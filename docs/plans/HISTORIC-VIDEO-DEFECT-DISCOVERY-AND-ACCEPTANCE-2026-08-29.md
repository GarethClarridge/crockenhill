# Historic Video Defect Discovery and Acceptance Plan

> **Status — 2026-10-18 (evening).** The **re-cut round** (365 members, snapshot `recut-round-20261016g`)
> processed between 10-16 and 10-18 06:28 BST: 285 runs cut and completed. On 10-18 the K16
> doubled-word fix (`b9ebcaf55`) and the operator's answers re-cut 941, 1025, 1066, 1236, 1148 and
> 1223, and the four parked runs (974, 1109, 1216, 1313) were retried and completed. Left at manual
> review: the four structure-review runs (1287, 1288, 1327, 1366). It is **not closed**: the
> held-sermon batch completed **55 of 61** on 10-18, and the other six were answered, fixed and
> cut the same afternoon (end of §4.0). 60 of the 61 are clear of holds. **E20 is implemented and
> 1322's dead-feed re-cut completed at 19:36 UTC on 10-18**; §4090 remains held pending listening
> and an operator decision. The new video has no measured dead picture.
> Clone at `e66d567d5`. Nothing is public. The §3 measurements
> below date from 10-16.
> The stale-clip clean-up (§4.1), song acceptance stage 2 and the release work (§4.5) follow the
> close.
> Getting production complete is mapped in the
> [programme plan §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16);
> weekly take-over (§4.2), lasting evaluation and custody (§4.6) and legacy transcripts (§4.7, decided 10-17) were
> added on 2026-10-16.

**How to read this plan.** It holds the current state and the remaining work, nothing else.
- **Rulings in force:** the [rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md). Check it
  before calling anything a gap.
- **How a round runs:** the [round runbook](HISTORIC-ROUND-RUNBOOK.md).
- **Design references, still current:**
  - [ensemble detection](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md);
  - [music and silence](MUSIC-AND-SILENCE-DETECTION-2026-09-25.md);
  - [song acceptance protocol](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md);
  - [talks](TALKS-AND-SHORT-TALK-DETECTION-2026-09-18.md).
- **Programme authority** (production rounds, release policy, IC8): the
  [incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md).
- **History, word for word, for evidence only:**
  - [10-03 to 10-16](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md):
    this plan as it stood on 10-16, and the session notes of 10-10 to 10-16;
  - with it, the [cut redesign](../archived-plans/CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md), the
    [boundary consistency work and full-pass record](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md)
    and the [follow-up round](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md);
  - earlier: [09-24 to 10-02](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md),
    [09-12 to 09-23](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md),
    [phases 0–8](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md).

**Original date:** 2026-08-29. **Redrafted:** 2026-10-16 (previously 10-02, 09-23, 09-12), keeping only
current state and open work. Everything removed is in the 10-03 to 10-16 log.

## 1. Outcome and boundaries

The definitive historic-video corpus has been processed through the weekly
livestream path. Completion is not acceptance. All historic sermon and song
outputs remain locally quarantined until the current defects are held or repaired,
the exact release membership passes current policy, and the operator separately
authorises a release batch.

The following constraints remain binding:

- Do not publish, release, delete or silently accept an item merely because its
  run completed or an earlier banked verdict called it release-eligible.
- Treat an effective review hold as safe containment; uncertain items need not all
  be repaired before the batch can be assessed.
- Keep candidate counts separate from audio/frame-confirmed defects. The
  populations overlap and are not a defect-rate denominator.
- Reassess generated songs inside each owning run's recorded historic staging
  context. Ambient-disk checks and stale banked verdicts are not acceptance proof.
- Preserve the better masters in the three deferred duplicate/date pairs. Do not
  substitute a different encode under transcript and section timings without the
  approved source-adoption path.
- Public release is a separate, exact-membership, human-authorised act. It is
  never a side effect of processing, promotion, cleanup or bundle generation.
- **Repair only through the standard video processing pipeline (operator rule,
  2026-09-14).** Do not correct outputs, verdicts or media by hand. Fix the
  pipeline, then re-run the affected runs through it, so every repair also
  protects future services. A hold through the tested hold path is containment,
  not repair, and remains allowed.

## 2. Completed work and archived evidence

