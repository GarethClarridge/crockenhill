# Historic Video Defect Discovery and Acceptance Plan

> **Status — 2026-10-16.** The full pass (10-10 to 10-12), its fix round (10-12/13) and the follow-up
> round (10-13 to 10-15) are done. Nothing is public: all 440 historic sermons and 953 historic song
> videos are quarantined. Export readiness is 12 of 455 runs (§3). The **re-cut round** is set up and
> **not dispatched**: its membership needs refreshing on current code; duplicate corrections and
> ending holds are now applied, with source listening still pending (§4.0). The
> stale-clip clean-up (§4.1), song acceptance stage 2 and the release work (§4.5) follow it.
> Getting production complete is mapped in the
> [programme plan §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16);
> weekly take-over (§4.2), lasting evaluation and custody (§4.6) and legacy transcripts (§4.7) were
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

### 4.0 The re-cut round (set up 2026-10-16, not dispatched)

**Purpose:** re-cut every run whose replayed plan differs from its cut on current code (register
E11, E12), so the edge fixes of 10-12 and the dead-feed rule (E10) reach the media.

**As set up:**
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
8. **Keep thumbnail deferral — reaffirmed 10-16 (register P2).** Generate thumbnails only after
   the release membership's editorial QA passes (§4.5).

**Go checklist** (on the operator's word, after the decisions):
1. Push master to a backup branch: no workflow runs on a non-master push. Force
   `DB_DATABASE=testing` in `phpunit.xml`, so tests in a clone cannot reach `crockenhill`; test it
   on main.
2. **Done locally 10-16:** the three duplicate-pair corrections were applied and verified
   (`historic-import:correct-run-identity … --apply`); see §4.4.
3. Build what the decisions approve on master. Move the clone to it ([runbook](HISTORIC-ROUND-RUNBOOK.md)
   §2.1). The stale-clip cause fix (R9) must be tested and included before the new freeze;
   verify the seven known-bad endings are held and have a review entry (Q12).
4. New snapshot: the 144 less 1035, plus the 38 v9 runs (the 39 less 974), plus 1034, 1120 and 1375
   on `rerun-redetect`. Dry-run every route.
5. Watcher on the new snapshot (runbook §5.1). Then:
   - stage (a): one recompose followed through every job;
   - then `rerun-recompose --all --max=500 --execute`;
   - `rerun-redetect` for its runs;
   - autotick on the extract list;
   - 974, 1126 and 1378 through `reExtract`.
6. `sermons:re-extract --held-section --dry-run` over the held runs, then cut them one at a time
   after the main batch.
7. Listening: every edge moved ≥ 1 s, the backlog in §4.1, 1036 and 1255, and the held-sermon release
   page. Then close (runbook §8).

**Time:** about 3.9 min a run (10-15): about 9.5 h for the 144, plus 2.5 h for the v9 runs. Held
sermons take about 6 min each, one at a time: about 6 h.

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
   1. fix the cause, with a failing test from 1002;
   2. a dry-run command that un-points the path and re-binds or retires each `SongVideo`, keeping its
      file;
   3. recount;
   4. re-run the watcher's diff.

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
- [ ] **Settle the programme plan's deferred release policy early**
  ([§8.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#8-release-policy)). It decides whether
  the 953 song videos can be published at all, so it should come before song acceptance stage 2 and
  the clip clean-up, not after them.
- [ ] **Rehearse the whole route on one service, early.** Export it, import it into a
  production-like copy, release it and play it back on a real server. §4.5's actual-server checks are
  done once now, while problems are cheap, as well as at the end.

### 4.7 Legacy sermon transcripts (proposed 2026-10-16; awaiting the operator)

The 808 legacy sermons (2003–2013, audio only, 494 hours) are published. 807 have a named preacher
and 685 a Scripture reference, but only 1 has a transcript. Some 2014–2019 sermons exist only on
production, and are not yet counted.

**Proposal: transcribe them, and do nothing else to them.**
- Use the transcription setup the historic work proved: local Whisper `large-v3-turbo`, timed cues,
  `max_context=0`, the repetition screen and recovery. Not the direct-upload path, which returns
  untimed text with no loop screening (§4.2's table). This needs a small route that transcribes an
  existing published sermon's audio.
- Run it locally, with no paid calls, timing a sample first. The transcripts reach production as a
  promotion round (programme plan §0.4, step 6). The 2014–2019 sermons' audio comes down from
  production storage first.
- Do no structure detection, re-cutting, AI titles or summaries: their titles, preachers and
  references are curated (register P4). No audio re-treatment either, unless a listening sample of
  about 20 shows level problems.
- Do it after the historic import, so weekly's take-over is not delayed.

## 5. Go/no-go

| Gate | State | Required evidence to turn green |
|---|---|---|
| Processing | GO for an authorised round | The re-cut round is set up and not dispatched (§4.0). Every round needs the operator's go and a live preflight (runbook §6). |
| Containment | **NO-GO** | Nothing is public (0 of 443 sermons, 0 of 953 song videos), and the watcher stops a round on any lost hold. Remaining: the current-policy and unassessable residue, and the stale-clip residue (§4.1). Containment is not adoption: the duplicate pairs are ruled but not applied (§4.4). |
| Weekly take-over | **NO-GO** | §4.2's weekly-resumption checks and a canary on production, and the historic import done ([programme plan §0.4](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#04-getting-production-complete-the-map-2026-10-16)). |
| Export readiness | **12 of 455** | §3's reasons cleared or explained. The thumbnail line is a ruled deferral (§4.5). |
| Content acceptance | **NO-GO** | §4.3's stopping rule, song acceptance stage 2, listening on the re-cut edges, and §4.5's fresh release-membership sample, each against criteria fixed beforehand. There is no reserved historic set; insufficient evidence is not a pass. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks run within the authorised release's rollback window and must pass to close observation. |