| Stage | State | Evidence |
|---|---|---|
| Phases 0–6 | Complete | Inventory, metadata/projection corrections, song eligibility, custody and database-owned dispatch are in the [execution log](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#3-delivery-plan). |
| Phase 7 | Complete | Canary, remediation and idempotent replay are in the [Phase 7 record](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#phase-7--run-a-fresh-untouched-canary). |
| Phase 8 | Processing drained; acceptance open | Pass execution, remediation and the 2026-09-06/09 reviews are in the [Phase 8 record](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#phase-8--process-the-remainder-as-a-closed-pass-loop). |
| Defect discovery 09-12 to 09-23 | §4.0–§4.3a detailed | Canary, containment, censuses, rulings, transcript-loop and song work, class table, detector harness H1–H10b: the [2026-09-12 to 09-23 log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md). |
| Corpus re-run 09-24 to 10-02 | Route built; canaries 1–9; ensemble built and evaluated | Diff, rounds, tiers, concatenation gate, canaries 1–9, freezes 1–10, batch 1, the detection evaluation, the ensemble's evidence, §6 evaluation and canary 9: the [2026-09-24 to 10-02 log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md); the [detection reliability work package](../archived-plans/HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md). |
| Canary 10 to the full pass, 10-03 to 10-10 | Cut redesign, boundary findings F01–F11 and I1–I5 built or ruled, canaries 10–15, audio treatment | [Cut redesign](../archived-plans/CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md); [boundary consistency §2–§8](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md) |
| Full pass, 10-10 to 10-12 | Dispatched whole on freeze `441b14ed6`: 152 Tier A, 37 recompose, 244 Tier B. Listening found 19% of endings clipped | [Boundary consistency §6.3.9](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md#639-full-pass-execution-record-2026-10-10--the-one-current-instruction-set); [log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md) Part 2 |
| Fix round, 10-12/13 | Items 1–20: spoken-end tail, sentence ends, start joins (J1, J2), amended D1, rule M, widest sermons, media version 9 | Log Part 2 |
| Follow-up round, 10-13 to 10-15 | Frozen clone, 301 runs over a dozen snapshots, rulings applied, duplicate-recording adoption, custody follows the disk, export fixed (363 of 363 build an inventory), database wiped and recovered | [Follow-up round §11–§12](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md) |
| Re-cut round set-up and its review, 10-16 | Dead-feed edge rule, pre-flight, set up short of dispatch, read-only pre-dispatch review | Follow-up round §12 (end); §4.0 here |
| Phase 9 | Blocked | Acceptance (§4.3, §4.5) and identity disputes (§4.4) remain before final convergence and release. |

The archive retains the exact M1–M12 and P8-Q1–P8-Q16 narratives, commands,
measurements, hashes, repair sessions and superseded conclusions. Do not copy
those histories back here; link to the relevant anchor when they explain a new
decision.

## 3. Current measured state

**Measured 2026-10-16, read-only, local Sail database and quarantine.** Readiness ran per run inside a
rolled-back transaction (`storage/scratch/review-20261016/readiness.php`, output `readiness.json`).
Clip edges are from `clip-silence*.tsv` and `deadshare.sh` there, sermon edges from
`recut-listening-20261015/`. This machine is not production.

| Population | Count |
|---|---:|
| Historic runs | 455: 372 completed (6 superseded), 83 failed (5 superseded) |
| Completed, not superseded, by sermon-plan media version | v8: 135 · v9: 217 · none: 14 |
| Historic sermons | 443, all quarantined |
| Historic song videos | 953, all quarantined |
| Public historic sermons or song videos | 0 |

**Export readiness: 12 of 455.** Reasons (a run can have several):

| Reason | Runs |
|---|---:|
| Main sermon `thumbnail_file_path` missing: the **ruled deferral** (register P2), not a defect (§4.5) | 406 |
| A section's publication decision unsettled, mostly song acceptance stage 2 (10-15 counted 520 sections) | 249 |
| Run failed, not completed | 81 |
| Processing history holds active or failed work | 17 |
| Main sermon publication missing | 8 |
| Superseded | 7 |
| Excluded (no sermon in source 2, rehearsal duplicate 2, private occasion 2, rehearsal 1, silent source 1, duplicate recording 1) | 9 |
| Nested publication work not terminal (1127, 1352, 1220, 1223) | 4 |
| Scripture enrichment unsettled (973, 1022, 1171) | 3 |
| 937 never dispatched: it was **retired on 09-03** as a dead duplicate of 938 (register X1), but readiness does not read retirement | 1 |

**Failed runs, by cause** (78 not superseded):
- **62 parked on a sermon content hold**, each waiting for `sermons:re-extract … --held-section`
  (register Q8). The list is in §4.0.
- 6 waiting for their sermon sections and uncovered speech to be resolved: 973, 1287, 1288, 1327,
  1355, 1366.
- 3 with fewer than two valid draws: 954 (a talk, D22), 1073 and 1268 (split sermons, D21). Their
  fixes are not built.
- 1108 and 1223: an internal error at projection.
- 945: sections cover 64% of the recording.
- 1143: an accepted hold. 1145: excluded.
- 1314: re-detection left holds with no section of their type.
- 1220: cleanup refused while a candidate step row is `running`.

**Edges.**
- **Sermons.** The 10-15 scan (silence below −45 dB lasting ≥ 1 s) of 442 cut sermons found leading
  silence of ≥ 5 s in 16 and trailing silence of ≥ 5 s in 5. The scan understates digital-zero dead
  air: 1079 measured 3.8 s but holds about 14.5 s.
- **The dead-feed rule** (E10) moves 70 edges in its replay. 24 of them are in the re-cut round's
  membership; 46 (39 runs) are v9 runs outside it (§4.0).
- **Song clips (908).** 44 open on ≥ 3 s of silence and 17 on ≥ 5 s, 14 of the 17 cut on v9 code.
  25 of the 44 are mostly digital zero, below ruling 2's 15 s bar (register E14).
  - Two song sections are silent throughout: 938 §627 and 1106 §1878 (`SongVideo` 238).
- **Short talks (230).** 10 open on ≥ 3 s of silence, all but two of them mostly digital zero; the
  dead-feed rule covers them.

**Residue and attribution.**
- Stale song clips on non-song sections: 170 sections (120 runs) and 199 `SongVideo` bindings,
  up from 184 on 10-14 (§4.1).
- Preachers: 423 of 439 sermons on non-superseded runs are "Visiting Speaker" with
  `needs_preacher_review` (§4.5).

**Machine and backups.**
- The round's infrastructure:
  - the round containers are up from `../crockenhill-round` at `bef6b303a`;
  - main has no workers and runs on Redis 4/5 (its pre-round `.env` is in session `781bf913`'s
    scratchpad as `env.main-before-recut-round`);
  - the ffmpeg LaunchAgent is on the clone's script.
- Backups: a database dump and private storage from 2026-10-16 03:06 are in
  `/Volumes/Sonnics/_pre-recut-round-backup-20261016/` (SHA-256 in
  `storage/scratch/recut-preflight-20261015/backup-sha256.txt`).
- Free space: internal disk 29 GB, Staging 448 GB, Sonnics 299 GB.
- Commits after `7a69aac5d` (2026-10-14) are unpushed. A push to master deploys; a push to any other
  branch runs no workflow.

## 4. Remaining work in execution order

**Execution model.** The corpus re-run (rounds, §4.0 and the runbook) is the repair route: every eligible run goes through the
pipeline again, so every fix that changed detection, matching or cutting reaches it, and a repair
also protects future services. Outside it a run is repaired only as a named exception with its own
reason (1287 on 09-23, 1112 on 09-24). This plan revision authorises no processing membership,
dispatch, paid call or release; each needs the operator's go.

**Section numbers in the carried text** (§4.2 to §4.5): §3.1, §4.0a, §4.1–§4.1d, §4.3a, the §4.1b
coverage matrix, the H-numbered harness steps, and the bold group labels inside §4.3 ("4.1 Contain…",
"4.2 Close…", "4.3 Refresh…") all refer to the
[09-12 to 09-23 log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md),
not to this plan. The class-to-detector binding is data, in `resources/detector-classes.json`.

### 4.0 The re-cut round (set up 2026-10-16; processed 10-16 to 10-18; not closed)

**Purpose:** re-cut every run whose replayed plan differs from its cut on current code (register
E11, E12), so the edge fixes of 10-12 and the dead-feed rule (E10) reach the media.

**All known fixes before one round (operator, 10-16: "everything in one round, so all known fixes
first").** Built test-first on master after the `recut-round-20261016c` freeze, which they
supersede; a new freeze and snapshot follow.
- **Held re-cut decodes instead of refusing** (`6a4667608`): `sermons:re-extract --held-section`
  accepts a sound structure whose edge words are not yet decoded and lets `TranscribeOutputEdges`
  decode; `ExtractSermon` still checks the final plan. Clears the 54 held runs below.
- **D21 split sermons joined** (`77d880b44`): a draw's extra sermon parts become `other`
  continuations of the longest part. Replay on banked draws: 1073 and 1268 go from 0 to 4 valid
  draws, no disputes (free recompose, no re-detection). Members 1224 and 1335 move edges slightly
  (listening ≥ 1 s covers them). Non-member 1148 now has 4 disputes (two draws call 688–1726 s a
  talk, two a sermon part): a question for the operator before it is cut.
- **D22 talk over a notices item** (`affa64e11`): flagged, not failed. 954 goes to 4 valid draws;
  the talk-over-reading (1297) and sermon-over-prayer-slide (1164) conflicts still fail.
- **Candidate-prep nested row** (`b9b07f60b`): the chain no longer re-marks a standalone row
  running. 1220's row is still `running` from the old defect; settling it is an operator decision.
- **Order-of-service re-link** (`94f66bf0b`): the section panel can link a section to its item
  (1363: item 18035 to sermon §248827); a retype that contradicts the linked item asks again.
- **Readiness** (`c2b3cec35`): a retired run (937) reads "retired; not exported"; a thumbnail
  deferred by P2 is pending, not missing.
- **Stale-clip clean-up command** (`af6ff1d36`): `historic-import:clear-stale-song-clips`, dry run
  10-16: 199 sections in 130 runs, 55 clips re-bound, 144 retired, files kept. Run after the round
  closes (it writes to members).
- **No code needed:** 1108's promotion refusal was fixed earlier (held candidates always replace);
  1223's §2751 clip vanished from quarantine at 12:34 UTC on 10-10 for an unrecoverable reason and
  its re-cut regenerates it — both need only the cut route. The song-policy refresh is
  `service:backfill-song-boundary-evidence`. The extract route decodes edge evidence
  (`reExtract` resumes at `TranscribeOutputEdges`). The 36 song clips with no recorded cut,
  measured from their section starts: none moves.
- **Not changed, operator's:** 1314's benediction hold (§3994) has no prayer-typed section after
  re-detection; content holds follow content (ruling), so confirm or re-hold it. §988's
  spoken-material boundary check is a detector change; §988 is held.
- **Operator decisions, 10-16 (applied):**
  - **1220:** nested row 904 settled `completed` at 2026-10-12 19:25:55 UTC, when that run's
    candidates were cut and promoted. 1220 joins the round.
  - **1314:** §3994's hold released (`service:release-content-hold`, reason recorded): its
    song-boundary concern does not apply to the benediction re-detection types `other`.
  - **1148: "one sermon in parts".** Answers applied from
    `storage/scratch/ensemble-review-1148-20261016/` (q0 Version A, q1 no talk, q2 Version A,
    q3 the Revelation 5 reading, read by Andrew). My first mapping put q2 as "leave it out",
    which dropped 39:53–52:48; that ruling was removed (recorded under
    `service_structure_ensemble_ruling_corrections`) and q2 re-applied.
  - The answered whole still lost the sermon after the reading, which exposed two gaps, fixed in
    `15d5cbea6`: answers that keep a sermon on both sides of a hymn are joined at the composed
    level too, and an item answered inside a sermon keeps the sermon after it (the start rule
    decides where a sermon begins, not that it ends there). Replaying all 170 answered runs
    changes only 1148, now sermon 11:28 + parts 39:53–52:48 and 56:23–1:00:25 around the
    reading. 1148 joins the round.
- **Gates:** full parallel suite 9,859 tests green; PHPStan 0; Pint clean. Dusk: only
  `ChurchServiceEvidenceReviewTest` (`@sermon-composition-2`) fails, as on master since 10-14.

**Re-frozen again 2026-10-16, on all the fixes (supersedes both set-ups below; not dispatched):**
- **Clone** at `be35e9466` (previous line kept as `round-20261016c-line`); `CodeRevision`
  `870c2902…` equals main's; lockfiles unchanged; `bootstrap/cache` cleared.
- **Snapshot** `recut-round-20261016e/snapshot.json`: 200 runs, membership `122307f8…` — the 192
  of `…c` plus 1073, 1268, 954, 1108, 1223, 1220, 1314 and 1148. (`…d` has the same membership;
  it was retaken as `…e` after a `--dry-run` resolve on member 1220.)
- **Routes (dry runs beside it):** recompose 194 ready, including 1073, 1268, 954, 1108, 1223,
  1314 and 1148 (free, from banked draws). `rerun-redetect`: 1034, 1120 and 1375 ready.
  `reExtract`: 1126, 1378 and 1220 (`sermons:re-extract --dry-run` ready for 1220). Extract refuses
  all 200 before recompose, as expected. `extract-runs.txt`: 197.
- **Held sermons after the batch:** the 57 in `…c/held-runs.txt` (1322 after its recompose), now
  through `sermons:re-extract --held-section`, which decodes their edge words (A1).
- **Still to do at the go:** a fresh backup (runbook §6.5), round workers up, watcher on `…e`.

**Re-frozen 2026-10-16 (superseded):**
- **Clone** at `29126fb8a` (old line kept as branch `codex/round-20261016-before-v10`);
  `CodeRevision` `e5a35676…` equals main's. Round workers are stopped and will boot on it.
- **Snapshot** `recut-round-20261016c/snapshot.json`: 192 runs, membership `de52ba19…`. That is the
  old 144 less 1035, plus the 38 v9 runs, plus 1034, 1120 and 1375, plus the 8 song-start runs.
  `members.txt`, `extract-runs.txt` (190: all but 1126 and 1378), `held-runs.txt` and every
  dry run sit beside it.
- **Dry runs:** recompose 187 ready. 1034, 1120 and 1375 refuse ("banked ensemble input no longer
  matches") and `rerun-redetect` has all three ready; 1126 and 1378 refuse as before
  (`reExtract`). Extract refuses all 192 before recompose, as expected.
- **Held sermons (decision 2), 57 runs** (the 62 less the duplicate-pair runs and 1322, a member
  whose dry run would write its composition): `--held-section --dry-run` is ready for only 1019,
  1330 and 1364. **54 resolve no segments: `edge_word_timings_missing`.** The v10 bump makes
  their banked edge evidence stale, and the command plans before it decodes (as 1126 and 1378).
  Their route is `authoriseHeldSermonSpan(§)` then `ProcessingRunOrchestrator::reExtract`,
  which decodes at `TranscribeOutputEdges`. 1322 is cut the same way after its recompose.
- **Preflight (read only):** queues empty on DB 0; Staging on USB at 5 Gb/s, 448 GiB free;
  Sonnics 299 GiB; internal 32 GiB; speaker identification off; host ffmpeg on the clone's
  script (unchanged since `bef6b303a`); the seven Q12 sections are held. Still to do at the go:
  a fresh backup (runbook §6.5), workers up, watcher.

**As first set up:**
- **Snapshot** `recut-round-20261016`: 144 runs at `bef6b303a`, the 155 runs planned before the
  10-12 fixes less:
  - 7 excluded;
  - 3 superseded runs whose media no service uses (943, 1249, 1377);
  - 930, which is parked.
- **Dry runs:** recompose 141 ready; 1035 goes to `rerun-redetect`; 1126 and 1378 go to
  `ProcessingRunOrchestrator::reExtract`. `extract-runs.txt` (142) is written.
- **Not yet started:** the watcher and autotick.
- Membership: `storage/scratch/recut-preflight-20261015/members.txt`. Pre-flight:
  `preflight.json` beside it.

**Decisions before dispatch** (pre-dispatch review, 2026-10-16; evidence in the archived
[follow-up round §12](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md), "Pre-dispatch review"):

1. **Add the 39 v9 runs the dead-feed rule moves — approved 10-16 (register E19).** The rule did not bump the media version, so the
   version-based membership missed them. They include 1096 (sermon 1023, the case the rule was built
   for), 1352 (sermon end −39.3 s), 1308 (+35.3 s), and the talks 1020 §1344, 988 §1149 and
   1326 §4135. Route: recompose and cut; 974 through `reExtract` (register X7).
   - The runs: 928 941 951 956 974 976 977 983 988 993 994 997 1002 1004 1015 1017 1018 1020 1023
     1060 1062 1063 1066 1077 1096 1102 1106 1131 1136 1210 1229 1269 1308 1326 1328 1334 1339
     1342 1352.
2. **Cut the 62 held sermons with `--held-section` — approved 10-16** (register Q8), after a `--dry-run` per run. Holds remain until the operator confirms each defect is gone. A run
   stopped before `TranscribeOutputEdges` goes through `reExtract`. Four are the duplicate pairs' runs
   (1034, 1120 and 1375 kept, 1374 excluded) and follow decision 3 instead.
   - The runs: 929 946 969 980 1003 1008 1019 1030 1031 1032 1034 1039 1047 1052 1057 1059 1064
     1065 1068 1069 1075 1084 1090 1095 1100 1107 1120 1121 1168 1176 1177 1187 1197 1208 1209
     1215 1230 1248 1254 1258 1262 1276 1278 1297 1300 1309 1310 1317 1320 1322 1330 1332 1336
     1347 1359 1364 1373 1374 1375 1380 1381 1382.
3. **Apply the duplicate-pair corrections first** (§4.4, register X4). Drop 1035 (pair B's poorer
   run, a member on the paid re-detection route). Take kept runs 1034, 1120 and 1375 through
   re-detection, then `--held-section`. 1374 is excluded.
4. **Song dead air — decided 10-16 (register E18):** song starts remove a leading prefix of ≥ 2 s
   of verified digital zero and keep 0.3 s before the first finite sample. Song ends, fades and
   the 15 s dropout/whole-song policy remain unchanged.
   - **Evidence before adoption, 10-16:** a bounded read-only replay selected 63 IDs from the
     saved ≥3 s leading/trailing-silence scan; 62 current eligible song plans matched. The spoken
     algorithm moves 49 edges, but 34 would remove finite-level audio. Only 15 moves remove solely
     digital zero (all starts; 70.633 s total). The direct transfer also violates the existing
     1028 §1392 ending fixture (553.1 s against its required minimum 555.01 s). A test-only variant
     that accepts only digital-zero removals and preserves fades passes the song fixtures; the
     combined focused checks pass 111 tests, with PHPStan at zero errors. Evidence:
     `storage/scratch/song-dead-feed-evidence-20261016.json` (raw replay and SHA-256 alongside it).
     This ruled out copying the spoken ≤ −80 dB rule and supported the separate start-only rule
     subsequently adopted. This sample supplies no zero-only end-move evidence.
   - **Listening set prepared, 10-16:** `storage/scratch/song-start-listening-20261016/index.html`
     contains all 15 starts, with current/proposed previews from each saved processed clip and
     wider source context (45 lossless audio excerpts). Recorded starts match the replay;
     excerpt durations and references are checked. Five review-control tests pass. Answers stay
     in the browser and can be exported as JSON bound to the evidence hash.
     The blank file-preview delivery was fixed by embedding the cards and script in the HTML;
     a restricted loopback preview was verified in the browser with all 15 cards and 45 audio
     players loaded, with no audio errors. No review choices were selected during verification.
   - **Operator listening acceptance, 10-16:** "All of these are fine" accepts all 15 proposed
     starts in the reviewed set: run/section 963/860, 967/1073, 974/997, 1007/1251, 1009/1270,
     1009/1277, 1036/1477, 1062/1662, 1066/212330, 1102/1863, 1102/1866, 1315/4002, 1323/4099,
     1338/4762 and 1345/4356. Exact coordinates and excerpt hashes are recorded in
     `storage/scratch/song-start-listening-20261016/operator-verdicts-20261016.json`, bound to
     evidence SHA-256 `1ac1c12c1e7ff3e96ccc6fc8f05e566d6e63107accdc7fac63c6ae86d2432aa6`.
     All 15 are accepted; none is deferred. This settles these starts, not song ends, finite quiet
     audio, a general cutting-rule change, unrelated holds or release. No cuts have been applied.
   - **Implementation authorised, 10-16:** after accepting the 15 starts, the operator approved
     implementing the 2 s digital-zero-only start rule. The shared cut planner now stops before
     the first finite sample, with no loudness or minimum-live-duration threshold. On the accepted
     recordings this keeps up to 0.107 s extra zero lead compared with the previews. Durable RMS
     fixtures cover all 15; synthetic cases cover the exact threshold, finite quiet audio, brief
     sounds, wholly silent songs and missing evidence. A partial RMS prefix or a sample gap over
     0.1 s refuses the trim; unknown audio cannot be treated as digital zero. Answered edges still
     apply after the rule.
     Media-processing version is **10**, so earlier candidate stamps cannot be reused. The frozen
     round clone and its snapshot have not been changed; the next freeze must remeasure affected
     membership and bank current edge evidence before dispatch. No re-cut, hold clearance or
     release was performed by this implementation.
     **Validation:** all 15 accepted RMS fixtures and the focused song regressions pass; Pint
     passes and PHPStan reports zero errors. The full parallel suite completes with no failures:
     9,829 tests, 96,908 assertions, 163 PHPUnit notices and one skipped opt-in corpus replay.
   - **Membership remeasured at `29126fb8a`, 10-16 (read only):** the rule applied to the recorded
     cut start of every extracted song clip on active, non-excluded historic runs. 1,075 clips
     assessed; 26 starts move (128.4 s in all), in 21 runs; 36 clips have no recorded cut and could
     not be assessed. All 15 accepted starts are among them. 11 further starts move that nobody
     has heard (1.8–5.2 s, except 993 §1181 at 19.7 s): 976 §961/§964/§163426, 993 §1181,
     1034 §1455/§1462, 1088 §1787, 1109 §1908, 1322 §4085, 1326 §4134 and 1334 §4241. They join
     the listening in checklist step 7.
     - **Runs to add to the snapshot**, outside the 144, the v9 runs and the duplicate pairs:
       963, 1009, 1109, 1315, 1322, 1323, 1338 and 1345. 1322 is also a held sermon (decision 2).
     - Report: `storage/scratch/recut-freeze-20261016/song-start-membership.json`
       (SHA-256 `e6132347dcdd6546bf6055870d099433bb4d16084b0ffe9760b5dccc6a52dd92`), shard
       outputs and the script beside it.
5. **Hold and review the known-bad endings — decided 10-16 (register Q12):**
   - members 1224 (the sermon end runs 22 s into the hymn while the preacher reads it) and 1111
     (ends mid-sentence);
   - non-members 1198, 962, 1005, 1240 and 1271.

   Keep or apply a content-defect hold and review each ending against the source. A re-cut alone
   does not clear the hold; the operator must confirm the ending defect is resolved. This decision
   is recorded. **Applied and verified locally 10-16 at 10:39 UTC:** sections 846 (962),
   1242 (1005), 1935 (1111), 165128 (1198), 146083 (1224), 175519 (1240) and 3397 (1271)
   now carry live decision-owned `content_defect_hold` records and `needs_manual_review`.
   Source listening and operator confirmation remain pending; no hold was cleared.
6. **Unreviewed structure changes on recompose:** 1036 drops "Unidentified singing" §1470 (no media);
   1255 drops "Opening Bible reading" §3178, which carries a stale song clip. Look before the round,
   or listen after it.
   **Read-only inspection, 10-16:** §1470 spans 29.98–90 s, has no extracted video, and its
   stored text is only “Amen. Thank you.”; this does not settle whether singing occurred.
   §3178 spans 141–179.127 s and its stored transcript contains a Revelation 1 reading, while
   its video path is the stale song clip `sermons/songs/104/3178.mp4`. Its proposed removal is
   not accepted merely because that clip is stale. Both remain explicit source-listening
   entries in `storage/scratch/recut-preflight-next-20261016/review-entries.json`, alongside the
   seven ending reviews. Their section data has not been changed.
7. **Fix the stale-clip cause before the round — approved 10-16 (register R9).** Complete
   [follow-up round §9.1](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md#91-clean-up-pass-stale-song-clips-on-sections-that-are-not-songs-found-14-10)
   step 1 before freezing or dispatching, so recomposition stops adding to the stale-clip residue
   (§4.1). Implemented in the local working tree on 10-16: section sync clears stale song paths on
   non-song rows and detaches/quarantines obsolete `SongVideo` bindings when content changes or
   sections are removed, keeping the files. The run-1002 insertion regression and moved-span
   regression failed before the fix; focused coverage also proves retyping, removal, rollback and
   preservation of unchanged bindings. All 33 section-sync tests pass; Pint passes and PHPStan is
   at zero errors. The full parallel suite passes: 9,835 tests, 96,939 assertions, 163 PHPUnit
   notices and one skipped opt-in corpus replay. The frozen clone is unchanged; include
   this fix in the next freeze. Existing residue clean-up remains in §4.1.
   **Status checked 10-17:** the cause fix and regression tests are committed in `29126fb8a`.
   The isolated section-sync test file passes again: **40 tests, 177 assertions**. This check
   does not establish which revision the running clone uses; no clone, worker or processing
   data was changed. Do not alter the current round to deploy it.
8. **Keep thumbnail deferral — reaffirmed 10-16 (register P2).** Generate thumbnails only after
   the release membership's editorial QA passes (§4.5).

**Readiness correction, 10-16 (final review fixes):** the 200-run snapshot below is superseded
for membership planning, but has not been replaced or dispatched. Read-only inventory using the
production `SermonSongIntroductionCheck::songStart()` rule found 280 song-adjacent sermon runs,
277 eligible historic runs. Of those, **166 are outside the old 200**, making the combined
proposed membership **366**. Exact previous, additional and proposed IDs, plus the seven Q12
edge-answer inventories, are in
`storage/scratch/weekly-edge-tool-20261016/k16-freeze-inventory.json`; its read-only inventory
script is beside it. The seven Q12 owners still have **no active edge answers and no history**.
Their post-reprocessing source confirmations remain outstanding; no answer or hold was changed.
Missing manual timestamps do not block reprocessing: Q12 requires confirmation that the known
defect is resolved before its hold is cleared, not an operator-selected timestamp for every run.

The working-tree fixes propagate K16 provider failures for queue retry, explicitly request the
configured tier through the Flex fallback and log the actual tier. Completed content uncertainty
still banks a null end; legacy null artifacts without proof that calls completed are asked again
once. Section sync ignores history-only records when no same-type section remains, retains history
when it can follow the content, and refuses a merge of two actively answered sections before any
write, identifying both owners. Re-freeze on the tested code, take a new snapshot using the
remeasured membership, and dry-run every route before dispatch. The clone and existing snapshot
remain untouched.

Validation: 72 focused tests pass; the full parallel suite completes with 9,909 tests,
97,187 assertions, zero failures/errors, 163 PHPUnit notices and one skipped test.
PHPStan reports zero errors and Pint passes. The full run also exposed an obsolete outage fixture
in `SpokenEndBesideSongTest` (now a completed uncertain response) and an ID-shift setup error in
`HistoricProcessingResultBundleRoundTripTest` (now shifts above the captured source IDs after
deletion); both were corrected and passed in the fresh full run. Logs:
`storage/scratch/weekly-edge-tool-20261016/freeze-review-{focused,full-suite,phpstan}.log`.

**Go checklist** (reconciled 10-16 with the `recut-round-20261016e` freeze; on the operator's word):
1. **Done 10-16:** `phpunit.xml` forces `DB_DATABASE=testing` (`29126fb8a`); duplicate-pair
   corrections applied (§4.4); Q12 holds applied and verified; all known fixes built, tested and
   pushed; clone at `be35e9466`; snapshot `recut-round-20261016e` (200 runs) with every route
   dry-run (above).
2. **Remaining dispatch preparation and post-processing review:**
   - **1036 and 1255 settled 10-16** (listening page https://claude.ai/artifact/1nnya6qTouPWLaEEEE2k1C,
     answers in `storage/scratch/structure-review-1036-1255-20261016/`): §1470 is only
     pre-service music or talk, and §3178 is not a reading. Both runs recompose as planned and
     the sections go; routes unchanged;
   - **The seven Q12 endings — automatic reprocessing first; source confirmation before hold
     release.** Runs 1224, 1111, 1198, 962, 1005, 1240 and 1271 go through the automatic pipeline,
     including K16 where applicable, word timing and sentence checks. Missing manual timestamps
     do not block dispatch or reprocessing. Keep the Q12 content-defect holds until the operator
     reviews the resulting endings against the source and confirms each defect is resolved.
     Manual edge answers are a fallback for cuts the automatic pipeline cannot resolve correctly.
     The service screen's section panel has "Where this sermon starts and ends" for every sermon
     and short talk (a weekly tool; no historic-only behaviour): it plays the archived service audio
     around each output edge in service time at 1×/2×/3× (default 3×) with the transcript lines,
     records "start here" / "end here" as a recording time, and checks it against a read-only plan.
     - **Rule:** the answer is the *output's* edge (a sermon composition's first start or last
       end; a talk with its paired reading), kept on the owning section
       (`output_edge_answers`, with who, when, the bound it replaced, the section span and the
       output's parts; earlier answers in `history`). `CueSafeExtractionPlan` applies it last,
       after the edge rules, dead feed (E10/E18 order) and unresolved intervals, so no
       word-timing move overrides it. It is refused, the planner's edge kept and the plan held
       (`sermon_operator_edge_refused`), when it leaves a part out, falls outside the recording,
       or moves outward past the planner's edge into a section the output does not cut (the
       panel shows the reason). Inside content the plan already reaches (1224's hymn) is fine.
       `SetOutputEdge` also refuses, before any decode, what section bounds alone rule out and
       a reach into a held section. Setting an edge confirms nothing and releases no hold.
       Re-detection carries the answer to the overlapping section of its type, or refuses the
       sync (`UnplacedOutputEdgeAnswerException`, a content-hold refusal, so the run parks).
       A sermon's re-cut stays explicit; a talk's clip returns to candidate preparation, as the
       existing talk recut does.
     - **Measured:** all 772 banked plans (466 sermon runs, 306 short talks) replayed read only
       on master and on the change, on current (v10) evidence and on the v9 bank as a proxy
       (540 with segments): byte-identical, as no answers exist yet. Evidence and the script:
       `storage/scratch/weekly-edge-tool-20261016/`.
     - **After automatic reprocessing:** the operator listens to each resulting ending against
       the archived source and confirms the defect is resolved before clearing its Q12 hold.
       Only where the automatic cut remains wrong or unresolved does the operator set an edge
       on the service screen, verify its placement with a read-only replay and re-cut as needed.
       At v10 the edge words are not decoded until the round decodes them; "not decoded yet"
       is not a requirement to supply a manual timestamp before dispatch.
     - **This is new code: re-freeze before the go** — move the clone to the new master
       commit, take a fresh snapshot and dry-run every route (runbook §2.1, §3).
   - **Sermon ends beside a song — K16 built 10-16 (`de9a1f0e1` and the follow-up).** Ruled 10-16
     (register K16, superseding K7/K8): the sermon ends at the join to the song, never after the
     music starts, and keeps the preacher's introduction only when the preacher says how the song
     relates to the sermon. `SermonSongIntroductionCheck` asks the structure model on the edge step,
     *before* the edge words are decoded (twice; a third time on a disagreement in judgement or
     line), and banks the answer; `CueSafeExtractionPlan` ends the sermon at the answer's line and
     reaches across an unpublished introduction section (1111). Pattern A's spoken stretch is
     deleted. Lines in the first 5 s music window may finish a sentence; later lines never count.
     - **Measured read only** (`storage/scratch/weekly-edge-tool-20261016/`): 280 sermons
       followed by a song on the first prompt, then the production prompt (v2) on 19. v2 ends
       1040, 1311, 932, 1224 and 1304 at "Amen." (verse read, no stated link); keeps the stated
       links of 1313, 1111, 1164, 1005, 1185, 1312 and 1160 (through "…full of grace and truth.",
       not the screen logistics after it); ends the housekeeping runs (951, 1236, …) at the
       sermon. To hear: 948 keeps "I've chosen this one because it seems to fit in so well" and
       the tune logistics after it, but not the verse read after "So let's stand and sing".
     - **Ruled endings (§4.5):** the seven sermon fixtures beside a song now carry the recorded
       answers (and, for 1160, 1311 and 932, words decoded read only at the new end); all 36 keep
       their rulings. 1160 and 1311 move later (to "…grace and truth" and the second "Amen.").
       Re-capture copies banked K16 answers and warns where a live run has none; keep the seven
       fixtures' recorded blocks until their runs are asked (the round), and update the script's
       `media_processing_version` pin (8) to the round's before any re-capture after it.
     - **Re-processing:** every route that cuts a sermon resumes at `TranscribeOutputEdges`, which
       asks (Tier C `rerun-extract` and `sermons:re-extract`, both via `reExtract`). A run never
       asked plans as `edge_word_timings_missing`, like undecoded words, so `sermons:re-extract`
       goes on to the edge step instead of refusing; an answer that names no line holds the run
       for the operator. Calls: 2–3 per sermon beside a song (about 600 for the round): the
       preflight must confirm the clone's workers reach the structure model. Re-freeze before the go.
   - a fresh backup to Sonnics (runbook §6.5) and the live preflight (runbook §6);
   - round workers up from the clone (runbook §3), restarted after the clone moved.
3. Watcher on `recut-round-20261016e` (runbook §5.1). Then:
   - stage (a): one recompose followed through every job;
   - `rerun-recompose --all --max=500 --execute` (194 ready);
   - `rerun-redetect` for 1034, 1120 and 1375, then their extraction;
   - autotick on `extract-runs.txt` (197);
   - 1126, 1378 and 1220 through `ProcessingRunOrchestrator::reExtract` (974 is not a member;
     it is cut the same way, as register X7 says).
4. Held runs after the main batch: **a canary, then the rest in parallel** (see "Held sermons:
   the procedure", end of §4.0). Each is cut with `sermons:re-extract <processing_id>
   --held-section=<§> --yes`. The command decodes their edge words itself (A1); holds stay.
5. Listening: every edge moved ≥ 1 s, the backlog in §4.1, and the held-sermon release page.
   Then close (runbook §8) and run `historic-import:clear-stale-song-clips --execute`.

**Time:** about 3.9 min a run (10-15): about 13 h for the 200. Held sermons take about 6 min
each. In parallel on three ffmpeg workers the 61 take about 2 h; the single whisper worker that
decodes edge words is the narrowest point.

**Preflight operation, 10-16:** no processing dispatched. No active processing step or real
queue payload was present (Redis notification tokens are not jobs). Before the identity corrections
and Q12 holds, a fresh consistent database backup was taken:
`storage/scratch/recut-preflight-next-20261016/before-identity-and-holds.sql.gz`
(SHA-256 `60e64c23c720e2512545ab4488243ce54aaa856edc2ba12ae067821646a53040`).
The earlier Sonnics backup remains. Verification and review entries are beside the fresh dump;
`preserved-assets.json` verifies all 10 relocated assets against the command's inventory hashes.
The correction/hold command tests passed: 20 tests, 121 assertions. After these operations,
440 historic sermon rows remain and no historic sermon or song video is public. The §3 counts
above are the earlier pre-correction measurement. The frozen clone and snapshot remain unchanged.

**Execution log:** record the round here as it runs. Move the record to a dated archive log when it
closes.

**Staged start, 10-16, `547bbb52d`:** the clone moved from `be35e9466` (preserved as
`round-20261016e-line`) to `547bbb52d`; hashed code matches main. Fresh snapshot
`recut-round-20261016f` freezes 366 members (membership SHA-256
`d200b05424f274f4daa0b4d5cd1bf257eda98b031a92f29c26ab79190ba33a07`). Route evidence
and the execution record live beside it. Explicit routes: 357 recompositions; four re-detections
(1034, 1120, 1262, 1375); five direct extractions (974, 1126, 1220, 1363, 1378), preserving manual
structure edits. The 300 ordinary extraction members exclude the 61 live held-sermon members;
60 held-section dry-runs passed, while 1197 refuses on `sermon_section_content_held` and needs
rechecking after recomposition. No hold was released. Initial diff: zero of 366 changed.

Fresh consistent database/private-storage backup and binlogs are on Sonnics at
`/Volumes/Sonnics/_pre-recut-round-20261016f-backup-20261016/`; gzip integrity passed and
`SHA256SUMS` is beside the backup. The dump records binlog `000121:70024469`; the copied active
binlog covers through `79576687`. Live preflight passed: queues empty, no active steps, isolated
Redis, speaker identification off, sufficient disk space, USB 3 links, host FFmpeg/Whisper/classifier
reachable, both structure models accessible on Flex. Clone workers restarted and watcher enabled.

**Only canary 1040 dispatched.** Recomposition and extraction completed, including two K16 calls,
edge transcription and sentence checks; paired reading and sermon retained. `ExtractSermon` took
321 seconds. Mid-cut pause/resume proved all ten queues paused, no reserved work after the cut,
and the next job waiting. Quality verdict approved; thumbnail stage intentionally skipped public
thumbnail for quarantine; candidates prepared and assets promoted to private quarantine.

**Bulk dispatch stopped by the canary gate.** The final watcher paused all ten queues on
`run 1040: §1511 bible_reading 1662.4–1780.1 → §1511 bible_reading 1662.4–1780.1 lost its extracted media`.
This is the intended stale-song detach from the 10-16 sync fix, but the watcher's
`staleSongClipKept()` only sees videos still attached to sections. SongVideo 198 remains detached,
quarantined and present at `sermons/songs/878/1511.mp4` (372,467,769 bytes; SHA-256
`cab27d57293484109bfcd2476a43e10b15e41541e936f363712d43e2db00de8a`). Evidence:
`stale-song-198-custody.json`, `canary-diff.json`, `watch-stop.json`. No new failed job, publication
or lost hold was reported. The baseline has 186 similar non-song stale-media sections on 123 runs,
so this reporting mismatch needs resolving before unattended bulk dispatch. No stop was
acknowledged, no autotick enabled, no further member dispatched; queues remain paused.

**Watcher correction, 10-16 (main checkout; not installed in the frozen clone):** read-side
capture now records detached song-video custody for the same service, including a nonempty file
check on the recorded disk (with staging batch-root resolution). The diff accepts the stale-clip
exception only for matching song IDs that remain quarantined and present; missing/deleted files,
unavailable disks, changed service/song bindings and public copies still require attention.
Removed non-song sections get the same custody check, and actual removed-media loss now triggers
the watcher stop as well. Existing v2 snapshots remain readable without the optional new field.

Regression tests reproduced the false stop before the fix. Validation: 69 focused tests,
362 assertions; full parallel suite 9,925 tests, 97,295 assertions, zero failures/errors,
163 existing PHPUnit notices and one skipped test. PHPStan: zero errors; Pint: passed. Logs:
`storage/scratch/watcher-detached-{red,focused,full-suite,phpstan,pint}.log`. Read-only replay
against `recut-round-20261016f/snapshot.json` reports zero attention items; evidence is
`recut-round-20261016f/canary-watcher-fix-diff.json`. The original snapshot and stop file remain;
no stop was acknowledged or cleared, the clone has not moved, and all ten queues stay paused.
**No batch expansion or additional dispatch without the operator's explicit approval.**

**The round, 10-16 to 10-18, on `8440211fc` (snapshot `recut-round-20261016g`).** The record is
`storage/app/private/recut-round-20261016g/execution.json`, and the watcher's state and stop files
are beside it.
- **Freeze:** the clone was re-frozen on the watcher fix, at code revision `8fd2aae3…`. 365 members
  (the 366 less canary 1040), membership `f1adeee0…`. A fresh backup is in
  `/Volumes/Sonnics/_pre-recut-round-20261016g-backup-20261016`. The operator authorised the
  whole remainder with no staging batch.
- **Dispatch:** 356 recompositions, four re-detections (1034, 1120, 1262, 1375) and five direct
  `reExtract`s (974, 1126, 1220, 1363, 1378); autotick ran the ordinary extractions. A zsh
  argument-splitting slip first accepted only 928 and 1034. The rest followed explicitly, with
  no duplicates.
- **Recoveries:**
  - **10-17 morning:** 1378's archived service audio was restored byte-identical. Seven members
    (1002, 1012, 1032, 1059, 1106, 1229, 1379) regained authority through single-stepped
    queued jobs.
  - **10-17 18:46:** 1313 parked (below).
  - **10-17 23:28:** a stale Docker gRPC-FUSE view showed the directory
    `section-publications/1845-f8501cd931d180a0` as a 2,795-byte regular file. Restarting all
    nine round containers at 00:46 cleared it, and the directory reads correctly now. 1109 parked.
  - **10-18 05:13:** 1216 parked.
- **Settled 10-18 06:28 BST:** queues empty, three failed jobs since the start, all of them
  acknowledged. The watcher's count:

| State | Runs |
|---|---:|
| Cut and completed | 285 |
| Detected only: held sermons awaiting `--held-section` (checklist step 4) | 62 |
| Held or failed: the 10 below at manual review, and 1109, 1216 and 1313 | 13 |
| Parked: 974, still `processing` | 1 |
| Not started | 4 |

The four parked payloads (974, 1109, 1216, 1313) sit under
`historic-round:recut-round-20261016g:parked:<run>`.

**Triage, 10-18 (read only).** Plans were replayed in `round-tools` on the frozen code inside
rolled-back transactions, with stray HTTP refused. Ten members failed at `manual_review_required`.
- **Four are known structure-review runs:** 1287, 1327 and 1366 (`sermon_reading_membership_unresolved`)
  and 1288. Not new.
- **Four were completed before the round and now fail on a K16 end: 941, 1025, 1066 and 1236.**
  - **Cause:** `sermon_edge_unresolved`, in every case the K16 end (`moved_beside_song_from`)
    anchored on a line whose word occurs twice close together, so the anchor is
    `ambiguous_cue_anchor` (`c6aebe9a0`, F03). Composition and structure need no review.
  - **The anchors:**

    | Run | K16 line | Occurrences in the words | Cut placed after the second | Stored end it replaces |
    |---|---|---|---:|---:|
    | 941 | "Amen." | 4177.94 and 4179.32 s | 4180.04 s | 4205 s, 25 s into the song |
    | 1066 | "Amen." | 2288.94 and 2289.80 s | 2290.40 s | 2360 s, 70 s later |
    | 1236 | "Amen." | 3636.15 and 3637.50 s | 3638.28 s | — |
    | 1025 | "…hour." | 3979.56 and 3980.26 s | 3980.92 s | — |

    1025 is a stated link (`ties_to_sermon`), so it is kept.
  - **Containment:** none of the four had its sermon re-cut, and its old quarantined cut stands.
    No register ruling covers ambiguous anchors under K16. **Open for the operator:** answer the
    four on the edge panel, or change the mechanism. The second option means taking the last
    occurrence when every candidate lies inside the K16 line's own cue; it needs a failing test
    first and a count over the cut members.
- **1148** (answered "one sermon in parts" on 10-16): the plan now has the answered parts
  (688.0–1723.8, 2392.5–3172.7, 3382.6–3625.0 s), but it fails on two things:
  - `spoken_end_thought_unresolved` at the final end, 3625 s, inside a 30 s cue "Thank you."
    (3611.7–3641.7);
  - uncovered speech "Okay, right." at 1725.0–1725.8 s.

  Both are listening questions for the edge panel.
- **1223:** no longer a projection error. It now asks for its sermon reading
  (`sermon_reading_membership_unresolved`), an operator answer.
- **Parked:**
  - **1109** (`PromoteHistoricAssets`) and **1313** (`ExtractSermon`: "source or output cannot be
    probed", 18:12 on 10-17) both failed on batch root `9351fa4e…` before the 00:46 container
    restart. 1109's failure is the stale FUSE view. 1313's fits the same class but is unproven,
    because its temporary output no longer exists.
  - The watcher found that 1313's three song clips and §3976's short-talk media lost their links.
    The songs are verified in quarantine; **§3976's short-talk custody is still unresolved.**
  - Both runs look retryable from their parked payloads once that custody is settled.
- **974 and 1216 fail deterministically in `PrepareSectionPublicationCandidates`.** Three
  identical attempts each, 1216's after the restart, with "Timing check: video start or duration
  differs from selected sections" (`SourceAwareMediaTimingChecker`). The log does not name the
  section, and the failed outputs are gone.
  - **974:** §997's start moves 3.49 s under E18 (`song_start_digital_silence`).
  - **1216:** no E18 move; the next section after the last extracted one is probably §2655,
    whose plan moves −1.56 s and −4.16 s.

  So E18 alone does not explain both. Reproduce with a failing test before any retry.

**Settled 10-18 (operator's answers; not yet re-cut).**
- **The K16 anchors: mechanism fixed on master (operator: "Build it"), uncommitted at the time of
  writing.**
  - **The rule:** in `CueSafeExtractionPlan::cueBoundaryPause`, an **end** on a cue that closes a
    sentence is placed after the latest occurrence and no longer marked `candidate_anchors`. That
    is 1105's ruled case, a closing sentence heard twice, now also for a single-cue window. Starts,
    and ends on lines that don't close a sentence, stay unresolved, as F03 has them.
  - **Tests:** 941's timings failed first and pass now. All 28 planner test files pass.
  - **The count** (frozen code, all 365 members, `storage/scratch/k16-ambiguous-anchor-20261018/`):
    the only ambiguous sermon edges are the four K16 ends (941, 1025, 1066, 1236), with no errors.
    Talk and reading edges in held runs are not yet decoded at v10, so they can't be counted; the
    rule changes whether such an edge is asked, never where it is cut.
  - **Replay on the change:** the four plan without review, at the same cut points.
  - **Full suite:** 9,940 tests, 3 failures, all of which fail on master without the change:
    `PrepareSectionPublicationCandidatesTest::a_published_song_recut_under_a_new_recording_override_publishes_the_new_sound`
    and both `ServiceOccasionConfirmationTest` public-page tests (a 302, probably `6cea189c8`'s
    access restriction). Open.
- **1148 and 1223:** answered on the listening page
  https://claude.ai/artifact/TsV7ry9isjjGM4tyoUjW7B (`rulings` collection, saved in
  `storage/scratch/review-1148-1223-20261018/answers/`). They were applied through the screen's own
  actions as user 1, with before-state snapshots beside them.
  - **1148:**
    - "Okay, right." at 28:45 is the hand-over to the prayer, so the composition is
      `reviewComposition` 2201, 2205, 2207.
    - The sermon ends after "…Amen. Amen." at 58:59 (`SetOutputEdge` end 3540.2 s, in the 1.4 s
      pause after the second Amen); the hymn announcement is not the sermon's (K16).
    - §2201 confirmed (`ConfirmServiceSection`), on the operator's word.
  - **1223:** the sermon preaches John 8:12–20 ("chapter 8 and verse 12", "verse 20 of chapter 8",
    "Verse 12 again"), so the composition is 2753, 2755 (the reading, then the sermon; hymn 737
    left out). §2755 confirmed on the operator's word.
  - Both now plan without review: 1148 at 688.0–1723.8, 2392.5–3172.7 and 3382.6–3540.2 s; 1223
    at 1287.2–1399.1 and 1558.6–3172.2 s.
- **Found on the way:** `structure_sermon_boundary_material_risk` is added by
  `ExtractSermon::recordSermonBoundaryEvidence` and, by design
  (`composition_does_not_clear_an_upstream_material_boundary_review`), only Confirm clears it.
  An answered composition therefore still needs Confirm on the sermon section.

- **Talk and reading edges** (the count's second phase, all 365 members): one more edge changes,
  1233 short talk §2878 "Still the early days.". On the fix it ends at 1643.39 s, between its own
  "…days." (1643.20) and the next speaker's "Okay," (1643.58). It has never been cut, so nothing
  changes until 1233's clips are next prepared. 165 edges in 58 runs are not yet decoded at v10
  (`edge_word_timings_missing`).

**The six re-cut, 10-18 (snapshot `recut-round-20261018a`; operator: "Go", "Dispatch").**
- **Clone:** moved to `6072ee52a` (old line kept as `round-20261016g-line`). Lockfiles unchanged;
  `bootstrap/cache` cleared; `CodeRevision` `8127d428…` equals main's. Workers restarted; the
  `…g` watcher and autotick stopped.
- **Backup:** `/Volumes/Sonnics/_pre-recut-round-20261018a-backup-20261018/` (database dump at binlog
  `000127:572975306`, binlogs, private storage; gzip integrity passed; `SHA256SUMS`).
- **Route:** `ProcessingRunOrchestrator::reExtract`, because Tier C refuses runs with no detection
  round on this code, and recompose would re-sync sections over the admin answers.
  - Preflight (`direct-preflight.jsonl`): no projection refusal, job offset 9, every sermon plan clean.
  - Dispatched together at 09:16 BST; all six completed by 09:35 with no failed job and no watcher
    stop.
- **Diff:** 6 of 6 changed, 0 need attention. Each executed plan equals its replayed plan:
  - 941 ends at 4180.04 s;
  - 1025 at 3980.92 s;
  - 1066 at 2290.40 s;
  - 1236 at 3638.28 s;
  - 1148: 688.0–1723.8, 2392.5–3172.7 and 3382.6–3540.2 s;
  - 1223: 1287.2–1399.1 and 1558.6–3172.2 s.
- **Sermons** 889, 960, 998, 1070, 1141 and 1155: quarantined on `historic_quarantine`, video
  quality approved. All 439 historic sermons remain quarantined.
- **New review flags** (post-cut checks, review queue; nothing held):
  - 1025: `sermon_audio_loudness_missed` and `transcript_repetition_suspect`;
  - 1066: `sermon_text_predates_evidence` and `published_reference_contradicts_sermon`.

**1109 and 1313 retried, 10-18 (snapshot `recut-round-20261018b`; operator: "Do 1 and 2").**
- **§3976 custody:** its quarantine and staging directories were empty, from the host as well as
  the container. Every one of the 961 sections that points at a quarantine file has a non-empty
  file; the 584 empty directories are superseded versions. Both runs' sources were intact.
- **Retry:** `UnifiedMediaProcessor::retry`, which resumes at the failed step: 1313 at job 10
  (`ExtractSermon`), 1109 at job 19 (promotion). 1313's plan replayed clean on the current code.
  - Dispatched 09:39 BST; 1109 completed at 09:39, 1313 at 09:49. No failed job, no watcher stop.
  - Diff: 2 of 2 changed, 0 need attention.
  - §3976's clip was regenerated (40 MB in quarantine, `pending_approval`). 1313's three songs are
    in quarantine. The 36 published song videos are the weekly ones, unchanged since 09-10.
- **The parked payloads** under `historic-round:recut-round-20261016g:parked:{1109,1313}` are now
  obsolete; they are kept for audit.

**974 and 1216 don't reproduce, 10-18.** The section clips their candidate preparation would re-cut
were encoded into scratch files through the same `extractMedia` call and timing check, on
`6072ee52a`: 974 §989 and §997 (§997's start moves 3.49 s under E18), and 1216 §2655, §2658 and
§2660. **All five pass.** 1216's three identical failures (04:58 on 10-18) came after the 00:46
container restart, so the stale-view explanation is unproven for 1216. The exception records no
measured values (first frame, last frame end, expected, file size), so the failure cannot be
placed. The scratch outputs were deleted. Evidence: `storage/scratch/timing-974-1216-20261018/`.

**974 and 1216 retried, 10-18 (snapshot `recut-round-20261018c`; operator: "Yes, do both").**
- **The error now carries its measurements** (`55c9cfdb7`, test-first):
  `SourceAwareMediaTimingChecker` keeps its prefix and adds the first frame, the measured and
  expected end, the tolerance, the frame count and the output's size.
- **Clone** moved to `55c9cfdb7` (old line kept as `round-20261018a-line`); `CodeRevision`
  `68ccc9b6…` equals main's; workers restarted. A database dump was taken first:
  `crockenhill-before-974-1216.sql.gz` in the 10-18 backup folder (binlog `000127:640114319`).
- **Retry:** `UnifiedMediaProcessor::retry` at job 18 (candidate preparation). 974 was still
  `processing`, so it was retried as a stranded historic run.
  - Dispatched 09:55 BST; 1216 completed at 09:57 and 974 at 09:58, with **no timing error**, no
    failed job and no watcher stop.
  - Diff: 2 of 2 changed, 0 need attention; 8 section clips re-cut. All 12 linked clips are
    present (three under the staging batch root).
  - Song videos: 36 published (the weekly ones, unchanged since 09-10). Historic sermons: all 441
    quarantined.
- **The cause stays unknown.** The failures were transient and match the stale gRPC-FUSE view
  class only by circumstance. A repeat now logs its numbers.

**Held sermons: the procedure (written 10-18; executed 10-18, results below).**
- **Parallel is fine (checked 10-18).** "One at a time" began on 10-13, when the first five held
  re-cuts were done individually, and was copied forward as a rule. `sermons:re-extract
  --held-section` only writes a one-run authority onto that run
  (`MediaProcessingLog::authoriseHeldSermonSpan`) and calls `ProcessingRunOrchestrator::reExtract`;
  it has no lock and no shared state. The queues set the concurrency.
- **A canary first anyway.** The held route last ran on 10-13. Since then it has gained edge-word
  decoding (`6a4667608`), the K16 end rule and the doubled-word fix (`b9ebcaf55`). One run goes
  through every job before the other 60 (runbook §3, staged start (a)).
- **Membership: 61 runs**, from `storage/app/private/recut-round-20261016g/held-members.json`
  (run → held section). Checked 10-18: none overlaps the runs cut that day, and every run is
  `completed`. Two entries are out of date; use the corrected sections:
  - **1262:** re-detected in `…g`, so the held sermon is now **§3287** (the old §4873 is gone);
  - **1314:** the held sermon is **§3993** (the listed §3992 carries no hold).
  - **1197** refused its 10-16 dry run (`sermon_section_content_held`): another held section lies
    inside its span. If it still refuses, it stays held and is left out of the batch.
- **Steps**, on the clone at `55c9cfdb7` or a newer master commit moved to by runbook §2.1:
  1. **Preflight** (runbook §6): queues idle; workers started after the last commit; a fresh
     database dump with SHA-256; the live checks (speaker identification off, host
     ffmpeg/whisper, Staging on USB 3, internal disk ≥ 10 GB).
  2. **Snapshot** the 61 (`historic-import:rerun-snapshot … --output=<fresh dir>/snapshot.json`)
     and start its watcher (`ROUND_SNAPSHOT=<dir> scripts/historic-round/round.sh watch`).
  3. **Dry-run every run:** `sermons:re-extract <processing_id> --held-section=<§> --dry-run`.
     Record the output beside the snapshot, and list any refusal for the operator rather than
     forcing it.
  4. **Canary:** dispatch one run that passed its dry run (`--yes`) and follow it to `completed`:
     edges, `ExtractSermon`, quality, candidates, promotion. Check the diff
     (`historic-import:rerun-diff`) and that its sermon is quarantined.
  5. **The rest together:** dispatch every other run that passed its dry run. Watch with the
     watcher; any failed job pauses all queues.
  6. **Afterwards:** the diff, a custody check (every linked clip present; staging clips sit under
     their batch root), and confirmation that nothing is public. Holds stay; the operator clears
     each one on the held-sermon release page (listening, step 5).
- **Shell traps:** zsh does not split a variable holding several names or IDs (this hit both the
  round dispatch and a worker restart). Use `xargs` or an explicit list.

**Held sermons: execution, 10-18.**
- **Freeze and preflight:** clone `55c9cfdb7`, code revision `68ccc9b6…`; workers booted after
  the commit; queues idle; speaker identification off; host ffmpeg and Whisper healthy; USB 3
  at 5 Gb/s; internal free space above 27 GB. Snapshot
  `held-round-20261018/snapshot.json` freezes all 61, membership `6e247b15…`, with the corrected
  sections for 1262 and 1314. All execution evidence is beside that snapshot.
- **Backup:** fresh `crockenhill-before-held-20261018.sql.gz`, closed `binlog.000127`, and
  `private-except-service-artifacts-before-held.tar.gz` in
  `/Volumes/Sonnics/_pre-recut-round-20261018a-backup-20261018/`, with `SHA256SUMS-held`;
  gzip integrity passed. Service artifacts were backed up separately to
  `/Volumes/Sonnics/_service-artifacts-backup/`. The post-batch artifact and detection-ruling
  backup copies completed by 14:52 BST.
- **Dry runs:** 57 passed; four refused and were not forced:
  - **1034, 1262:** `sermon_composition_review`;
  - **1120:** listed §2012 is no longer a content-held sermon;
  - **1197:** `sermon_section_content_held`, as anticipated above.
- **Canary 929:** dispatched 10:26 BST, completed 10:38. All 14 edge windows decoded;
  extraction, quality, candidates and promotion completed. Diff: one changed, none needing
  attention. Sermon 872's audio and video were present in private quarantine; §531 stayed held.
- **Batch:** of 56 further dispatch attempts after the canary, **1340** refused at the dispatch guard:
  banked composition/answers are not reflected in projected sections. It was not forced.
  Thus **56 runs were submitted in total**. **1300** subsequently refused extraction with
  `sermon_composition_review` after edge decoding; `ExtractSermon` was skipped for manual
  review, not a failed queue job. **55 completed**, the last by 14:47 BST.
- **One watcher stop, resolved:** at 13:09 BST the watcher paused every queue because sermon
  845 (run 1381) linked the newly cut audio name while its bytes were still in batch staging,
  before promotion. The 10,146,908-byte staged audio was copied without overwriting to its
  linked private-quarantine path; source and destination SHA-256 both `66eef83a…`.
  `custody-recovery-1381.json` records the paths and hashes. A fresh scan was clean; the stop
  record was archived, the watcher restarted and all queues resumed. No hold or publication
  state was changed for this recovery.
- **Final checks (14:47 BST):** diff: 56 of 61 changed, only **1300** needs attention, no media
  custody pending extraction; 240 section media re-cuts. All **539 linked files** are present
  and non-empty, with staging paths resolved under their batch roots. All 61 member sermons
  remain quarantined; 715 historic song videos remain quarantined; the published song-video
  count remains 36. Holds remain. Zero failed queue jobs since the watch began; queues empty
  and no pause flags. `final-diff.json`, `final-custody.json` and the refusal reports retain
  the detail. Post-cut text, reference and audio review flags remain for listening/review.
- **Outstanding at 14:52:** operator decisions for **1034, 1120, 1197, 1262, 1300 and 1340**,
  then the held-sermon release listening. Nothing was released or made public.

**The six refusals, 10-18 afternoon (operator: "do 3 and 2 first, then process while I listen").**
Evidence beside each snapshot (`held-round-20261018b` to `…e`) and in
`storage/scratch/held-six-questions-20261018/`. Fresh dump before any of it:
`crockenhill-before-held-six-20261018.sql.gz` (`SHA256SUMS-held`, binlog `000128:679412361`).
- **What each refusal really was** (plans replayed read-only with the held-span authority):
  - **1120:** a stale member entry. The 10-17 re-detection replaced §2012 with **§174713**, as
    1262's §4873 was. Its dry run passed on the new section.
  - **1034, 1262:** the sermon's reading carried `structure_ensemble_disagrees` from open ensemble
    questions. Answering them clears it.
  - **1197:** §2481 carried `sermon_composition_review_required`, from the risk "choose the sermon
    reading" (Matthew 2:10–12 at 3:55 or Matthew 2:1–12 at 23:53). Only that flag blocks a held
    repair.
  - **1300:** `ExtractSermon` reports any review as `sermon_composition_review`; the real reason
    was `sermon_edge_unresolved`, the sermon's **start** on "Politicians." decoded twice
    (`ambiguous_cue_anchor`).
  - **1340:** a code defect, below.
- **Questions page** https://claude.ai/artifact/GHciJbJTzQJVwwu6fw5ba7: six questions, one per
  region; 1262's three overlapping song questions were merged into one. Answers:
  1034 version A ("While Shepherds Watched", ends 36:55); 1262 short talk either, four hymns as
  proposed; 1197 Matthew 2:1–12 only; 1300 all three readings, and "there's only one
  'Politicians' here".
- **Applied:** `structure:ensemble-apply-answers` (4 applied); `reviewComposition` for 1197
  [§2478, §2481] and, after the fixes, 1300 [§4644, §4645, §4646, §214779], with before-state
  files. No output edge was set: 1300's start is placed by the new rule.
- **Three fixes** (each failing test first; full suite: only the three known failures at line 683):
  - `bc1d6fd58`: selected sections that overlap at a seam are cut through instead of refused
    (1300's Deuteronomy and Matthew 5 overlapped by 2.3 s). A nested section still refuses.
  - `ac8cba823`: an opening sentence heard twice starts before the earlier occurrence, as a
    closing one ends after the later. Measured on the frozen code over all 365 members: 1300's
    start was the only ambiguous sermon edge left (`storage/scratch/start-anchor-20261018/`).
  - `e66d567d5`: ensemble projection stamps survive the database round trip. MySQL prints a JSON
    double with at most 16 significant digits; 1340's snap delta `-5.684341886080803e-14` read
    back as `…804e-14` (binlog row images showed the flip), so its stamp never matched and every
    recompose refused again. Floats are hashed at 12 significant digits; old raw stamps are still
    accepted. Over all 415 ensemble runs the gate refuses no run it accepted before. 890, 930, 940
    and 1196 still refuse for other reasons, not examined.
- **Clone moved** to `e66d567d5` (old line `round-20261018b-line`; lockfiles unchanged;
  `bootstrap/cache` cleared). All eight round workers restarted on it.
- **Cut:** 1120 (snapshot `…c`, 14:30); 1034, 1262, 1197 (recompose then held cut, `…d`,
  diff 0 attention); 1300 and 1340 (`…e`, 1340 recomposed first; completed 15:26 and 15:27 UTC,
  diff 0 attention: 1300 at 1710.1–3862.2 s with its three readings, 1340 at 1582.3–3300.6 s).
  Every hold stays. 36 song videos public, unchanged.
- **Release page, part 1** https://claude.ai/artifact/BhfDhv3nSfsCUfw8skvsFz: the sermons held
  for their edges, each clip the newly cut file. The operator ruled all 23 fixed; those 23 holds
  were released with `service:release-content-hold` (the record kept, reason citing the page).
  1359 keeps its transcript-loop hold. 1197 was added as question 24 after its cut.
  Later added and ruled fixed: 1197 (q24), 1300's closing prayer (q25) and 1340's opening
  sentence (q26). 26 edge holds released in all.
- **Release page, part 2** https://claude.ai/artifact/PRFC9qE57UCsJ9CBdhLUWA: 28 sermons held
  for their transcript text, each clip at the fault, with the current text beside it
  (`storage/scratch/held-release-part2-20261018/`). Windows were located from the evidence:
  the 09-20 reviews' fresh source decodes matched to the current cues by word bigrams, the
  P8-Q14 `source_start` times, the source phrases of the 09-19 findings, the H10b listening
  windows, and 1278's 2736–2857 s. BC-08 drift was measured first (full stops per word now
  0.064–0.069, against 0.85–0.89 held). The operator ruled all 28 matching; 34 text holds were
  released (several sermons carried two). Nothing became public (36 song videos, no historic
  sermon).
- **The nine left after listening (operator: "go ahead with your recommendations"):**
  - **Titles/references, 944, 1381, 1382: repaired, holds released.** Each run had banked an
    analysis of its own sermon whose title and reference were discarded: the rows predate the
    import (null provenance, real titles from other weeks), which `titleMayBeReplacedByAnalysis()`
    rightly protects in general. Applied through `SaveSermonDetails` as user 1 (slugs kept, as
    the rows may have prod URLs): 881 "God lifts the humble" / 1 Samuel 2:1–10, 845 "The lost
    older son" / Luke 15:11–32, 850 "Amazing grace: saving, sustaining and future grace" /
    1 Peter 5:6–14. Each reference agrees with the one the sermon announced, and
    `published_reference_contradicts_sermon` withdrew. 881's speaker-model preacher attribution,
    which the form marks manual, was restored. Before-state:
    `storage/scratch/title-repair-20261018/before.json`. 845's and 850's preachers came from the
    other weeks' rows too and are unchecked.
  - **Duplicate identity, 1034, 1120, 1375: released under X4.** Not an open decision: X4 ruled
    the pairs on 10-14 and every step it prescribed is now done and was verified (kept runs on
    services 1018, 617, 1121 with re-dated sermons; 930, 1035, 1374 excluded as
    `duplicate_recording`; re-detected in `…g`; re-cut). Seven identity holds released, on the
    three sermons and songs §1997, §2012, §1455, §1457. 1034's other song holds (clip boundaries)
    stay for song acceptance.
  - **1262: released (part-2 q29, ruled "matches").** Tier A refuses it (no grounds), and rightly:
    its fresh decode was wrong too. But the faulty stretch (1978.6–2007.6 s) now falls between the
    reading's span and the sermon's, outside the cut, and the sermon's text opens "Well, if you
    could have Matthew 17 handy", as the operator's 09-24 note said it should. Hold on §3287
    released with `service:release-content-hold`; no flags left.
  - **1322: E20 re-cut complete; §4090 still held (10-18, 19:36 UTC).** On the operator's
    instruction, the standard `sermons:re-extract` chain ran with `--held-section=4090` after the
    staged source's SHA-256 matched the recorded source. The source probe confirmed dead picture
    over the dead audio; their intersection, **2511.29–2583.83 s (72.54 s)**, was excised. The cut
    now joins **1449.07–1469.89, 1970.63–2511.29 and 2583.83–3875.81 s**. Final video duration:
    **1853.48 s**; MP3: **1853.52 s**. Source-aware timing checks passed without source anomalies;
    the new video assessment is `approved`, with **0 dead-picture seconds** and usable share 1.
    The pipeline completed and promoted the outputs to private quarantine. Nothing was released.
    The isolated worker used the tested main checkout and Redis 10/11, then stopped; the frozen
    round clone and its workers were unchanged.

    **E20 is a shared pipeline rule for sermons and short talks.** `TranscribeOutputEdges` probes
    only dead-audio windows overlapping talks, with detector margins, and banks source-time picture
    evidence beside the RMS/classifier artifacts as `.dead-picture.json`, bound to source and RMS
    hashes. Both planners excise the corroborated interior overlap through the existing multi-span
    cut after edge snapping; songs and sound-only dropouts are unchanged. The read-only replay
    identified 1322 alone, with 988 and 1148 unchanged; 940 lacked graded-picture evidence and was
    reported as unassessable. The real source measurement then established 1322's cut above.

    The source text's hole was correct: no transcript splice was made. Removing dead media cannot
    recover lost words, so `structure_talk_audio_dropout` stays as a source-loss review note.
    §4090 still has `content_defect_hold` and `transcript_repetition_suspect`; its “God loves
    sinners” ×6 passage at 3270–3283 s remains unheard. Listen to that passage and the new seam
    before deciding the hold. The new seam is at about **561.48 s (9:21.48)** in the output.

    Validation: **172 focused tests passed**, PHPStan **0 errors**, Pint passed. The full parallel
    suite ran **9973 tests**, with 3 failures and 1 skipped test. All three failures reproduced on
    unchanged `HEAD`: the two public-page tests in `ServiceOccasionConfirmationTest` received
    redirects, and `PrepareSectionPublicationCandidatesTest`'s recording-override song re-cut kept
    the old sound. They are baseline failures, not E20 regressions.
  - **1031: released (operator: "publish 1031 anyway").** The only recording (755 s) starts a
    sentence into "Believe the impossible, find true joy" (2025-12-28 evening): its first words
    are "…Zachariah, especially when he's on duty in the temple". The sermon section is 462.7 s
    of a sermon the operator (who preached it) puts at about 8 minutes, so it is nearly whole,
    not the tail of a longer one as first reported. Cut-off hold on §1421 released. Its D5 flag
    (`structure_missing_preached_reading`, no reference) resolved as D5 intends: the operator
    supplied Luke 1:1–2:40, set on §966 through `SaveSermonDetails` (was Luke 1:5–2:38; passage
    left to the queued enrichment), and §1421 confirmed through `ConfirmServiceSection`. Release
    holds for §966: none. Before-state: `storage/scratch/reference-1031-20261018/before.json`.
    §966's preacher still reads "Visiting Speaker" (source `default`); the operator preached it.

**1322 listening, 10-18 (operator, page `ENUVcmHL8RxXC5iZBiMhYq`):** the new join at 9:21.48 is
clean. At source 3270–3283 s the fresh decode is correct: the preacher says "I heard someone say
quite a number of years ago that the most amazing thing is that God loves sinners" once, and the
stored "God loves sinners" ×6 (`transcript_repetition_blocks` on §4090) is a decoder loop over that
sentence. The stored transcript is therefore wrong for that stretch. Still open: the operator's
decision on §4090's `content_defect_hold` (its recorded evidence window, 2550–2580 s, now lies
inside the E20 excision 2511–2584 s) and whether to repair the stored text, which needs
re-transcription and so the operator's go.

**1322 follow-up, 10-18 (operator):** hold on §4090 RELEASED (`service:release-content-hold`, because
the 2550–2580 s window is excised and the stored loop is to be re-transcribed) and re-transcription
ordered. The round clone is reset to master `5baf012bf` (old line kept as `round-20261018-line`) but
its workers are not restarted and nothing is dispatched: the `CodeRevision` comparison was refused by
auto mode, so the operator runs it or authorises it first. Route: restart workers, snapshot 1322, Tier A
(retranscribe), Tier B (redetect), recompose, Tier C (extract), 1322 alone, stopping at any Tier B question.

**Moved-edge listening, page 1, 10-18 (operator; page `AD4hcutdyPbxYNZNUrWkeY`, `rulings` collection,
answers in `storage/scratch/listening-edges-20261018/answers/`):** the sermon edges moved ≥ 10 s by the
round (136 sermons, plus 1308/1339/1342/1352; the already-heard 1148, 1223, 1036, 1255 left out), 143
edges. **128 right, 15 wrong.** Wrong:
- Cut mid-sentence before a song or housekeeping line (end too early): 1115 ("stand and sing it"),
  1127 ("let's sing"), 1167, 1004, 1166, 1283. The operator: the K16 rule is applied too literally and
  cuts off the sentence before; "either include the sentence or don't".
- End too late, taking the first word of the next thing: 1244 ("But anyw…"), 1294 ("let's"), 992 (a
  split second of the next word), 1130 (ends mid-sentence).
- Start: 1136 and 1308 start too late; 1328 starts mid reading; 957 starts at the mic check, should
  start at "Let me begin".
These are one class, the word edge at a K16 end or a start-cue anchor. Mechanism not yet examined.
**Moved-edge listening, page 2, 10-18 (operator; page `G8qPsMP1xBhsH3Ljx1zcLA`, 6 s clips, answers in
`storage/scratch/listening-edges2-20261018/answers/`):** the remaining 72 sermon edges moved 1–10 s.
**62 right, 7 wrong, 3 unsure.** Wrong, all ends: 1053 (late, "cut mid sentence"), 1186 (late, "sentence
clipped"), 1315 (early, ends on "so let's"; "include the sentence or drop it"), 1216 (early), 1108 (early,
"might be an audio quality issue"), 1070 and 1218 (late). Unsure, no note on the edge: 1011, 1012 (clip
holds no speech, a sound check) and 1077 (no speech). Same class as page 1: ends beside a song or
housekeeping line landing mid-sentence.

**Moved-edge listening, page 3, 10-19 (operator; page `1Wb36h4YD86bbt2ryvoLqw`, `rulings-cut` collection,
answers in `storage/scratch/listening-edges3-20261018/answers/`):** every non-sermon section edge the round
moved ≥ 1 s, plus sermon sections not on pages 1–2, 112 edges, heard as cut-only clips (the first or last
3 s of the section as cut). **60 right, 28 wrong (21 early, 7 late), 24 unsure.** The summary doc
(`Ru85aJgE6xw2m93Vgk1iy2`) has the right counts but repeats the page's labelling fault (below).
- **The page mislabelled 39 of its 112 questions.** `page3-items.json` carries section ids from a
  different copy of the rows (1262's §4873, §4913 and §4914 do not exist in this database), while
  `build.php` took each title from `ServiceSection::find()` on that id here. The type and times were
  right, but the title belonged to a neighbouring section: 1314 is off by one throughout, and 1262 by
  up to two. Runs affected: 1314 (13), 1073 (10), 1262 (10), 1120 (5), 1034 (1). The types of 954 §798
  and 1148 §2201/§2205 also differ from this database's, but their ids are right. The operator's
  "mistitled" notes are this fault, not a data defect: 1314 and 1262 are titled correctly here. Each
  ruling is re-keyed to the section whose times it played (`answers/relabelled.json`, matched on start
  and end ±0.6 s, all 112 resolved). The verdicts below use those sections.
- **Wrong, the page 1–2 word-edge class (10):** mid-sentence at 1053 §1610 end, 1070 §3705 end, 1073
  §1714 end, 1186 §2410 end, 1268 §4734 end, 1343 §4326 end, and starts 1034 §173999, 1073 §267202,
  1120 §1994 and 1262 §3278. 11 sermon edges were heard on both page 2 and page 3. 8 agree, and 1053 and
  1186 are mid-sentence on both (only the direction differs).
- **Wrong, song edges that cut the music (8):** 954 §796 start (late) and end, 1073 §1712 end, 1120
  §1999 and §2002 ("end of the music is cut off"), 1224 §2769, 1262 §3278 end, 1262 §3288 end.
- **Wrong, starts that take in the previous item (4):** 1224 §146083 (sermon starts in the song), 1073
  §267201 (in a song), 1262 §3289 (in the end of a song) and 1262 §3286 (the reading starts at the
  announcement "I think we'll have the Bible reading").
- **Wrong, ends that run into the next item, and others (6):** 954 §901 (song end at 63:58.8 holds the
  prayer; the transcript has "Our gracious and loving God" at 63:50.6), 1034 §1454 (reading end holds a
  song intro), 1120 §174715 (song end holds the prayer start), 1314 §3990 start (late, mid introduction
  to the testimony), 1314 §3992 end (early), and 1148 §2201 end (early, "a multipart sermon"; the ruled
  composition plans that part to 1723.8 s, 2.3 s after the section end heard).
- **Unsure, no audible speech (11):** 954 ×5 (§798 end, §900 start and end, §901 start, §902 start),
  1034 §1452, 1120 §174716, 1148 §2204, 1262 §3283, 1314 §3994 start, 1368 §4583. **954, 1073 and 1034's
  service recordings are noise-gated:** they contain long runs of digital zero (−91 dB): 590 s,
  397 s and 528 s; the other runs checked have 0–70 s. 954 is silent at 25:49.4–26:07.4, where §900
  starts at 26:00.5. Their transcript cues mostly avoid those runs (954: 5.4% of cues against 15%
  silence), so audio and transcript share a timeline. The silence is in the source, not the clip.
- **Unsure because of the labelling (11):** 1073 §1709 start, §1716 start and end, §267202 end; 1262
  §3281 start, §3284 start, §3285 end; 1314 §3983 end, §3989 end, §3990 end, §3994 end. Several notes
  describe a verdict once the right title is known: 1262 §3284 "the start of a song" is right, and 1314
  §3994 end "mid sentence of an announcement" and §3989 end "introduction to a testimony" are late.
  These are not the operator's rulings yet and need hearing again with correct titles.
- **Other unsure (2):** 1120 §2000 ("Mary's Song", a reading, heard as sung); 1148 §2205 end (moved
  456.8 s earlier, to the middle of "I think we have time for Revelation 5"). 1148's ruled composition
  ends that part at 3172.7 s, not at this section end (3168.2 s), so the published edge was not heard here.

**Section-edge re-listen, 10-19 (operator; page `4SHVNmipS1EbWHJAvJmFaY`, `rulings-relisten` collection,
answers in `storage/scratch/listening-relisten-20261019/answers/`, built by `build.php` there):** the 11
relabelled questions, heard with correct titles and the neighbouring section named. **4 right, 5 wrong,
2 unsure.** These replace the page-3 rulings for the same edges.
- Right: 1073 §1709 start, 1262 §3284 start, 1314 §3983 end, 1314 §3990 end.
- Wrong: 1262 §3281 start (early: "the end of the song. No words are spoken"); 1314 §3989 end (late:
  "the start of the testimony"); 1314 §3994 end (late); 1073 §1716 end (early); 1073 §267202 end (late:
  "should either end at the end of the music or include both 'hallelujah' and 'Jesus saves'", the
  word-edge class again).
- Unsure: 1262 §3285 end ("might be right if there is a hard cut"); 1073 §1716 start ("I suspect this is
  part of 'In Awe and Wonder'"). The rows support that: §1715 "In Awe and Wonder" is a 4 s stub
  (2694.7–2698.7 s), then an 11 s gap, then §1716 "Unidentified singing" (2710.0–2888.7 s) holds the
  singing. It looks like one song split into a titled stub and an untitled body. Neither is published.
  1073 is already due for re-detection (D21, §4.1 item 7), so check this after that, not by hand.

Page 3 with these replacements: **64 right, 33 wrong, 15 unsure** of 112.

Still to listen: the J1/J2 starts, 1271,
1345 and the held-sermon release page.

**Before closing:** E20 is built and 1322's re-cut is complete, and its seam is heard. Finish the
rest of step 5 (listening), then obtain the operator's hold decision on §4090. Any further
processing needs the operator's go.

### 4.1 After the round

1. **Close the round** (runbook §8). Back up the service artifacts.
2. **Listening:**
   - every edge the round moved ≥ 1 s, and the large dead-feed moves (1308, 1339, 1342, 1352);
   - the starts moved by J1 and J2 on 10-12, never heard: 976-960, 1232, 1214, 1227-2799, 1291, 1203,
     1040, 939 (J2) and 990, 1149, 1167 (J1);
   - 1271's "sovereign" ending, 1345's "Technical difficulties", and 1036 and 1255;
   - the held-sermon release page (Q8).
3. **Stale song clips on non-song sections** (archived
   [follow-up round §9.1](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md#91-clean-up-pass-stale-song-clips-on-sections-that-are-not-songs-found-14-10),
   recounted 10-16):
   1. **Complete — cause fixed in `29126fb8a`.** The run-1002 insertion regression and
      moved-span regression failed before the fix (§4.0 decision 7). Section sync clears stale
      song paths and detaches/quarantines obsolete bindings, keeping the files. Reverified
      10-17 with `vendor/bin/sail artisan test --compact tests/Integration/Services/ServiceSectionSyncServiceTest.php`:
      **40 tests, 177 assertions passed**. No changes to the running round.
   2. **Command exists; post-round cleanup outstanding.** Use
      `historic-import:clear-stale-song-clips` in dry-run mode first to review how it un-points
      paths and re-binds or retires each `SongVideo`, keeping its file; then apply the cleanup
      after the round closes.
   3. **Outstanding:** recount after cleanup;
   4. **Outstanding:** re-run the watcher's diff.

   Open for the operator: listen to the clips that are the only copy of their song in a run before
   they are retired?
4. **Duplicate pairs**, if not applied before the round (§4.4).
5. **Song acceptance stage 2** on the settled runs ([protocol](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md)
   §3, M4).
6. **Run-level items:**
   - retire 879 (register X5);
   - 1363: OoS item 18035 still links the prayer §215988, not the sermon §248827, and no admin action
     re-links a section;
   - 1015: preacher ("Visiting Speaker", while 879 names the operator);
   - Scripture enrichment for 973, 1022 and 1171;
   - nested publication work for 1127, 1352, 1220 and 1223;
   - 1108 and 1223 (projection error), 945 (coverage), 1314 (holds without a section);
   - the six structure-review runs (973, 1287, 1288, 1327, 1355, 1366).
7. **Build the two detection fixes ruled on 10-14:** join split sermons (D21: 1073, 1268) and 954's
   talk (D22). Then re-detect those runs.
8. **Small code items:**
   - readiness should treat a retired run like an excluded one (937);
   - readiness should treat the thumbnail as pending its §4.5 step, if the operator agrees.
9. **Unset the temp-file cleanup pause** after the historic repairs (register C6).

### 4.2 Regular uploads are the lasting outcome

Historic imports use the livestream
path. Current auto-trim already shares full-service transcription, structure
detection, extraction and sermon-transcript creation. Direct uploads instead use
`TranscribeAudio`; both local clients share the decoding-options fix, but direct
transcription does not run the full-service repetition screening/recovery. Do not
treat historic acceptance as proof for all three modes.

- [ ] Add a small, source-reviewed regression set covering livestream, auto-trim
  and direct uploads, including ordinary clean examples as well as confirmed
  defects. Extend existing fixtures and tests. The current `StructureEval`
  manifest uses one transcript with correct and deliberately wrong expectations;
  it verifies evaluator behaviour, not broad real-service accuracy.
- [ ] Reproduce fluent transcript repetition through the direct-upload path and
  add a proportionate shared safeguard, preserving legitimate repetition. Verify
  the applicable configured transcription backend; local decoder settings alone
  do not prove equivalent behaviour for another provider. This closes ordinary
  upload coverage and need not block unrelated historic livestream repairs.
  **Verified against code 2026-09-22.** `TranscribeAudio` serves two modes:
  audio uploads (`buildAudioPipeline`) and direct video uploads
  (`buildDirectVideoPipeline`). Both backends request `response_format: text`,
  so these transcripts are **untimed prose**. The full-service screen is
  cue-based, and cannot run on them as-is. Sparse cadence needs 30 s cue
  boundaries, and density needs seconds. Only the repeated-phrase reason is
  meaningful on text alone. The local direct client shares `LocalWhisperDecoding`
  (`max_context=0`), so prevention reaches it; the OpenAI `whisper-1` client
  (`AudioTranscriptionService`) has no such control. No detection runs on
  either. This machine uses `local`; the production backend was not checked.
  Reproduction needs whole-sermon decodes, because context-carry loops form over
  long context and the 09-20 review's ~40 s windows cannot show them. On the
  OpenAI backend that is a paid call. Two safeguard shapes: a text-only
  repeated-phrase check on the stored transcript (proportionate), or timed output
  on the direct path (complete, but it changes a format `ProcessTranscriptWithAI`
  and the sermon page consume).
  **Reproduction attempted 2026-09-22 — not reproduced on the local backend.**
  The operator confirmed the direct path runs local Whisper. Five sermons with
  source-confirmed stored loops (runs 1003, 1039, 1064, 1084, 1176) were
  re-decoded whole through `LocalWhisperTranscriptionService`, the direct-upload
  client (`max_context=0`, with chunking as real uploads get). Each stored loop
  phrase fell from 3–6 occurrences to 0–1. A scan of all five fresh transcripts
  for *any* consecutively repeated phrase found nothing longer than two
  repetitions, all natural speech ("crucify him, crucify him"). Evidence:
  `storage/scratch/direct-repro-20260922/`. Reading: on the configured backend
  the context fix prevents the class on direct uploads, so no new safeguard is
  added for a failure that does not reproduce. The limit is five sermons, not
  a rate. An OpenAI-backed direct path would need its own check before use.
- [x] Record which upload modes each shared fix protects and verify relevant
  source content, stored artifacts and review outcomes. Keep historic-only
  custody/dispatch machinery bounded; no general reprocessing platform is required.
  **Recorded 2026-09-23 from the code**: phases per route come from
  `ProcessingPhaseRegistry`, and fix locations from each class's callers and the fix
  commits. Livestream covers historic and weekly livestream runs. Auto-trim is a
  video upload with trimming. Direct means video or audio uploads without trimming.

  | Fix (where it lives) | Livestream | Auto-trim | Direct video | Direct audio |
  |---|---|---|---|---|
  | Whisper context drift, `max_context=0` (`LocalWhisperDecoding`; both local clients) | yes | yes | yes, local backend only | yes, local backend only |
  | Repetition screen, sparse cadence, recovery, prompt-echo filter (`TranscribeFullService`) | yes | yes | **no**: untimed text; not reproduced on local (5/5) | **no**: same |
  | Sermon transcript hold from screen blocks (`CreateSermonTranscriptFromService`) | yes | yes | no | no |
  | Structure prompt in whole seconds (1493), sustained-sound widening and introduction bridge, speech-edge trim, mistyped-sung flag (`DetectServiceStructure`) | yes | yes | n/a: no structure | n/a |
  | Sermon span to next song, closing prayer, absorbed sung item, held preached reading (`SermonExtractionPlanResolver`) | yes | yes | n/a: whole upload | n/a |
  | Held sermon parks at extraction (283a6cd90); sermon MP3 from final video track (1496) (`ExtractSermon`) | yes | yes | n/a | n/a |
  | Smart cut and keyframe correction (`VideoExtractionService::extractSegment*`) | yes (sermons and song clips) | yes (sermons) | n/a: `extractOptimizedAudio` does not cut | n/a |
  | Song identity refusal over suspect blocks (`MatchSongsFromTranscript`) | yes | **no**: no song matching | n/a | n/a |
  | Song publication review: lyric edges, loops, neighbour same-song (`SongPublicationReviewPolicy`) | yes | **no**: no song clips | n/a | n/a |
  | Enhanced audio keeps the source sample rate (`AudioEnhancementService::enhanceVideo`) | yes (song clips) | **no**: `EnhanceAudio` calls audio-only `enhance()`, which the fix did not touch. Its `loudnorm` has no `-ar` either, but `libmp3lame` caps output at 48 kHz, so the 96 kHz defect cannot occur there (a 44.1 kHz source may come out at 48 kHz) | no | no |
  | Video quality verdict on the owning run (`AssessSermonVideoQuality`) | yes | yes | yes | n/a: no video |
  | Temp-file cleanup pause (`CleanupTemporaryFiles`, sweep): **operational, local only, not a fix**. It keeps restaged sources; unset it after the historic repairs | yes | yes | yes | yes |

  **Gaps that remain, by design or open:**
  - Direct uploads have no transcript loop detection. The loop class did not reproduce on
    the local backend (five sermons, 09-22), so no safeguard is added. **The OpenAI
    `whisper-1` client has neither the context fix nor detection**: switching the direct
    backend to OpenAI needs its own check first.
  - Auto-trim runs no song matching or clip publication, so song-identity and song-clip
    fixes do not apply to it. That is not a gap unless auto-trim starts publishing songs.
  - Historic acceptance is not evidence for the direct routes. It shares only the context
    fix and the video quality check with them.

  **Re-checked 2026-10-14 against `12b16f5e1` (code only; §4.5 weekly-path item).**
  - *Same chain.* `ProcessingPipelineBuilder::buildLivestreamChainJobs` builds one job list
    for weekly and historic livestream runs; the only historic addition is
    `AwaitHistoricSermonVideoStorage` (custody). Auto-trim has the same structure,
    edge-transcription and extraction jobs, without song matching or clips.
  - *Fixes since 09-23 sit in shared code.* Ensemble detection runs unconditionally in
    `DetectServiceStructure` (models fixed in `config/media-processing.php`, not `.env`).
    Cut planning, spoken-edge tail and sentence check (`CueSafeExtractionPlan`,
    `SermonExtractionPlanResolver`), and the §6.3 audio treatment (`SectionAudioTreatment`
    via `VideoExtractionService`) are reached from `ExtractSermon`, `TranscribeOutputEdges`
    and `PrepareSectionPublicationCandidates`, so they reach weekly livestream runs.
  - *Every `historic_import_operation_id` branch in pipeline code is custody, queue routing,
    nested-job bookkeeping, quarantine or historic-only tooling (re-detection, tail
    recovery)*, with **one behaviour difference**: `ProcessTranscriptWithAI::applyAnalysisSeries`
    held an uncorroborated AI series for review on historic runs but wrote it straight to
    the sermon on weekly runs. **Removed 2026-10-14:** the weekly exemption assumed the
    model knows the current series, but both paths give it the same undated list.
    Weekly runs now hold it too.
  - *Configuration the historic results depend on lives only in this machine's `.env`*:
    `LOCAL_WHISPER_MODEL=large-v3-turbo` (default `small`), `SERVICE_STRUCTURE_MODEL` and
    `ANALYSIS_MODEL` `gpt-5.6-luna` (defaults `gpt-5.6-sol`, `gpt-5.6-terra`),
    `VIDEO_EXTRACTION_REENCODE_PRESET=veryfast` (default `faster`), and the detector and
    transcription service selections (defaults `mock`). **Defaults moved 2026-10-14** for
    the Whisper model and both OpenAI models (`MediaProcessingDefaultsTest`). The preset
    stays `faster`: `veryfast` buys bulk-pass throughput a weekly run does not need. The
    service selections stay `mock` by design, so an unconfigured machine never calls a
    paid API.
  - *Not checkable yet:* worker evidence and a weekly evaluation. Weekly processing is
    paused, and has never run consistently on production.

The dependency order (transcript → structure and identity → planning → extraction →
stored-output checks) is the tier shape (register R1). The 09-22 numbered workstream list moved to the
[log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md#moved-from-the-plan-on-2026-09-24); its open gaps are in §4.4, §4.5 and the
carried items in §4.3.

#### Weekly resumption (added 2026-10-16)

Weekly processing takes over when the historic import is done (operator, 2026-10-16; register R8).
The definition of "done" is proposed in the
[programme plan §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16).
The weekly pipeline has never run consistently on production, so taking over is its own work, not a
switch.

- [ ] **Where weekly runs.** On production, by the 2026-10-05 ruling: weekly on the server, historic
  locally with a promotion step. Confirm it, or decide to run weekly on this Mac through the historic
  promotion path.
- [ ] **Production parity.** Production runs Caddy, the app (workers under Supervisor), MySQL and
  Redis, with no Whisper or classifier service. Check against the local setup the historic results
  came from:
  - **Transcription.** Which backend `.env.production` uses, and whether it gives timed cues and edge
    word timings. `max_context=0` is a local-Whisper fix; OpenAI `whisper-1` has neither it nor loop
    detection (table above).
  - **Audio classifier.** It runs in the image on CPU: measure CPU, memory and time per service on the
    production host, and the image size
    ([music plan §10](MUSIC-AND-SILENCE-DETECTION-2026-09-25.md#10-production)).
  - **Ensemble.** Models and keys (`gpt-5.6-luna`, `gpt-6-luna`), and the cost per service.
  - **Encoder.** libx264 at preset `faster`; VideoToolbox is Mac-only.
  - **Speaker identification** off (`PROD-ACTIONS-PENDING.md` §1).
- [ ] **Review without a terminal.** Only ensemble questions have an admin screen. These are
  command-line only, so each needs an admin action, or to fold into the review-question pattern
  (§4.3, "Added 2026-10-16"; register Q1):
  - edge questions (`historic-import:unresolved-edge-excerpts` and `-answers`);
  - content-hold release (`service:release-content-hold`);
  - held-sermon re-cuts (`sermons:re-extract --held-section`);
  - talk–reading answers (`service:answer-talk-reading`).
- [ ] **Weekly canary on production.** Two recent services through the whole weekly path on
  production code and hardware: time, CPU and cost measured, every question answerable in the
  admin, and the outputs listened to.
- [ ] **The backlog.** Services from 2026-08-30 until take-over, oldest first, through the weekly
  path. **Keep their original recordings until then.** Sonnics' `Services/` stops at 2026-08-23, and
  only the original file lines up with its banked timings: a re-download is a different encode.
- [ ] **Scored against the evaluation set** (§4.6) before and after take-over.

### 4.3 Acceptance evidence still owed

The discovery workstreams of 09-12 to 09-23 left these boxes. They were triaged on 09-23 and are
kept as they stood. The detail is in the
[09-12 to 09-23 log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md)
at the lines cited. Their 09-14 rulings are also in the register (§5–§6 there).

Every box still unticked in the moved sections, grouped by the section it came from. Most
items are the first line of the original; its scope, populations and acceptance are in the
[log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md) at the line cited, which is frozen. The coverage matrix, the "not a defect"
rulings and the stopping rule are restored in full because §5 gates on them (09-24).
**Triaged 2026-09-23** against the log, the code and the database: ticked items name their evidence,
superseded ones say why, and partial ones say what remains. For unannotated items no
evidence of completion was found, so they stay open; some (for example the §988 boundary
check against the 09-17 speech-edge trim) deserve a closer look before being built.

**1340's regression is a transcript regression — measured 2026-09-17/18**

- [x] Exercise the supported pipeline re-extraction/replacement path, including an equal-duration recut and a held-then-reprocessed run. *Triage 09-23: done. Canary 09-17 recut three held sermons (3/3); held runs 980/1258/1343 were re-transcribed and re-extracted 09-20 with holds persisting; 1340 repaired 09-18.* *(Full text: log L489.)*
- [ ] Compare source content with the actual stored video/MP3 at starts, ends, randomly selected interiors and every join. *(Full text: log L493.)*

**4.1 Contain confirmed missing holds first**

- *Standing rule, not a task (triage 09-23):* keep the assets quarantined while their text or cuts are recovered.

**Field coverage matrix (drafted 2026-09-14 from the schema; keep current)**

- [ ] Extend the machine-readable matrix with exact eligible membership and its
  hash, counts actually checked and unassessable (with reasons), check kind,
  instrument/version, thresholds, run date, input/output evidence hashes, evidence
  lineage, known blind spots and adjudication state. An unavailable transcript,
  source or external plan is unassessable, never a pass. Reprocessing or changed
  policy invalidates dependent evidence until the relevant checks run again.
- [ ] Split mixed populations and partially checked rows. *(Full text: log L848.)*
- [ ] Reconcile expected outputs against actual outputs from source truth and applicable pipeline contracts: talks, readings, songs, media, passage enrichment and downstream artifacts. *(Full text: log L853.)*

**Blind source review and evidence independence**

- [ ] Draw and save random interior source windows before consulting detector results, alongside complete-source review. *(Full text: log L1071.)*
- [ ] For each corroborating signal, record its original evidence and every transformation that could have inherited another signal's answer. *(Full text: log L1077.)*
- [ ] Separate intended from performed content. *(Full text: log L1083.)*
- [ ] Give the 152 runs without an independent OoS in the recorded census their own coverage and result breakdown. *(Full text: log L1088.)*

**Content alignment across processing handoffs**

- [ ] Census content alignment across every eligible sermon MP3/video pair and generated clip versus its source spans. *(Full text: log L1100.)*
- [ ] Bind source identity/hash, ordered extraction spans, final artifact hashes, stored object identity and applicable policy/version in the evidence for a run. *(Full text: log L1108.)*
- [ ] Compare each source audio channel with final MP3 and video audio. *(Full text: log L1114.)*
- [ ] Search the whole source/output corpus for exact and near-duplicate content using hashes, audio fingerprints and transcript similarity without first requiring equal dates, references or titles. *(Full text: log L1120.)*

**Controlled variations and interruption tests**

- [ ] Implement and evaluate the following relations on the affected pipeline stages, then exercise representative cases through the weekly path. *(Full text: log L1136.)*
- [ ] Exercise failure around old-file removal and replacement upload in `SermonMetadataIntegrationService::organizeVideoFile`, plus downstream row linking and review recomputation. *(Full text: log L1170.)*
- [x] Include a held-then-reprocessed run. *Triage 09-23: done by 980/1258/1343 (09-20, holds persisted).* *(Full text: log L1175.)*
- [ ] Confirm each new regression fixture fails for its intended reason before fixing a reported bug, then passes through the standard pipeline. *(Full text: log L1179.)*

**Disagreement censuses to run (all 442 active runs unless stated; now the eligible membership frozen in §4.0)**

- [ ] Complete sermon audio/video content alignment corpus-wide, including source spans and joins, under the handoff checks above. *(Full text: log L1547.)*

**Changes of examiner modality**

- [ ] Watch and listen to five complete services' outputs end to end on the rendered local pages (sermon page with video and MP3, each song page), drawn across eras and including at least one held-then-repaired run. *(Full text: log L1717.)*
- [ ] Verify playback through an actual server and browser on a representative set spanning source codecs, frame rates, mono/stereo, concatenation joins and repaired artifacts. *(Full text: log L1762.)*
- [ ] Through real media URLs, test starting playback, seeking near the middle, across joins and near the end, and resuming playback. *(Full text: log L1767.)*
- [ ] Verify replacement at an existing URL returns the current artifact through storage/server/cache delivery, including a browser that has loaded the old one. *(Full text: log L1772.)*
- [ ] Carry these journeys into the separately authorised release's observation window on the actual destination, with exact asset membership checks before release and representative delivery checks after it. *(Full text: log L1776.)*

**Rulings and stopping rule**

- [ ] Put every "not a defect" ruling to the operator explicitly, with the evidence,
  and record the decision here: the hymn cut from sermon videos
  (`selectBibleReading()`), the long song videos, video 970's
  timestamps, and any ruling §4.1b adds. (The short MP3s were resolved as a defect
  2026-09-14, not a ruling.) Added 2026-09-14:
  - **Picture starting after its audio: not acceptable; use a smart cut (operator
    ruling 2026-09-14).** Re-encode only from each cut point to the next keyframe
    and copy the rest. Pieces then start exactly on time, without the lead-in from
    the preceding item, at close to stream-copy speed and quality.
  - **1225's partial video: show it (operator ruling 2026-09-14).** The repaired
    detector must approve it, since most of the recording is real preaching;
  - **whole-recording cards count as no video (operator ruling 2026-09-14).** The
    7 card rejections are correct, and the repaired detector must keep rejecting
    them. A ruling made in passing by the reviewer is
  not a decision.
  - **A concatenated recording can contain a song (operator ruling 2026-09-14).**
    `dropSongsTheRecordingCannotContain` retypes every song section in a concatenated
    recording, which loses a song caught between segments (944 §666). The rule must
    keep a song section the evidence supports and demote only join fragments; the
    five affected runs (940, 942, 944, 973, 1014) are then re-detected through the
    pipeline. *(2026-09-24: 940, 942 and 944 had no staged source; the concatenation gate in §4.0
    restaged them the same day, so all five are reachable by the re-run.)*
  - **A non-matching reading stays joined to the sermon (operator ruling
    2026-09-14).** When no reading matches the sermon's reference,
    `selectBibleReading()` joins the nearest one, across a hymn if need be, and that
    is kept: the media reflect what the congregation heard, and a preceding passage
    (1059, 967) is a useful lead-in. Carol services (930, 1120) fit badly but are a
    couple a year. Applies to 930, 1120, 967, 1044, 1059, 1174, 1264, 1267 and 1311.
    **1043 is not covered by this ruling:** its "reading" is a verse quoted inside the
    prayer before the sermon, and the detector should not type that as a reading at
    all (§4.3a).
  - **Saturday rehearsals are excluded (operator ruling 2026-09-14).** 1043 and 1089
    duplicate the sermons that Sunday runs 1042 and 1088 carry; the Sunday runs stay.
  - **Funerals are excluded (operator ruling 2026-09-14), for a different reason.**
    1051 and 1098 are right-dated real services, but they do not belong in the sermon
    archive. Their sermons (985, 1025) and song videos (211, 231, 232) go with them.
  - **No existing path records either exclusion.** `historic-import:exclude-run`
    accepts only `no_sermon_in_source`, and `HistoricRunExclusion` (D1) rules that a real
    service must not be excluded that way. Both rulings need a recorded reason of their
    own and a defined effect on the Sermon and SongVideo rows the runs already created.
    Until that exists, all four runs stay quarantined and must not enter release membership.
    *(Superseded: built and applied 2026-09-15, §4.5.)*
  - **A sermon video may end on the next hymn's announcement and instrumental
    introduction: not a defect (operator ruling 2026-09-14).** Single-span plans for 942,
    1135, 1304 and 1224 end 14–20 s after the song section starts, and sermon 1049 ends on
    the leader reading the hymn's first verse aloud. None contains singing, so no
    detector or re-plan is due; 1224 and 1049 need no hold.
- [ ] **Stopping rule (replaces round 2's; strengthened 2026-09-14).** Close this
  discovery round only when each coverage row has an explicit eligible population,
  check kind, actually checked and unassessable counts, evidence lineage/version,
  limitations and adjudicated results; expected-output omissions are reconciled;
  and every cheap instrument has run over its current eligible membership. Complete
  blind source review, handoff alignment, controlled variations/interruption tests,
  tail inspection, local browser delivery and whole-output checks. Human-only
  dimensions need justified samples; inapplicable/deferred subsets need scoped
  reasons and decisions, not blanket exemptions. No unexplained or unassessable
  case may silently become a pass. Record effective containment or an explicit
  operator disposition for residual uncertainty and ratify the not-defect rulings.
  §4.3a's detector evaluation and §4.5's acceptance evidence must then pass their
  predeclared criteria. Closing a round is a bounded decision on this corpus and
  these instruments, not proof that unknown classes cannot remain.
- [ ] Sample the held population on the other dimensions: draw 15 held runs and run the full checklist plus the matrix rows on them, so that clearing a hold for its recorded reason does not release an unexamined row. *(Full text: log L1842.)*

**4.2 Close the transcript-loop blind spot**

- [x] After readiness verification, and **on workers running `7d6bbde95` or later**, re-transcribe 1343, 1258 and 980 through the pipeline in the canary/bounded batches. *Triage 09-23: done 09-20.* *(Full text: log L2132.)*
- [ ] Verify recovered full-service evidence and saved sermon text independently. *(Full text: log L2341.)*
- [ ] Apply §4.1b's blind interior-window review to fluent transcription errors and omitted or meaning-changing speech, as well as loops. *(Full text: log L2343.)*
- [x] Complete the identity consequence: unusable looped text cannot provide lyric confirmation. *Triage 09-23: done; song matching refuses a transcript-derived `confirmed` where a suspect block overlaps (OCR or audited review may still confirm).* *(Full text: log L2382.)*

**4.3 Refresh song policy for existing outputs**

- [ ] Reconcile the 27 boundary-policy candidates in addition to confirmed mixed section 3869. *(Full text: log L2425.)*
- [x] Add a boundary check for continuous spoken material that does not depend solely on finding a wordless gap, using section 988 as the regression case. *Built 2026-09-23 (`4abce5f0c`): a song under half sustained with a 25 s spoken lead-in or 20 s tail is held, not trimmed; 34 flagged over 463 runs, 23 already held, §988 and §1475 included.* *(Full text: log L2427.)*
- [ ] Independently verify repaired starts and tails, including clean negatives, no lost singing, and the adjoining sermon ending (135 replay trims follow sermons). *(Full text: log L2481.)*
- [ ] Keep §988/§1475 (below the half-sustained floor), §2897 (speech over organ; proposed start trim unverified), and §1457's actual repaired output as explicit real-source checks. *(Full text: log L2484.)*
- [x] Preserve the rejected broad unsung-song rule as a measured decision: 14 of 21 candidates were sung. *Triage 09-23: recorded in the log (§4.3, 09-17 speech-edge trim).* *(Full text: log L2488.)*
- [ ] Re-resolve the 72 deterministic cases through the pipeline, adjudicate the three unchanged-fallback suggestions separately, and correct livestream-sourced order-of-service items after identity settles. *(Full text: log L2495.)*
- [ ] **1287 §3596: decide the binding of OoS item 11981**, which binds no song while the sung words match #797. The lyric hold re-checks itself once the binding is made (09-24 fixes); it is not lifted by hand.
- [ ] Settle the 31 pending-approval sections with the same hint disagreement before anyone approves them. *(Full text: log L2500.)*
- [x] Add a lyric-coverage check (calibrated in §4.1a) before a song match is recorded as `confirmed`. *Built test-first 2026-09-23.* `SongLyricIdentityCheck` ports the 09-14 lyric scorer's rule unchanged (≥15 distinct content words; a rival song with ≥4 word pairs, twice the bound song's, and +0.1 IDF coverage). `MatchSongsFromTranscript` runs it on every match not taken from OCR and records `lyric_identity_check`. A contradiction raises `song_identity_contradicted_by_lyrics`, which `SongCatalogueTitlePolicy` vetoes at any confidence (so it stays `inferred` and goes to review, and the recalculator cannot promote it). The section also stays eligible for OCR, which can settle it. It is a contradiction test, not a confirmation: an unreadable transcript (`unavailable`) or too few heard words (`insufficient_words`) does not block, because Whisper drops singing. The hand-applied `…_by_transcript` flag is a separate string and is never touched. Registered as the promoted detector `song-identity-contradicted-by-lyrics`. **Corpus measurement** (read-only, inside staging contexts; `storage/scratch/lyricid-20260923-measure.{php,json}`), 1,218 bound song sections: 954 consistent, 190 insufficient words, 15 unavailable, **58 contradicted, 56 already held**. Calibration reproduces: 0 of 42 frame-verified correct sections flagged; of the 8 known-wrong, 4 are contradicted, 2 score consistent (§519, §3218), and 2 have too few words (§508, §631). **Two new unheld candidates**: §872 (run 964; bound #275, sung words match "God We Praise You #177" at 45 pairs to 3) and §3128 (run 1250, `title_hint_fuzzy`; bound #19, sung words match "I Do Not Know What Lies Ahead #871" at 18 to 3, the title its own hint names). Both are historic, at `identified`, with no clip, so nothing is exposed. The check reaches existing rows only when matching re-runs through the pipeline. Those two need a binding decision, not a hand edit. *(Full text: log L2502.)*
- [x] Redefine `confirmed` as two agreeing evidence sources whose lineage is demonstrably independent, with at least one supporting the actual performed song. *Built 2026-09-23 (`8ae957628`) as **any two** independent sources, by operator ruling; the performed-song requirement was dropped after measurement (it would demote 105 planned-and-announced bindings where Whisper missed the singing).* *(Full text: log L2504.)*

**4.3a Put a detector in the pipeline for every class found**

- [ ] Each promoted detector ships with the corpus cases in this plan as regression fixtures (positive and negative), under `tests/Fixtures/StructureEval` or beside it, following the existing fixture conventions. *(Full text: log L2981.)*
- [x] Re-run every promoted detector over the current eligible historic membership and reconcile its output. *Triage 09-23: done by `detectors:replay` (H7a, 09-21) and the sound-stage re-derivation (H7c, applied 09-21).* *(Full text: log L2984.)*

**Detector quality and automation benefit**

- [ ] For each detector, record a source-adjudicated evaluation of true and false positives, false negatives and unassessable cases, with exact denominators and uncertainty. *(Full text: log L3001.)*
- [ ] Draw detector-negative and approved examples independently of its alerts and review them against source evidence. *(Full text: log L3006.)*
- [x] ~~Separate known-defect regression fixtures from the development data used to choose thresholds/prompts.~~ *Triage 09-23: superseded by the H9 ruling (no reserved set; historic results are retrospective validation).*
- [x] Before evaluating a candidate, record acceptance thresholds by defect severity, tolerances and allowed review burden. *Triage 09-23: done; H4 predeclared thresholds contract (09-21).* *(Full text: log L3023.)*
- [x] Break results down by era, codec/channel setup, service kind and availability of independent evidence, explicitly including the no-OoS group. *Triage 09-23: done; the evaluation tabulates stored era, codec/channel, occasion and corroboration × independent OoS (H8 follow-up, 09-22).* *(Full text: log L3029.)*
- [x] Bind results to code, model/prompt, policy and evidence versions. *Triage 09-23: done; H6 version binding, and the report binds the evaluator file hash.* *(Full text: log L3034.)*
- [ ] Derive the era boundaries the breakdown needs from observed source codec/container/channel transitions in the corpus. *(Full text: log L3038.)*
- [ ] Draw and review the H10 detector-negative sample: 90 transcript-negative runs for the S1 target, by sampled interior windows rather than whole-service listening, reporting the quantity actually bounded. *Triage 09-23: partly served; H10a coverage done, and the H10b listening queue (208 windows) is the transcript half. Still needs the operator's listening.* *(Full text: log L3046.)*

**H8. What this discharges, and what it leaves open**

- [ ] Write the source-confirmed regression cases into the existing fixtures; distinguish test doubles from evaluations of real model outputs. *(Full text: log L3701.)*
- [ ] Complete each remaining detector class's tested response or explicit recorded decision not to automate it; retain the class table as the work list. *(Full text: log L3703.)*
- [ ] Complete independent positive/negative source adjudication and H10/H10b's scoped measurements, then report results and limitations against the declared criteria. *(Full text: log L3705.)*

**Final verification, interpretation and next actions**

- [x] **Contain the five audio-proven corrupt transcripts and §3869/§988.** *Triage 09-23: containment verified; runs 929/1008/1068/1187/1317 sermons and §3869/§988 all carry `content_defect_hold`.* *(Full text: log L4570.)*
- [ ] Recover the text or correct the cuts for those seven (through the pipeline).
- [ ] **Close the short-loop blind spot contextually.** *Triage 09-23: detection and regression coverage built (`4e42e05e7`, `e6b9326fc`, 09-19/20). Validating the remaining candidates against their audio is still open.* *(Full text: log L4574.)*
- [ ] **Make policy refresh reach already-generated songs.** Reassess within each owning staging context and bind the result to current inputs. *(Full text: log L4579.)*
- [ ] **Bind the three deferred duplicate pairs into Phase 9's membership.** *Triage 09-23: tracked in §4.4; kept here only as a pointer.* *(Full text: log L4584.)*

**Added 2026-10-16** (open work found in the documents archived that day):

- [ ] **One pattern for review questions, slices 1–4** (register Q1; archived
  [boundary consistency §8.6](../archived-plans/VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md#86-proposed-slices-after-measurement)).
  Ruled on 10-05 and not built:
  - the consequence is declared once and every gate reads it;
  - durable per-question answers;
  - a shared publication predicate with read-side enforcement (weekly) and the promotion pre-flight
    (historic), replacing `HistoricReleaseReviewHolds`' own rules;
  - talks are ask-only.
- [ ] **Already-published weekly clips** (cut plan D4,
  [archived §5](../archived-plans/CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md#5-decisions-and-scope)):
  a read-only, source-aware audit for the section-edge and sync defects the cut redesign fixed.
  Production re-cuts are a separate operational act.
- [ ] **945 §669 and 1342 §4319:** standing decision 4 (09-28) held them once Tier A wrote their
  text. Still marked open on 10-16: check them.

### 4.4 Bind deferred identity disputes

- [x] Choose the containment mechanism (§3.1 item 4): a hold on each sermon's own
  sermon section (§2009, §1464, §4824, §4598, §4599) through the 4.1 path, or a
  new, tested membership exclusion. `structure_low_confidence` holds already on
  those runs do not count. **Chosen 2026-09-16: the hold.** Exclusion is keyed on
  the run and its three operator reasons all rule that the occasion would never
  have been uploaded (`HistoricRunExclusion`); both runs of each pair are real
  services, so recording one would assert the adoption decision that is still open.
  A hold is keyed on the section, says only that the content is disputed, and is
  reversible by operator confirmation.
- [x] Put that explicit Phase 9 hold or exclusion on sermons 1045, 969, 1307, 1296
  and 1297, and on their generated song videos where the identity dispute reaches
  them; sermon 873's unrelated micro-section hold is not identity containment.
  **Applied 2026-09-16 at 17:52 UTC against `4f5ecd5e2`**, local database, through
  `service:hold-section-content --execute` in one all-or-nothing membership of 13
  sections: the six sermon sections §544, §1464, §2009, §4598, §4599, §4824 and the
  seven song sections §1453, §1455, §1996, §2008, §4817, §4823, §4825. Reason
  recorded: the paired run claims the same sermon and source adoption is undecided.
  - **§544 is included deliberately.** Sermon 873 was refused only through §535, an
    unrelated `bible_reading` micro-section. Confirming or clearing that section
    would have made 873 releasable with its identity still disputed, so its own
    sermon section now carries the hold. The plan's earlier five-sermon list left
    this gap.
  - **The song membership is all seven, not a subset.** They span both sides of the
    pairs — §1453/§1455 on run 1034 and §4817/§4823/§4825 on run 1035 are pair B's
    two runs, §1996/§2008 on run 1120 are pair A's better master. Adoption can still
    flip which run survives, so neither side is settled.
  - Gate before (17:51:29 UTC): **one** refusal across all 13 rows, sermon 873's
    micro-section. After (17:52:15 UTC): **14** refusals — every one of the six
    sermons on its own sermon section's `content_defect_hold`, and every one of the
    seven song videos because its section awaits review. All six sermons and all
    seven song videos were `quarantined` throughout; none was publicly exposed.
    Evidence: `storage/scratch/identity-20260916-gate-{before,after}.json` and the
    recorder `identity-20260916-gate.php`.
- [x] **Demote the seven song sections the holds left published.** They carried
  `publication_status = published` with `needs_manual_review = 1`, published
  2026-09-06 to 09-08. The holds created this inconsistency; the media stayed
  quarantined, so it was internal state drift rather than public exposure.
  **Applied 2026-09-16 at 18:22 UTC** through `service:demote-held-publications
  --apply`, scoped to those seven ids — the command is all-or-nothing and the six
  sermon sections are `not_applicable`, so naming all 13 refuses the whole run.
  The dry run assessed 7 published, 7 not-to-be-published, **0 currently visible,
  0 refused**, each reason reading `held for review: content_defect_hold`. After:
  all seven are `not_applicable` with `published_at` cleared, and no song video was
  quarantined because all seven already were. **The containment is undisturbed:**
  the gate still returns the same **14** refusals and **13** held sections across
  the demotion, so demoting a held row does not release its hold. Corpus-wide
  published-while-held is back to **0**, which restores §3's claim on a basis that
  now includes these rows. Evidence: `identity-20260916-gate-after-demotion.json`.
- **Workbook evidence for the carol pairs (2026-09-25, not a decision).** Run 1120, dated
  Monday 2024-12-23, matches all nine carols the workbook records for Sunday 2024-12-22 evening
  (Angels noted "had am and pm"), so it is the same service as run 930. Runs 1034 (dated Monday
  2025-12-22) and 1035 (2025-12-21) both match the workbook's 2025-12-21 evening carol list, and
  the workbook records no service on the 22nd.
- [x] Resolve source adoption for pairs 873/1045, 969/1307 and 1296/1297 without
  deleting the better master or substituting media beneath existing timings.
  **Ruled 2026-10-14 (operator, on recommendation): keep the real service, use the better
  file's run, set the poorer run aside.** Built and committed the same day (`12b16f5e1`):
  `historic-import:correct-run-identity --operation --processing-id --date --service --duplicate
  --note [--apply --yes]` and the new exclusion reason `duplicate_recording`, whose kept run may
  belong to another operation. With it, an excluded run never wins supersession. Dry runs on all
  three pairs read as the table below. **Applied locally 2026-10-16, 10:38–10:39 UTC.**
  Fresh dry runs matched the table exactly. The three kept sermons were re-dated in place;
  poorer runs 930, 1035 and 1374 are excluded as `duplicate_recording`; sermons 873, 1307 and
  1296 were withdrawn and all 10 assets retained under `superseded/` with matching SHA-256
  hashes. Misdated services 1027, 1017 and 1122 were removed. Re-detection, re-cutting and
  release of the kept runs' identity holds remain pending.

  | Pair | Kept run (better master) | Moves to | Poorer run (excluded) | Removes service | Re-dates sermon | Withdraws sermon |
  |---|---|---|---|---|---|---|
  | A 873/1045 | 1120 | 2024-12-22 evening (617) | 930 (other operation) | 1027 | 1045 | 873 |
  | B 969/1307 | 1034 | 2025-12-21 evening (1018) | 1035 | 1017 | 969 | 1307 |
  | C 1296/1297 | 1375 | 2020-04-12 morning (1121) | 1374 | 1122 | 1297 | 1296 |

  - A re-cut finds its sermon by run (`SubmitToProcessing::resolveExistingLivestreamSermon`), not
    by date. So the kept run's own sermon row is re-dated and the poorer run's row is withdrawn,
    its files kept under `superseded/`.
  - Order: apply the three corrections, re-detect the kept runs so they project onto the real
    service's order of service, re-cut with `--held-section`, check, and release the September
    identity holds above on the kept runs' sermon and song sections (re-read their section
    IDs at the time: re-detection re-creates rows). The kept runs' song videos lose
    `church_service_id` when the misdated service is deleted (`SET NULL`) until they are re-cut.
  - **Timing (pre-dispatch review, 2026-10-16):** the re-cut round as set up includes 1035 on the
    paid re-detection route and leaves 1034, 1120 and 1375 out. Applying the corrections before
    its snapshot turns that into one round instead of two.
    [FOLLOW-UP-ROUND §12](../archived-plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md), "Pre-dispatch review".
- [ ] Update exact release membership only after each identity decision is
  evidence-bound.

### 4.5 Prove acceptance, then converge and release

Done (detail in the [10-03 to 10-16 log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md), Part 1 §4.5):

- [x] Terminal dispositions for 1004, 1143 and 1145 (09-19): 1004 repaired by a fresh
  transcription; 1143 an accepted hold; 1145 excluded, `no_sermon_in_source` (register X3).
- [x] Occasion exclusions built and applied (09-15): `rehearsal_duplicate` and `private_occasion`.
  The services their runs created are removed, and release refuses every row of an excluded run
  (register X2).
- [x] Operation 4's completion designed and its verifier core built (09-19). **Still open:**
  1. The cover awaits the real Operation 4 backup receipt and `HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY`
     (neither may be inferred).
  2. Then generate the exact 474-item cover, review every disposition and residue, sign it, run the
     verifier, and only then apply the round-evidence closeout transition.

  The seven-report set of 09-19 is in `storage/scratch/operation-4-round-evidence/` and must be
  rebuilt on the converged state.

Open:

- [ ] Re-run §4.1a's sample validation on a fresh sample drawn after repairs, as final
  acceptance evidence, across eras and apparently clean cases. It is not a held-out set:
  H9 rules that none survives, so the sample is new, not reserved (wording corrected
  2026-09-24). Cover split sermons, partial/composite recordings, corrupt transcripts,
  repeated performances, song identity/count and boundary quality. The sample
  frame must include repaired and previously held runs, not only the never-named
  population (§4.1a's two samples excluded them), and the checklist must cover
  every row of the §4.1b coverage matrix, not the original seven dimensions. Record
  sample membership, seed, selection probabilities, applicable denominators and
  acceptance limits before inspection. Include source-first review and randomly
  selected interior passages. Report repaired/targeted challenge cases separately
  from population-rate estimates. This release-membership audit includes familiar
  cases and does not replace §4.3a's H10 detector-negative measurement of
  generalisation; report
  both results without calling previously used tuning cases untouched.
- [ ] Build a pipeline regression set from operator-reviewed weekly services
  (recent weeks with email, OpenLP and a completed review). Re-run the weekly path
  on them after each §4.2/4.3/4.3a change and measure per-field agreement against
  the reviewed values, so a fix for one class cannot silently regress another
  field. Extend the existing `tests/Fixtures/StructureEval` and appropriate media
  job/service fixtures rather than starting a new harness. `TypingBaseline` tests
  PHP typing conventions and is not a media-processing regression set. Keep tests
  using mock detector responses distinct from evaluations of live model behaviour.
  **Built 2026-10-14 from operator-ruled endings, not weekly services.** The weekly frame is
  empty: none of the 20 local weekly runs has an email or OpenLP record or a completed review,
  and the weekly pipeline has never run consistently on production. The cut planner is one
  shared chain (§4.0b re-check), so the operator's 340 ruled endings test it for weekly runs as
  much as historic ones. `RuledEndingsRegressionTest` replays 36 of them from checked-in
  fixtures (`tests/Fixtures/RuledEndings`, 2.7 MB; sample and provenance in its
  `manifest.json`): 14 clipped-word endings current code fixes, 12 sermon and 10 section endings
  ruled right, all on settled runs. Each fixture must reproduce its live cut (fidelity) and keep
  its ruling. No model or audio calls: the deterministic planning stages only. A deliberate
  mutation (song-end reach 30 s → 5 s, anchor reach 2 s → 0.2 s) fails 6 of 36. 8 clipped-word
  endings current code still gets wrong are listed as open, not tested. Still open under this
  item: structure detection and song identity have no fixture set beyond `StructureEval`'s
  evaluator check; direct uploads are out of scope (operator, 2026-10-14).
- [ ] Complete §4.1b's source-to-delivery alignment and expected-output
  reconciliation for the exact proposed membership; repeat affected checks after
  repairs and bind acceptance to current artifacts and policy. Confirm the
  controlled-variation and interruption tests pass, including equal-duration
  re-cuts, replacement failures and held-then-reprocessed runs.
- [ ] Meet §4.3a's predeclared detector and automation criteria on H10's
  detector-negative sample, with subgroup results, unassessable counts and review
  burden
  reported. Preserve failures as findings; do not move thresholds after seeing
  the evaluation answers and continue calling the same set held out.
- [ ] Confirm every implemented §4.3a response is running on the weekly path,
  with worker/code evidence and weekly evaluation separate from historic results.
  Every remaining class must have its tested response or an explicit recorded
  decision not to detect it, as §4.3a requires, before historic acceptance. Exclusion
  tooling alone does not close the automatic rehearsal/occasion detector rows.
- [ ] Regenerate corpus membership and the proposal census.
- [ ] Re-evaluate previously uncorroborated services and disagreements against the
  full-grade video evidence.
- [ ] Resolve surviving proposals by class where a safe deterministic rule exists.
- [ ] Retrain speaker identification on the historic video corpus (disabled
  deliberately; operator ruling 2026-09-14), then re-attribute the 422 default
  preachers, or accept "Visiting Speaker" explicitly for the release batch.
  Independently verify the 16 existing non-default assignments; neither deferral
  nor retraining exempts them. Keep reserved evaluation recordings out of speaker
  training, including alternate encodes of those recordings.
  **Recounted 2026-10-16:** 423 of the 439 sermons on non-superseded historic runs are
  "Visiting Speaker" with `needs_preacher_review`, and 16 carry a named preacher.
  `HistoricProcessingResultReadinessService` does not check the preacher, so the readiness count
  (12 of 455 on 10-15) does not show this item.
- [ ] Complete editorial QA for titles, slugs, references, series, speakers, songs,
  children's talks and occasions, including source-supported summary/point claims
  and meaning-changing transcript errors under §4.1b's blind review method.
- [ ] Generate thumbnails for the exact release membership only after its editorial
  QA passes (deferred for cost; operator ruling 2026-09-14). The weekly job skips
  unpublished sermons and no release-path code generates them, so this must be an
  explicit step, then checked (frame inside the span, not black or a slide).
  Existing thumbnails are checked against current cuts and editorial values too;
  deferral never certifies them.
  **Readiness counts this deferral as a blocker (found 2026-10-16).**
  `HistoricProcessingResultReadinessService` refuses a run whose sermon has no
  `thumbnail_file_path`. `GenerateThumbnail` skips every sermon that is not published
  (`SermonExposurePolicy::shouldGenerateVideoThumbnail` → `isWholeContentPublic`), so every
  quarantined sermon fails that check, and no re-cut changes it. The 15 October count of
  "406 main sermons without thumbnails" is therefore this ruling, not a backlog. The sermons of
  27 completed and 6 failed historic runs do have thumbnails; those need the check above.
  Decision for the operator:
  - readiness treats the thumbnail as pending this step until the exact membership is chosen, so
    the count shows the other blockers;
  - or the step is brought forward, generating onto the holding disk (`MediaAssetPath::holdingDisk`,
    never `THUMBNAIL_STORAGE_DISK`, which is the production bucket: 2026-10-15's five stray
    objects came that way).
- [ ] Audit exact assets, Scripture settlement, quarantine visibility and
  notification containment.
- [ ] Regenerate the hymn-usage apply artifact against the exact converged graph.
- [ ] Generate and verify authoritative Bundle A, optionally split by release era.
- [ ] Complete truth-set checks, full-output human listening and actual-server
  browser acceptance journeys under §4.1b, including seeking, range responses,
  access contexts and replacement cache freshness. Record the tested environment
  and remaining destination checks; the completed in-process census alone cannot
  close browser or delivery acceptance.
- [ ] Create separately signed, era-sized release authorisations.
- [ ] Run `historic-import:release-batch --dry-run` for each authorised batch.
- [ ] Release only exact authorised membership, observe the rollback window and
  retain the release ledger. Verify representative playback and current artifact
  delivery on the actual destination during that window; act through the existing
  rollback process if it fails.
- [ ] At IC8, retire the remaining one-shot historic-import surface and the inert
  cost-accounting residue together.
- [ ] Settle the programme plan's deferred release policy
  ([§8.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#8-release-policy)) before large
  releases.

### 4.6 Lasting evaluation and long-term custody (added 2026-10-16)

What we would regret not doing while the context exists.

- [ ] **A second copy of the source masters.** The 1 TB archive in `/Volumes/Sonnics/Services`
  (649 recordings) has no second copy (operator, 2026-10-16).
  - The decision not to back up quarantine and staging (register C2) rests on outputs being
    re-derivable from this archive, so everything else depends on it.
  - Make one copy on a separate drive or in cold cloud storage, ideally off site.
  - It needs no round to finish, but don't run it during a cut: both drives share the Mac's USB 3
    bandwidth.
- [ ] **The operator's rulings as a maintained evaluation set.** 1,290 saved ruling files sit in
  gitignored `storage/scratch`, backed up only as a tarball on Sonnics. Only 36 are committed
  fixtures (`tests/Fixtures/RuledEndings`, `RuledEndingsRegressionTest`). The edge replay and scoring
  are scratch code too (`recut-preflight-20261015/preflight.php`,
  `recut-listening-20261015/deadfeed-replay.php`, `endings-rule/score.py`). Commit two things:
  - a data-only set: for each ruled edge or clip, the evidence the planner reads (word timings, cues,
    level windows, classifier windows) and the ruled outcome, with no audio;
  - one replay-and-score command over it, run before any change to detection, composition or
    cutting, and in CI where it fits.

  Cover the endings rulings (340), the main and re-check pages, the canary 11–15 listening, the re-cut
  listening, the structure answers from the decision and question pages, the loop page and song
  stage 1.

  **Edge rulings gathered 2026-10-17 (during the round, no database access):**
  `tests/Fixtures/Rulings/edge-rulings.json` (generated by `scripts/rulings/gather.py`):
  - 883 rulings on 235 runs from the endings page, the full-pass main and v2 pages, canaries 11–15,
    the canary 13 proposed cuts and the 10-03 widening, word-edge and reading-end pages;
  - each records the run, the output, the edge and the cut heard, and what it expects of a later
    cut: keep 795, outward 65, inward 21, at a time from the note 2;
  - left out, and counted in the file: can't tell, the endings page's 25 `cut_sentence` (mixed
    directions) and questions that are not about an edge (noise, peaks, joins, "is it a talk").

  `RuledEndingsRegressionTest` now checks starts and all four expectations, and
  `scripts/ruled-endings/capture.php` takes these ruling ids. The existing 36 fixtures are
  unchanged and pass.

  Still owed:
  - **Capture evidence after the round closes.** Capture resolves the planner on the live database.
  - **Score all 883 rulings, then fix a ratchet set for CI.** Rulings current code does not yet
    satisfy are open defects, not regressions (as with the manifest's `open_clips_word`).
  - **The re-cut listening.** No saved rulings were found.
  - **Structure answers, the loop page and song stage 1.** Each needs its own replay.
- [ ] **Before IC8, decide how historic media is repaired after release.** Production receives only
  outputs; the masters, the round tooling and the promotion path all live on this Mac, and IC8
  retires the one-shot historic surface. Either keep a maintained repair route (the runbook,
  promotion, the masters) or record that the released outputs are final.
- [ ] **Preacher attribution, as proposals before release** (with §4.5's speaker item). 423 of the
  439 sermons on non-superseded historic runs are "Visiting Speaker". Evidence already in hand:
  - about 48 of the 555 order-of-service emails name the preacher ("Speaker: …");
  - many services' welcomes, in the transcripts, name the visitor;
  - a voice-clustering design exists
    ([speaker identification §11](SPEAKER-IDENTIFICATION-ROOT-CAUSE-2026-09-03.md)).

  These become proposals for the operator to confirm; nothing is applied unreviewed.

  **Text evidence gathered 2026-10-17 (read-only, during the round):** 420 historic sermons are
  "Visiting Speaker", all 2020–2026.
  - **How:** a scan of each service transcript (the opening, the 7 minutes before the sermon and its
    first 2 minutes), plus the emails that name a preacher, each email tied to the right Sunday.
  - **Result:** 23 proposals, on the "Preacher Proposals" page
    (`storage/scratch/preacher-proposals-20261017/`, answers in its `rulings` collection):
    - 19 historic;
    - 2 weekly sermons that are also "Visiting Speaker" (2026-05-03, 2026-07-05);
    - 1 conflict: 2026-07-26 is credited to Mark Drury by the speaker model, but two emails say
      "Gareth preaching from Joshua".
  - **Most services name no preacher:** the pastor preaches without an introduction. So the
    remaining ~400 need the voice clustering, which reads audio from the drives and so waits for the
    round to close. Absence of an introduction is not evidence of who preached.
  - **The speaker model's 13 historic attributions are unreviewed**, and one is contradicted.

  **Operator's answers, 2026-10-17** (`answers/rulings/` beside the page). Not yet applied: applying
  writes sermon rows, so it waits for the round to close and then uses the ordinary admin route.
  - **19 confirmed:**
    - already in the preachers list: Gareth Clarridge (2020-08-16, 2023-08-20, 2025-05-25),
      Peter Clarridge (2021-02-21), Malcolm Jones (2022-09-11, 2023-08-13, 2026-05-03),
      Laurie Everest (2025-08-31) and Jon Summers (2023-08-06, the transcript's "John");
    - new: Andrew Wilson (2023-06-04, 2024-09-01, 2025-02-16, 2025-07-06, 2026-01-25, 2026-07-05),
      Joshua Bell (2021-09-05 evening), Tarl Reeves (2022-04-17), Le Fras Strydom (2022-05-22) and
      Ralph Spencer (2025-11-23 evening).
  - **Can't tell:** 2020-06-21, 2026-07-19, and 2026-07-26 (the speaker model's Mark Drury stays).
  - **Rejected:** 2026-03-29. The email's "Jason Roach will be the speaker" was not about a service
    at this church. Email mentions need that check before they become evidence.
  - **Transcription spells names wrongly** ("Tal", "Laf raz"), so a name heard in a transcript is
    a lead, never the record.
- [ ] **Settle the programme plan's deferred release policy early**
  ([§8.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#8-release-policy)). It decides whether
  the 953 song videos can be published at all, so it should come before song acceptance stage 2 and
  the clip clean-up, not after them.
  **Content/access policy settled 2026-10-17 (G10–G11):** service listings and detail pages are
  authenticated-only; the separate sermon archive stays public. Songs remain authenticated-only permanently;
  standalone testimonies, prayer requests, prayers and private occasions are withheld by default.
  Prayers within agreed sermon boundaries stay included. Applicable permissions and actual access
  enforcement still need verification before release; no batch was authorised by this decision.
  **Enforcement built locally 2026-10-17; not deployed** (programme plan §8.4 item 5).
  Verified-login service access, gated song playback, private new song writes and a dry-run-first
  existing-object privacy command are implemented and focused-tested. Old service links preserve
  their destination through login. Full-suite and visual checks remain pending, as do operator-approved
  deployment, immediate sitemap regeneration, the reviewed production privacy dry run/approved ACL
  apply, and bucket-policy/direct/CDN reachability checks. Existing production objects were not changed;
  no release batch is authorised. See the programme's delivery status for evidence and remaining gates.
- [ ] **Rehearse the whole route on one service, early.** Export it, import it into a
  production-like copy, release it and play it back on a real server. §4.5's actual-server checks are
  done once now, while problems are cheap, as well as at the end.

### 4.7 Legacy sermon transcripts (decided 2026-10-17; planned, not built)

**Operator, 2026-10-17:** do it, and include the sermons that exist only on production. Plan it
now, build later.

**Scope.** Every published sermon from before the historic video era (before 2020-03-22) that has
audio and no transcript:
- **2003–2013:** 808 local rows (494 hours, audio only); 1 has a transcript.
  - 807 have a named preacher and 685 a Scripture reference.
  - Their audio is on this machine (`storage/app/public/sermons/audio`, 2.0 GB).
- **2014–2019:** exist only on production; uncounted.

**Production is the record.** This machine's database was built here, not restored from production
(programme plan §0.4), so local sermon ids do not match production's, even for 2003–2013.
- **Membership:** read from production by the census export (programme plan §0.4, step 0). For each
  sermon, record its production id, slug, date, service, audio path, audio size and SHA-256, and
  whether it has a transcript.
- **Identity:** every later step keys on (production sermon id, audio SHA-256), never on a local row.

**Not touched:** titles, preachers, references, series, publication state and audio (register P4).
No structure detection, cutting, AI titles or summaries.

**One transcription step, shared with direct uploads** (G9; no historic-only behaviour). A legacy
sermon is a direct audio upload that already exists.
- **Today's direct path** (`TranscribeAudio`, §4.2) returns untimed prose with no loop screening.
- **The step:** build one step that transcribes a sermon's audio with local Whisper
  `large-v3-turbo`, `max_context=0` and timed cues.
  - It keeps the cues as an artifact and runs `ServiceTranscriptRepetitionScreen`, then
    `ServiceTranscriptRepetitionRecovery`. Both already take a transcript, not a run.
  - It writes the markdown transcript through `TranscriptStorageService`.
- **Who uses it:** new direct uploads use it before their AI step; a legacy sermon uses it with no AI
  step.
- **What it closes:** §4.2's direct-upload safeguard item, which is open because the direct path
  cannot be screened.
- **One format change:** the sermon page and `ProcessTranscriptWithAI` still read markdown; only the
  cue artifact is new.

**Route.**
1. **Build, test-first,** on master after the historic import's rounds (any time; it touches no
   round):
   - the shared step;
   - `sermons:transcribe-legacy`, which reads the membership file and is resumable, with a verdict
     per sermon that survives re-runs;
   - a transcript-only promotion bundle and its production importer.
2. **Audio.**
   - **2003–2013:** use the local copy only where its SHA-256 matches production's (restore only if
     byte-identical).
   - **2014–2019, and any mismatch:** download from production storage into a holding directory on
     an internal disk, hashing each file.
3. **Sample before the run.**
   - Time about 10 sermons on host Whisper.
   - Listen to about 20 for level problems; audio treatment only if they show some.
   - Read about 10 transcripts for quality, including names and places.
   - The timing gives the full run's length: 494 hours plus 2014–2019, likely days.
4. **Run, after the historic import is done** (weekly's take-over first; Whisper is the rounds'
   bottleneck, and the run must not share the drives with a cut).
   - Local only, no paid calls.
   - A sermon still flagged by the screen after recovery is held, not shipped, and listed for the
     operator.
5. **Promote,** as a production round (programme plan §0.4, step 6). The bundle carries, for each
   sermon: production id, slug, audio SHA-256, the transcript, the cue artifact and provenance
   (code revision, model, decoding options). The importer on production:
   - **dry-runs first**;
   - writes only where the sermon still has no transcript and its audio SHA-256 matches;
   - stores under the production id;
   - changes no other field. `SermonObserver` clears the sermon's cached metadata.
   - **Rollback:** delete what the bundle's ledger wrote.
6. **Afterwards:** search (site search and the Scripture search plans) can use the transcripts.
   They appear on public sermon pages, as the sermon archive is public (G11).

**Tests:**
- the shared step on a direct upload and on an existing sermon, with no AI step for the latter;
- screen and recovery on a looping fixture;
- resumability;
- the importer refusing on a hash mismatch, on an existing transcript and on an unknown id, and
  changing no other field.

**Open before the run:**
- the production count for 2014–2019 (the census);
- whether production's audio for 2003–2013 matches the local copy;
- the sample's timing.

## 5. Go/no-go

| Gate | State | Required evidence to turn green |
|---|---|---|
| Processing | GO for an authorised round | The re-cut round is set up and not dispatched (§4.0). Every round needs the operator's go and a live preflight (runbook §6). |
| Containment | **NO-GO** | Nothing is public (0 of 443 sermons, 0 of 953 song videos), and the watcher stops a round on any lost hold. Remaining: the current-policy and unassessable residue, and the stale-clip residue (§4.1). Containment is not adoption: the duplicate pairs are ruled but not applied (§4.4). |
| Weekly take-over | **NO-GO** | §4.2's weekly-resumption checks and a canary on production, and the historic import done ([programme plan §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16)). |
| Export readiness | **12 of 455** | §3's reasons cleared or explained. The thumbnail line is a ruled deferral (§4.5). |
| Content acceptance | **NO-GO** | §4.3's stopping rule, song acceptance stage 2, listening on the re-cut edges, and §4.5's fresh release-membership sample, each against criteria fixed beforehand. There is no reserved historic set; insufficient evidence is not a pass. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks run within the authorised release's rollback window and must pass to close observation. |
