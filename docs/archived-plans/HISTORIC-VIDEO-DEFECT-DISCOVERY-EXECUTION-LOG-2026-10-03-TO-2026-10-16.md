# Historic Video Defect Discovery: Execution Log, 2026-10-03 to 2026-10-16

> Moved **word for word** out of the historic plans when they were redrafted on 2026-10-16, before
> the re-cut round was dispatched. This is evidence, not current status: where a passage says
> "next", "pending", "uncommitted", "not yet" or "read first", read the
> [current plan](../plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) instead.
> Headings are nested one level deeper than they stood, and relative link targets were updated so
> they resolve from here. Nothing else changed. Rulings in force from these passages are in the
> [rulings register](../plans/HISTORIC-IMPORT-DECISIONS-2026-09-03.md).
>
> - **Part 1:** the plan as it stood on 2026-10-16 (commit `cb3d07ba8`), status blocks, canary 10
>   chronology and the September narratives included.
> - **Part 2:** the session notes of 2026-10-10 to 10-16, kept until then in Claude's working
>   notes for this project, newest block first: the full pass, the fix round (items 1–20, the only
>   record of its decisions), the follow-up round and the re-cut round's set-up.
> - **Archived the same day**, unchanged apart from a header and link targets:
>   [cut redesign](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md) ·
>   [boundary consistency and the full-pass record](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md) ·
>   [follow-up round](FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md).
> - [Round runbook](../plans/HISTORIC-ROUND-RUNBOOK.md) · earlier logs:
>   [09-24 to 10-02](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md) ·
>   [09-12 to 09-23](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md) ·
>   [phases 0–8](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md)

## Part 1 — the plan as it stood on 2026-10-16

## Historic Video Defect Discovery and Acceptance Plan

> **Status — 2026-10-16, re-cut round set up, not dispatched.** The full pass (10-10 to 10-12),
> its fix round and the follow-up round (10-13 to 10-15) are recorded in
> [FOLLOW-UP-ROUND §12](FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md). The re-cut round's
> pre-dispatch review found that membership leaves out:
> - 39 v9 runs whose edges the dead-feed rule moves;
> - 62 failed runs still parked on sermon content holds;
> - the duplicate pairs' kept runs, while it includes the poorer run 1035.
>
> It also found that song clips keep up to 12 s of digital-zero dead air under ruling 2's
> 15-second bar, and that the §9.1 stale-clip residue is still growing. The review and a
> proposed go checklist are at the end of that §12; §4.4 and §4.5 below carry the parts that
> belong here.

> **Status — 2026-10-10, full pass:** the current execution record (freeze, settings, membership,
> routing, order, stopping conditions, backup, preflight) is
> [VIDEO-BOUNDARY-CONSISTENCY §6.3.9](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md). Where §4.0 below
> differs from it (canary-10 gates, counts, the 40-run batch shape, "Open before batch 1"), §6.3.9 wins.

> **Status — 2026-10-10, after canary 15 and its repair pass:** canary 15 (29 runs, 12 a seeded
> unseen draw) met one stopping condition, published song clips deleted with no copy; it is fixed,
> along with the other findings, and a repair pass listened 7/7 right. Operator rulings the same
> day stopped the structure questions whose answers never changed an output (12 → 3 on canary 15).
> An offline old/new replay of 41 banked runs found no sermon cut changed, so **no canary 16**; the
> next step is to freeze and plan the full pass. Record:
> [VIDEO-BOUNDARY-CONSISTENCY §6.3.8](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md).

> **Planning reconciliation — 2026-10-04, after the read-only boundary review.**
> [Video boundary consistency](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md) owns ten newly
> recorded findings/cases and their proposed regression-first code slices. Static defects,
> synthetic counterexamples and listening candidates are distinguished there; these are not
> ten demonstrated bad recordings. F01/F05/F06 concern answers reaching projected output;
> F02/F08 concern reading equivalence; F03/F09/F10 concern final cuts; F04/F07 supply
> multipart-reading and exposed-speech cases. Track their reproduction/disposition when
> assessing the next code freeze; do not infer their closure from zero questions or technical
> media checks. No new acceptance threshold or operator policy is adopted by this note.
>
> The queued canary remains parked on its existing frozen-code contract. Finishing that round
> does not validate fixes on `fix-silent-sermon-omissions` or the proposed consistency work.
> “Final canary” below is the earlier schedule, not evidence that no further validation is
> necessary after output-changing fixes. The familiar sixteen are regression cases; the
> prepared twenty discovery candidates have unproven prior exposure and are not held out.
> Existing batch/release gates and the frozen-branch resume instructions remain authoritative.

> **Further investigation scope — 2026-10-04:** the consistency plan's
> [I1–I5](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md#7-further-bounded-investigations--added-2026-10-04)
> cover pre-detection transcript loss, speech ownership, replay stability, artifact invalidation
> and shared errors in apparently clean/unanimous output. These are bounded hypotheses, not
> additional confirmed defects. Prioritise I1/I3 for further research; I2/I5 supplement existing
> source-based acceptance evidence. Record checked extent, exposure and omissions separately
> from agreement/question counts. Every repair slice must name its restored invariant and
> prove downstream preservation. No operational authorisation or numeric acceptance bar changes.

> **Safety continuation — 2026-10-04.** The operator authorised the review fixes and
> as much of its five next steps as can proceed without input. Provider-aware edge
> transcription and custody-preserving output relocation are implemented; 1112's
> interrupted quality step was cancelled with its prior rows preserved. Code gates
> passed (9,212 tests; PHPStan zero errors) and the fresh same-16 replay passed.
> Tier C was dispatched, then parked for operator shutdown with extraction failures
> on 1108/1112 and intermittent staging-mount loss requiring investigation before
> resuming the existing queue. Canary 10 remains unaccepted; Tier A,
> the prepared next discovery-half batch and public release remain gated. Current
> evidence and technical results are recorded at the end of
> [Cut What Was Identified, In Sync](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md).

> **Status — 2026-10-02 (redrafted before the final canary).** Bulk processing is drained;
> containment, content acceptance and public release remain **NO-GO**. Repairs run through §4.0's
> corpus re-run: every eligible run is re-detected by the four-draw ensemble, its questions are
> answered, its answered draws are recomposed, and its media is cut once. Where the work stands:
>
> - **Detection passed canary 9 (2026-09-30):** 64/64 draws valid, 0 talk-count errors, 0 wrong
>   cuts. **Custody failed** on 1221 (a title-only respelling deleted two clips); fixed in
>   `1210d534e` and proven on canary 9's own before/after sections. Twenty operator decisions set
>   what the ensemble compares ([design and rule register](../plans/HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md));
>   rules A–D took canary 9's 41 questions to 7.
> - **Built 2026-10-02 for the final canary** (§4.0 "Built for the final canary"): a recompose round,
>   so answers reach the cut from the draws they were given on; evidence bound to a code revision
>   instead of a commit; the RMS log banked by reference; talk-edge checks; review samples; a batch
>   report; a held-transcript report and an operator release command.
> - **Committed 2026-10-02:** `f3b8f8893` through `e42c8e338`; the operator adopted the closing-prayer
>   exemption and authorised canary 10. Gates: 9,106 tests and 59 Dusk tests pass; PHPStan and Pint
>   clean. `.env` unchanged after Dusk. External backups completed and verified before dispatch.
> - **Canary 10 dispatched 2026-10-02:** all 16 runs reached Tier B; 11 completed and five await
>   review. Draw coverage is 63/64 valid (one invalid draw on 936); all 16 bundles replay. The
>   operator granted a bounded exception for the benign 1112 carry-forward flag (below). The
>   finalized answers are applied, six runs recomposed with no new draws, and all 16 have no
>   open question. All eight samples pass. Tier C dispatched all 16, then paused after 936
>   failed the input gate on projection-only OoS renumbering; see the recovery record below.
>   After three gate fixes (through `da7e4900b`), all 16 were recomposed from saved draws and
>   cut. The predeclared bar was met as written, but the operator then ruled that **any
>   sound/picture drift is a defect** and that **a cut is the identified sections, with no
>   second set of times**. Canary 10 is therefore **not accepted**. The causes and the
>   redesign are in [Cut What Was Identified, In Sync](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md);
>   Sermon composition is now settled in its §5 D1. Tier A waits for implementation and
>   acceptance of the repeated canary 10.
> - Release-side items, identity pairs and acceptance are unchanged (§4.4, §4.5).
>
> The 2026-09-24 to 10-02 status blocks and §4.0 narrative (route builds, canaries 1–8, freezes
> 1–10, batch 1, the detection evaluation) and the ensemble plan's evidence, evaluation and canary
> 9 record moved verbatim to the
> [2026-09-24 to 10-02 execution log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md).
> The [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md)
> is complete and archived; its DR6 is §4.0 "Batches" here. Earlier history:
> [2026-09-12 to 09-23](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md),
> [phases 0–8](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md).
> Use those files only as evidence. This document contains the remaining work.
>
> The [Historic Import: Incremental Convergence Plan](../plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
> remains the programme-level authority for production rounds, convergence,
> release and one-shot retirement. This plan owns the video-output containment
> and acceptance work feeding its final convergence/release slice.

**Original date:** 2026-08-29 (formerly `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`, renamed 2026-09-14)
**Last condensed:** 2026-10-02 (previously 2026-09-23 and 2026-09-12)
**Last reviewed:** 2026-10-02, a critical review before the final canary (performance, accuracy,
learning). It found that an answer reached a cut only through four fresh draws, which nobody had
reviewed and which asked again in one re-run in five; that no canary had cut media on the ensemble
code; that every commit, documentation included, stranded uncut rounds; that the internal disk
would fill with copies of the RMS log; and that the three plans contradicted each other. The
fixes are in §4.0.

### 1. Outcome and boundaries

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

### 2. Completed work and archived evidence

| Stage | State | Evidence |
|---|---|---|
| Phases 0–6 | Complete | Inventory, metadata/projection corrections, song eligibility, custody and database-owned dispatch are in the [execution log](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#3-delivery-plan). |
| Phase 7 | Complete | Canary, remediation and idempotent replay are in the [Phase 7 record](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#phase-7--run-a-fresh-untouched-canary). |
| Phase 8 | Processing drained; acceptance open | Pass execution, remediation and the 2026-09-06/09 reviews are in the [Phase 8 record](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md#phase-8--process-the-remainder-as-a-closed-pass-loop). |
| Defect discovery 09-12 to 09-23 | §4.0–§4.3a detailed | Canary, containment, censuses, rulings, transcript-loop and song work, class table, detector harness H1–H10b: the [2026-09-12 to 09-23 log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md). |
| Corpus re-run 09-24 to 10-02 | Route built; canaries 1–9; ensemble built and evaluated | Diff, rounds, tiers, concatenation gate, canaries 1–9, freezes 1–10, batch 1, the detection evaluation, the ensemble's evidence, §6 evaluation and canary 9: the [2026-09-24 to 10-02 log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md); the [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md). |
| Phase 9 | Blocked | Missing holds and current-policy failures below must be contained before final convergence and release. |

The archive retains the exact M1–M12 and P8-Q1–P8-Q16 narratives, commands,
measurements, hashes, repair sessions and superseded conclusions. Do not copy
those histories back here; link to the relevant anchor when they explain a new
decision.

### 3. Current measured state

**Reconciled 2026-09-16 at 17:14:57 UTC against `84d33bb24`, local Sail database.**
The census used a read-only transaction and the application's
`HistoricReleaseReviewHolds::assess()` per record. It did not modify a hold,
publication, worker or processing run, inspect production, decode media, or test a
signed release authorisation. Evidence:
`storage/scratch/plan-review-20260916-live-state.json`.
**This census predates** the 1004 and 1287 repairs, the 09-23 hold provenance backfill
and the 09-24 hold re-checks. It is recounted and hashed at the freeze (§4.0), not reused.

| Population | Runs | Linked sermons | Sections | Generated SongVideos |
|---|---:|---:|---:|---:|
| Historic, not superseded, including failures and exclusions | 445 (442 completed, 3 failed) | 438 | 4,190 | 460 |
| Historic, completed and not excluded: current discovery/repair denominator | **437** | **434** | **4,141** | **457** |

The five exclusions are silent source 955, rehearsals 1043/1089, and private
occasions 1051/1098. The former **442-run** completed baseline includes those five;
retain it on historical measurements, but use 437 for a new comparable census.
All-quarantined-row inventories also include other/superseded rows and must not be
substituted for these relationship-defined populations.

- The content gate refuses **119/438 sermons and 187/460 song videos** across
  active historic runs; in the completed, non-excluded population it refuses
  **115/434 and 184/457**. These are contained records, not defect rates. A record
  with no content refusal is not accepted or authorised for release.
- ~~**Identity remains an immediate containment gap.**~~ **Closed the same day,
  17:52 UTC.** Sermons 969, 1045, 1296, 1297 and 1307 had no gate refusal, identity
  hold or exclusion; sermon 873 was refused only by an unrelated reading
  micro-section; videos 189, 190, 250, 264, 544, 546 and 547 had no refusal. All
  six sermons and all seven videos are now refused through holds on their own
  sections (§4.4), which also rules that all seven videos inherit the dispute.
  This contains the rows; it does not decide source adoption, which §4.4 still owns.
- **Publication-state consistency is verified, not content acceptance:**
  `service:demote-held-publications --all --json` inspected 291 published sections
  and found zero demotable/refused cases. A separate database count found zero
  published sections with `needs_manual_review`. All **36** publicly published
  SongVideos are outside the historic lane and none of their sections is held.
  This is local exposure, not a production assertion. Evidence:
  `plan-review-20260916-demotion-dry-run.json`. **Broken and restored the same
  day:** the §4.4 identity holds put seven song sections into `published` with
  `needs_manual_review` at 17:52 UTC, and demoting them at 18:22 UTC returned the
  corpus-wide count to zero. The zero now holds over a population that includes
  the newly held rows, which the original measurement predated.
- The saved title-resolution comparison remains **75 changed bindings: 72
  deterministic catalogue resolutions and 3 fallback cases**. Refreshing those
  exact rows now finds **7 unheld**, §§872, 935, 2901, 3128, 3323, 3587 and 4156,
  rather than the earlier nine. §2683 is now held; §4156 still is not. This refresh
  reads their current state, not a new matcher evaluation or performance-identity
  adjudication. Do not bulk apply the three fallback suggestions.
- Operation 4 remains **`planned`**, with 409 completed and three failed active
  runs. Runs **1004, 1143 and 1145** remain unexcluded and need terminal
  dispositions. `external_disabled` remains its notification mode; this is not a
  fresh end-to-end notification audit.
- **Worker readiness is GO only for a freshly preflighted bounded repair.** The
  16 September NO-GO snapshot is superseded: all six workers were restarted onto
  `1c65dd104` before 1340's successful repair on 18 September, and the current
  container snapshot shows all six running. This is not reusable evidence for a
  later dispatch. Recheck empty queued/reserved/delayed sets, mounted staging/temp
  paths, process starts and loaded code immediately before each bounded batch.

**Status vocabulary used below:** *implemented* means the response exists in code;
*applied* means named existing rows were processed through it; *verified* names the
specific check and population that passed; *held/deferred* preserves uncertainty
without claiming repair. These states are independent: a tested fix is not a
repaired corpus, and a correct hold is not a confirmed defect.

| Workstream | Implemented | Applied / verified on existing outputs | Remaining |
|---|---|---|---|
| Explicit holds and occasion exclusions | Yes; holds survive sync and merges | Named holds, identity containment, four occasion exclusions, publication demotions and held-then-reprocessed canary applied; dated state above | Current-policy residue; source-adoption decisions |
| Song boundary/loop evidence | Version 7, input fingerprints, lyric-edge and loop risks | Successive backfills and demotions recorded in §4.3a | Current-policy scope/limitations; independent audio evaluation; identity confirmation |
| Smart cut, MP3, source audio format | Yes, including `fda414eb2` source-frame correction | Tests, source-content canary and limited real-source measurements; no completed corpus repair pass recorded | Affected media reruns; actual-server delivery checks at release |
| Structure and song identity | Prompt seconds, closing prayer/reading retention, sustained singing, mistyped sung-item risk, catalogue-title priority and 17 September speech-edge trim | Retrospective measurements and tests; trim replay is not a corpus write | Per-run re-detection/re-resolution, then extraction and derived-item repair; speech-edge exceptions |
| Local Whisper decoding | Context carry disabled; initial prompt retained on both request paths; options fingerprinted | Seven-run experiment, MP3-form check, 1314/1340/1343/1258/980 repairs and four-run recovery reconciliation; request/fingerprint tests | Wider raw-transcript census; full independent source/content and dependent-analysis checks |
| Video-quality detector | Dead-picture coverage and owning-run evidence | 48 prior rejections reassessed (28 approve/19 reject/1 review); seven prior approvals moved to review | 862 where its output lives; 897/1005 unassessed; source-versus-cut black-picture causes; independent negatives |
| Acceptance and release | Existing signed release machinery | Nine-service source inventories compared; acceptance incomplete; no new release authorised | Interior semantic review, H10 detector-negative sampling (no reserved set — H9), canary, bounded repairs, operation closeout, exact-membership QA and delivery |

The 10–11 September transcript/media-readability census and its missing-hold list
remain in the preserved follow-up review, now in the [log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md). They are not
fresh readability checks or current missing-hold counts. The five confirmed sermon
loops and §§988/1457/3869 were held on 13 September (§4.1).

### 4. Remaining work in execution order

**Execution model.** §4.0's corpus re-run is the repair route: every eligible run goes through the
pipeline again, so every fix that changed detection, matching or cutting reaches it, and a repair
also protects future services. Outside it a run is repaired only as a named exception with its own
reason (1287 on 09-23, 1112 on 09-24). This plan revision authorises no processing membership,
dispatch, paid call or release; each needs the operator's go.

#### 4.0 Corpus re-run

##### Standing decisions

Full text and evidence are in the [execution log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md).

1. **Re-run every eligible run** (historic, completed, not superseded, not excluded: 437 at the
   2026-09-27 recount, recounted at each snapshot), not only runs predicted to change (09-23).
2. **Tiers.** Tier A re-transcribes and stops before detection (ruled 10-01, superseding 09-24's
   "Tier A as a detection round"): runs whose H10b listening route is live `new_better` (126) or
   that hold a live transcript-loss hold (64), 170 in all. Tier B detects every run, Tier A's
   included once their new text is written. Tier C cuts each run's media once.
3. **Inputs.** Detection reads only independently sourced order-of-service items (09-24). Email
   stays out of the re-run, so the video stays an independent witness (09-25). Duplicate
   catalogue songs are merged in OpenLP after the re-run, before the convergence re-census
   (09-25). The hymn workbook's held-out half (143 services, `storage/scratch/hymn-video-20260925/split.json`)
   stays unopened until §4.5's song acceptance measurement.
4. **Holds.** Holds follow their content; only code-found holds recheck themselves (09-23).
   Listening's mixed and neither windows are held where a section can hold them (09-25). **945
   §669 and 1342 §4319 are held once Tier A has written their new text (09-28; open).**
5. **Talks.** A talk interrupted by a reading or prayer is flagged, never merged (09-28). A reading
   or recording inside a talk stays an ensemble question (decision 20). Each batch's diff lists
   every new `short_talk` under 60 s for a hand check; **stop before the next batch if more than
   three in one batch are confirmed false** (09-28).
6. **Media.** Tier C re-encodes above the bitrate threshold with preset `veryfast` (operator,
   2026-09-30; set in `.env` as `VIDEO_EXTRACTION_REENCODE_PRESET=veryfast`, not in code; probe
   `storage/scratch/preset-probe-20260930/`: 1.56× faster to encode, files 16–29% smaller, SSIM
   −0.0004 to −0.0014). One ffmpeg worker: a second measured +6–16% and was declined against the
   staging drive's USB-link exposure (09-30). The smart cut is frame-exact (09-27).
7. **Evidence binds a code revision, not a commit (2026-10-02).** A snapshot and its rounds bind
   the hash of the code a checkout runs (`App\Support\CodeRevision`: `app`, `config`, `database`,
   `routes`, `resources`, `bootstrap/app.php`, `bootstrap/providers.php`, `composer.lock`).
   A commit that changes only documentation leaves every snapshot and round valid; any code
   change, committed or not, needs a new snapshot and a worker restart. Snapshots and stamps
   written before this bind their commit as before. Workers still restart after any commit
   (the stale-worker exit), which is what restarted them unexplained on 09-28 and 09-30; it is
   harmless, as a worker's boot revision is the same after a documentation commit. `.env` is not
   code: a ruling that changes it is recorded here and the workers are restarted.
8. **Rules between batches (decision 10, reconciled with 09-23's "one commit" on 2026-10-02).** A
   recurring answer becomes a rule only after a replay over saved draws shows its changes and the
   operator approves. Adopting one changes the code revision, so: every uncut run is recomposed
   onto the new revision (no draws); every cut run is replayed, and only a run whose cut changes
   is recomposed and cut again. Each run's evidence names the revision its cut was made on.

##### The route a run takes

| Step | Command | What it does |
|---|---|---|
| Snapshot | `historic-import:rerun-snapshot {runs} --routing=… --routing-sha256=…` | Membership, code revision, each run's state and listening routes |
| Tier A | `historic-import:rerun-retranscribe {snapshot} [runs]` | Whisper afresh; reuses RMS and audio timeline; stamps `detection: none` |
| Tier B | `historic-import:rerun-redetect {snapshot} [runs]` | Four draws composed; any question holds the run for review |
| Review | `structure:ensemble-export-questions {runs} --sample-decided=… --sample-cuts=… --seed=…` | Review page: questions, talk-edge checks, samples, skim list |
| Answers | `structure:ensemble-apply-answers {export} {answers} --execute` | Banks each answer; records sampled cuts in `sample-results.json` |
| Recompose | `historic-import:rerun-recompose {snapshot} [runs]` | The answered draws composed again under current rules; no draw |
| Held text | `historic-import:held-transcript-report` then `service:release-content-hold` | Proposes held talks whose new text reads restored; the operator releases |
| Tier C | `historic-import:rerun-extract {snapshot} [runs]` | Cuts the media once |
| Diff | `historic-import:rerun-diff {snapshot}` | What changed; attention fails the batch |
| Backup | `rsync -a --exclude '._*'` of `storage/app/private/service-artifacts/` and `…/detection-rulings/` to `/Volumes/Sonnics/` | After every batch |
| Learning | `structure:ensemble-batch-report {runs} --export={dir} --report=…` | Questions, answers, samples, review minutes |

Every dispatch is a dry run without `--execute` and is bounded by `--max`.

##### Built for the final canary (2026-10-02, uncommitted at the time of writing)

Measured read-only before building; full suite, PHPStan and Pint after.

1. **Recompose round.** An answer on a run with media never touched its sections: the answer
   action syncs only runs without media, and every historic run has some. So the answer reached
   the run only at the next detection, which drew four fresh drafts nobody had reviewed, and a
   second detection on one commit was refused, so every review cycle cost a freeze move.
   **Measured** (`storage/scratch/carryover-20261002/carryover-report.json`, no calls): canary 9's
   answers applied to the 58 saved §6 sequences of the same 16 recordings (all 16 source hashes
   match) left 13 questions open; **11 of 58 re-rounds asked again**: 6 lost or invalid drafts
   (reduced coverage is bound to its attempt by design), one answered span matched at 0.49
   overlap against the 0.5 bar, and 6 new content questions canary 9's drafts never raised.
   Built: `DetectServiceStructure` takes a recompose request (`service_structure_recompose`, naming
   the attempt) and composes the latest banked draws with every answer, then validates, syncs and
   reviews exactly as after fresh draws. It refuses rather than draws when the attempt is no longer
   the latest or its input no longer matches the run (re-transcribed, new order of service). A job
   retry recomposes again; a final failure withdraws the request. `historic-import:rerun-recompose`
   continues a round on the same code and snapshot (held for questions, or completed and not yet
   cut), or brings an uncut run onto new code after a rule; Tier C takes either.
2. **Code revision** (standing decision 7). Computing it reads every code file, about 0.7 s
   through Docker's file sharing, once per worker boot and once per command.
3. **RMS log banked by reference.** Each attempt's input averaged 15.2 MB, almost all of it a copy
   of the RMS log already kept on the artifact disk; the internal disk had 18 GB free (96% used),
   and 437 runs at 1.5–2 rounds each would have taken 10–13 GB. The input now names the log by the
   path and hash it already recorded; a replay or draw reads it back and refuses if it changed.
   Canary 9's 16 bundles keep their copies and replay unchanged.
4. **Talk-edge checks** (ensemble decision 21). The ensemble cannot see an error every draft
   shares, and the 09-28 ruling asks about every talk edge that touches other speech with no
   pause. Each such edge on an agreed talk is now an ordinary question with the composed talk as
   its one version: "Yes, it is right" or "No, say where in the note". **Measured:** on canary 9
   with its answers, 7 open questions become 12 (5 runs; every check a talk ending straight into a
   prayer); over the 232 §6 draws, 0.77 → 1.19 questions per batch-1 sequence and 23 → 15 of 48
   sequences question-free; 16 of the 20 checks are talk–prayer joins; none fell on an edge the
   truth file marks wrong (every known error there is already a dispute). **Adopted 2026-10-02:**
   exempt a talk's end running into a prayer under the closing-prayer ruling; prayer before a
   talk, readings, interruptions and other speech remain checked. Replay of all 232 saved draws
   removes 12 of the 20 checks: 0.94 questions per batch-1 sequence, 20 of 48 question-free,
   zero unflagged talk-count errors and zero wrong scored cuts (45 scored, three accepted holds).
   Evidence: `storage/scratch/carryover-20261002/prayer-exempt-report.json` (no provider calls).
5. **Review samples.** `--sample-decided=N` moves N majority decisions, drawn with a recorded seed,
   into the questions (answered and applied as any question). `--sample-cuts=N` asks "does the
   cut start and end in the right places?" of N question-free runs, around the cut Tier C will
   make; the answer is recorded, never applied, and a wrong cut is held through the hold path and
   counted as an unflagged error. Together they measure what the queue cannot: how often a
   three-to-one vote is wrong and how often an agreed cut is wrong, on services the rules were not
   tuned on.
6. **Batch report** (`structure:ensemble-batch-report`): runs; rounds detected, recomposed and
   transcribed; open questions by type and per run; deferred; talk-edge checks; skim-list size;
   answers by kind, split into corrections and confirmations; answers applied, stale and
   conflicting; degraded attempts; sampled decisions overruled and sampled cuts wrong; and review
   minutes estimated from the answers' times.
7. **Held-transcript report and release** (proposed 09-25, ruled before the first era batch).
   Tier C parks 74 runs held on a sermon or talk, 36 of them Tier A runs whose holds no code check
   can re-test. `historic-import:held-transcript-report` measures each live hold's passage in the
   current text with the pipeline's own repetition screen and 1112's "no gap over 8 s", and
   proposes `looks_restored`, `still_suspect` or `text_unchanged`. It never clears a hold. The
   operator listens and releases with `service:release-content-hold {section} --because=… --execute`,
   which keeps the record and recomputes review. Release after the Tier B round and before Tier C:
   the diff then reads the release as `hold_cleared`, a change, not a failure.

##### Canary 10, the final canary

**Historical dispatch sequence; not current resume instructions (clarified 2026-10-04).**
The steps below record the authorised original round. Do not repeat these dispatches over
the parked queue. Use the latest shutdown/frozen-branch record at the end of
[Cut What Was Identified, In Sync](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md).
Validation of output-changing fixes belongs to the next freeze/canary, with the open
[consistency findings](VIDEO-BOUNDARY-CONSISTENCY-2026-10-04.md#2-finding-register)
explicitly accounted for; this note does not authorise that operation.

**Scope:** the 16 batch-1 runs (canary 9's set: 936, 949, 964, 1025, 1028, 1050, 1108, 1112, 1117,
1221, 1250, 1304, 1311, 1346, 1356, 1358), so every path the batches use runs once on the frozen
code, and 1221's lost clips are re-cut.

1. Commit. Restart the workers and check each one's start time against the commit. Snapshot all
   16 with the bound routing file. Preflight (below).
2. **Tier B, fresh draws:** `rerun-redetect --max=16 --execute`. This exercises the draw children
   reading the RMS log by reference, and canary 9's answers carrying to fresh draws.
3. Export with `--sample-decided=3 --sample-cuts=2`, answer every question, apply.
4. **Recompose** the runs held for questions: `rerun-recompose --execute`.
5. **Tier C** on every completed run: `rerun-extract --max=16 --execute`; the diff after it.
6. Frame-gap scan of every new cut (the canary-6 harness); batch report; back up.

**Pass, predeclared (all must hold):**

- *Custody:* both diffs show no live hold lost and nothing public; every section that lost media
  in the round has it again after Tier C, including 1221 §2718 and §2727 and their song video.
- *Draws:* 64 of 64 draws valid, or each lost one shown as reduced coverage; every new input
  banked without an RMS copy, and every bundle replays.
- *Carry:* no question canary 9 answered is asked again; no conflicting answer.
- *Accuracy:* 0 unflagged talk-count errors and 0 wrong cuts against the truth file, as canary 9;
  every question answered.
- *Recompose:* no draw made (the bank's attempt count unchanged), and no question left open on any
  answered run.
- *Cut:* every completed run cut, at `veryfast`, with no frame gap or repeat at a join; held
  sermons park as designed.

Reported, not judged: new questions on fresh draws (about 3 expected from the 11-in-58 carry-over
rate), review minutes and the samples. **A failure stops advancement and remains evidence**: fix,
commit, new snapshot, and canary 10 again on the same set.

**Operator amendment — 2026-10-02, after the Tier B result.** The predeclared bar above is
retained as the original contract. The operator explicitly permits one exception for 1112's
logged conflicting answer in this canary: canary 9 accepted one talk at 886–1596 s and rejected
a separate inner testimony at 1057–1455 s. Fresh draws contain the wider talk and no separate
inner testimony. The rejected inner scope overlaps the wider majority decision above the 0.5
matching threshold, so the matcher reports a competing answer although the two operator
instructions agree. Run 1112 completed with one talk and no open question. This exception does
not excuse any other conflicting answer, re-asked decision, accuracy, custody or cut failure;
the canary has not yet passed. The evidence is the banked compositions and saved rulings,
`storage/scratch/c10_1112.out` and the private canary batch report.

Before batch 1, fix the matcher after the canary cut queue drains. Write the reproducing test
first and cover a genuine contradictory answer as well. A rejected standalone inner section
must not compete with, or remove, the accepted containing talk when the inner section is
already absent. Preserve the evidence and check the corrected matcher against this saved bundle.

The 17 stale answers were compared with the final banked compositions, not just their source
hashes (`storage/app/private/canary10-20261002/stale-content-audit.json`). Rejected standalone
fragments are absent or merged, and the settled passages remain represented. Accuracy review
still includes 964's chosen Psalm 105 start (141.998 s → 145.017 s), 936's corrected closing-hymn
introduction now split into adjacent sections, and 1358's opening Revelation verse-reference
change. These differences are retained as evidence; source listening and cut acceptance remain.

**Tier C attempt and pause — 2026-10-02.** The finalized answers file is
`storage/scratch/canary10-questions/answers-final.json`. Ten rulings applied; the last answer,
1356-q1, was already closed by keeping the same passage as a notice through 1356-q0. The final
structure contains one notice and no duplicate other section. The conditional 1108 song answer
was checked: "Who has held the oceans in His hands" is followed by "His Mercy Is More". Six runs
(936, 949, 964, 1108, 1346, 1356) recomposed; banked attempt counts stayed unchanged. All 16
completed with zero open questions. The post-recompose diff has zero attention failures and
media custody pending extraction. The review report records 3/3 sampled cuts right and 5/5
sampled majority decisions upheld.

Tier C's dry run accepted all 16, and all 16 were dispatched. ExtractSermon then refused 936:
projection had renumbered canonical OoS positions without changing identities, content or
relative order. All source and slot hashes still match. This is a gate defect, not an
unanswered question. Historic workers were paused between jobs; 949's in-flight extraction
finished, with 14 ffmpeg jobs and one orchestration job queued and none reserved. Evidence is
in `storage/app/private/canary10-20261002/` (`answers-applied.txt`, `answer-application-note.json`,
`after-recompose-status.json`, `diff-after-recompose.json`, `batch-report-after-review.json`,
`extract-dispatch.txt`, `936-extraction-gate-source.json`, `936-current-oos-source.json`,
`tier-c-pause.json`). The gate fix is tested first against the renumbering regression and retains
rejection of real ordering, content, membership, policy and artifact changes. The regression failed
on renumbering before the fix and passed afterwards; 24 focused tests and the full 9,107-test
parallel suite pass, with PHPStan and Pint clean. Historic ffmpeg and orchestration containers
are stopped with their queued jobs preserved, so a commit cannot auto-resume the old round. Recovery requires
a new snapshot on the fixed code; the operator's approval of
a bounded reuse exception is recorded below. Do not resume the old queued round on changed code.

**Operator recovery exception — 2026-10-02.** The operator explicitly ruled "Reuse the saved
draws" after the projection-renumbering fix (`a66da8ced`). This is a second, bounded amendment to
the original predeclared failure rule: no fresh provider draws are required for this gate-only
fix. Retire the preserved stopped Tier C payloads by exact membership, settle their interrupted
runs, then take a new snapshot of the same 16 on the fixed code. Restart workers after the
commit and recompose all 16 from their latest saved attempts and finalized answers. Banked
attempt counts and attempt identities must stay unchanged, with no open questions. Then Tier C
and every original accuracy, frame-gap, custody and containment check still apply. Keep the
original before.json for full custody comparison as well as the new recovery snapshot; the
partially completed first extraction attempt is retained as evidence. This approval does not
waive any input content/order change, any additional answer conflict or any other failed gate.

**Saved-draw recovery follow-up — 2026-10-02.** The first recovery snapshot is
`canary10-20261002/reuse-before.json`, on `ee49aabd3` with the gate fix `a66da8ced`. All 16 draws
recomposed with unchanged banked attempt counts and zero open questions, but a second raw-position
comparison in DetectServiceStructure's locked write guard rejected most runs before syncing.
1050 alone completed that round. This duplicate check was missed in the first fix. No Tier C
was dispatched from this recovery snapshot. Three pending retries (936, 1221, 1250) were retired
by exact preserved payload and the interrupted/failed runs settled for recovery.

The follow-up unifies the write, dispatch and extraction input checks in EnsembleReviewGate,
retaining source artifact hashes, policy, canonical identity/content and relative order guards.
The full-job regression failed on the duplicate check before the fix; it then passed alongside
55 other focused tests. The full suite passes (9,108 tests); PHPStan and Pint are clean. A second recovery snapshot on this code
must precede recomposition and extraction; the operator's saved-draw exception still applies to
this same projection-only defect. Original before.json and both recovery attempts remain
custody evidence, and the 1112 exception and all other acceptance gates remain as recorded.


**Projection provenance follow-up — 2026-10-02.** The next snapshot,
`canary10-20261002/reuse-shared-guard-before.json` on `fe605f442`, passed detection's shared
input guard for all 16 saved bundles, with unchanged attempts and zero questions. Projection
then exposed a separate round-identity omission: the provisional song payload had already
been superseded by the previous round's refined payload, so ingestion refused to replay it.
936 and 1050 completed; the other 14 failed after exhausting retries. No Tier C was dispatched.
The failures were settled through the existing manual-review transition, with the complete
pre-transition records saved in `projection-retirement-before.json` and no evidence deleted.

LivestreamSourceAdapter now retains the latest detection round's snapshot hash and dispatch
time as immutable processing provenance. A new recorded round may legitimately observe its
provisional payload before refinement; a retry of the same round keeps the same identity.
The source ingestion guard against replaying superseded evidence is unchanged. The regression
failed on that guard before the fix and passes afterwards, including final-pass idempotence;
62 focused tests and the full suite (9,109 tests) pass; PHPStan and Pint are clean. All earlier snapshots remain custody
evidence. Recovery continues with the saved bundles and operator answers, without new draws.

**Canary 10 result — 2026-10-02, after Tier C.** Snapshot `reuse-provenance-before.json` on
`da7e4900b`; the ffmpeg worker started after that commit. All 16 recomposed from saved draws
(32 banked attempts unchanged, 0 new draws, 0 open questions), and Tier C dispatched all 16 at
16:38Z. The queue drained at 18:33Z with 0 failed jobs; all 16 are `completed` and stamped
`extracted`. Every predeclared criterion is met as written, with the two operator amendments
above (1112's carry exception, saved-draw recovery). Three findings for the operator follow
the list. Evidence is in `canary10-20261002/`: `diff-after-tier-c.json`,
`original-before-diff-after-tier-c.json`, `media-after-tier-c.json`, `cut-gapscan.json`,
`canary10-score.json` and `batch-report-after-tier-c.{json,txt}`. Scripts are
`storage/scratch/canary10-measure-20261002.php`, `canary10-gapscan-20261002.py` and
`canary10/score.php`, copied from the canary 6 and canary 9 harnesses.

- *Custody:* against the recovery snapshot, 0 runs need attention and 0 have custody pending.
  Against the original `before.json`, 10 runs list 25 sections that "left review". In
  `before.json` each was in review only through `structure_ensemble_disagrees`, the
  open-question flag; those questions are answered, and all 25 were already out of review in
  the recovery snapshot. Neither diff has a lost hold, lost or removed media, or anything
  public. The 20 newly published songs are all `section_published_into_quarantine`. For 1221:
  §2718 was retitled "King of Kings" → "King Of Kings" (canary 9's failure shape) and
  re-cut, not deleted. §2727 and its song video 659 were re-cut.
- *Draws, carry, recompose:* as recorded above (63/64 valid, with 936's lost draw shown as
  reduced coverage; every bundle replays). 1 conflicting answer, which is 1112's excepted one.
- *Accuracy:* against `detection-truth-20260928.json`, 0 talk-count errors (flagged or
  unflagged), every truth section matched, and 0 wrong cuts. For the 13 scored cuts, the
  written video matches the planned span to within 0.05 s. Samples: 0/5 majority decisions
  overruled, 0/3 cuts wrong.
- *Cut:* 72 files measured plus 1221 §2718, none missing, at `veryfast`. 70 of 73 have
  regular frame timing. Two of the three exceptions are not this round's defects: 1050's last
  frame (+0.1 s, in the recording, as canary 6), and 1028 §1398's held clip, a 09-27 cut
  reused because its section did not change (1112's song video 614 was reused the same way).

**Findings for the operator (not failures of the bar as written):**

1. **936 sermon, half-frame step at 59.25 s.** The stream-copied piece and the re-encoded tail
   of the first span join 0.049 s apart instead of 0.033 s. No frame is lost or repeated: the
   span holds 1,799 frames against 1,798.8 planned. The source has no part boundary there and
   is regular (33/34 ms steps, keyframe at 248.0 s in `11-02.mkv`), so the cut introduced it.
   The step is a 16 ms timing hold, not a missing frame. Accept, or investigate the piece
   placement on millisecond-clock Matroska before batches.
2. **Sermons merged across an interruption are cut from the baseline.** In 1050, 1250 and 1356,
   detection's sermon matches the truth. However, `structure_sermon_interruption_merged` is a
   `MATERIAL_BOUNDARY_FLAGS` entry, so the resolver falls back to the dominant speech segment
   (`no_high_confidence_sermon_section`). 1050 is cut 0–920 s (truth 79–838), 1250 is cut
   1739–4391 s (truth 2454–4310; it started at 2182 s before this round), and 1356 is cut
   1233–3555 s (truth 1234–3489). All three sermon sections have `needs_manual_review`,
   are quarantined and remain unscored as in canary 9. This is designed behaviour, but the
   held cut is worse than the detected span. A rule change would come before batch 1.
3. The 1112 matcher fix (above) remains required before batch 1.

**Operator ruling — 2026-10-02, after the result.** "Anything that gets audio out of sync
with video is bad", and a cut should simply be the video for what was identified, with no
extra logic about times. Canary 10 is not accepted. A whole-file sync scan found four causes,
all in our code (`loudnorm` in song videos, span joins, smart-cut piece placement, and reuse of
old cuts). A census of 475 runs found 46 sermons cut from the old detector's times, 26 of them
containing songs. See [Cut What Was Identified, In Sync](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md).
**Follow-up operator decision — 2026-10-02:** sermon media contains the sermon reading
(if separate), the sermon, and a concluding prayer if before the post-sermon song. The linked
plan now records this composition and the simplified shared cutter, focused sync regression
tests and explicit media-processing version. Implementation is committed through `fbfd9d1cd`,
with the 1112 matcher fix in `0098afe2c` and unindexed-WebM compatibility in `cf8a44371`;
the full suite passes (9,140 tests), PHPStan and Pint
are clean, and the frontend build and full Dusk suite pass. The selected corpus benchmark passes
(27 output assets plus the isolated known-defect span). The linked plan records the evidence and the
fresh same-16 canary snapshot. Canary 10 remains unaccepted under the existing controls. The production
weekly-media audit is a separate follow-up.

**Section-cut canary follow-up — 2026-10-03:** after explicit approval, private service-artifact
and detection-ruling backups were refreshed on `/Volumes/Sonnics`. All 16 saved-draw
recompositions completed on the fixed code, with queues drained and no new failed jobs.
Both fresh and original custody diffs report zero attention; media custody is still pending
extraction (15 and 12 runs respectively). Fifteen runs now require composition review: 98
uncovered-speech findings and one unresolved reading-membership finding. The private
`cut-sections-canary10-20261002/composition-review.md` handoff links their service editors.
No extraction or acceptance was dispatched; those findings must be resolved first.

##### Batches

**Tier A** starts once canary 10 passes (dispatch only after its custody diff is clean): 170 runs,
transcription only, on the Whisper worker (130–280 s a run, 6–13 hours), and it can run while
Tier B batches use the LLM worker. Then raise the 945 and 1342 holds, take a new snapshot (the
code is unchanged, so no commit is needed), and run the held-transcript report after each batch's
round.

**Batch shape, adopted by the operator 2026-10-02:**

- About 40 runs a batch, in era order: about 5 hours of Tier C and, at canary 9's rate after rules
  plus the talk-edge checks, about 35 questions.
- **Batch 1:** 20 runs from the workbook's *discovery* half (2023–2026), so song identity is scored
  automatically against the pianist's record without opening the held-out half.
- Each batch: snapshot → Tier B → export (`--sample-decided=5 --sample-cuts=3`, seed recorded) →
  answers → recompose → held-transcript releases → Tier C → diff → back up → batch report → rule
  proposals.

**Stop rules, adopted by the operator 2026-10-02:** stop before the next batch when any of:

- a live hold is lost or anything becomes public;
- a sampled cut is judged wrong with no question raised on it (an unflagged error): investigate
  before the next batch; two in one batch stop it;
- a sampled majority decision is overruled: investigate rule D; two in one batch stop it;
- more than three new talks under 60 s in one batch are confirmed false (standing decision 5);
- more than 10% of a batch's ensembles lose a draft (provider trouble);
- unresolved composer disputes per run, **before saved answers**, exceed **2.0**. The operator
  adopted the raw canary 10 baseline of 16 disputes / 16 runs = **1.0** on 2026-10-02, replacing
  the post-answer rate of 0.31. Use the same composer-dispute unit in later batches; a dispute
  may produce more than one review-page question. Samples are excluded from this rate.

**Performance.** Tier C dominates: about 50–58 hours on one ffmpeg worker at `veryfast` (60–68 at
`faster`, measured on canary 5). Tier B rounds take about 88 s a run on the one LLM worker (about
10.7 hours for 437); a second LLM worker would halve that if review ever waits on rounds. With the
recompose round and the code revision, batches can overlap: batch N+1's round and review while
batch N cuts, because neither an answer nor a documentation commit strands a queued cut. Only a
rule commit does, and it waits until the cut queue has drained.

##### Open before batch 1

- Batch shape and stop rules adopted 2026-10-02 as written above.
- Talk-ending prayer exemption adopted 2026-10-02 (built 4); retain checks at other speech edges.
- Raise the 945 §669 and 1342 §4319 holds after Tier A writes their text.
- Run the held-transcript report and release what listening confirms.

##### Preflight for every dispatch

1. Freeze exact membership in the snapshot; settle transcript and structure dependencies before
   cutting; retain or exclude unresolved cases explicitly rather than widening the batch.
2. Queues empty (queued, reserved, delayed); every worker started after the last code commit;
   staging and temp mounts readable and writable; free space for the batch on `/Volumes/Staging`
   and at least 10 GB on the internal disk (service artifacts live there); the previous batch
   backed up.
3. Reuse the canary's evidence for paths it covered; check representative first outputs of any
   path it did not; stop expansion on a new regression or an unassessable result. Outputs stay
   quarantined pending §4.5.

**Canary 10 word-pause dispatch, 2026-10-03 — initial extraction hold, resolved below.** The
operator authorized only the same sixteen, with 1050, 1250 and 1356's interruption-merged
sermons parked. Preflight passed on unchanged code `6b355b8cd` / revision
`e09d11928eeb5a3401e9e5068b4eb247234c3b3a7696b6ce35b505533fb09cde`; `8ea5a0a99` is
documentation only. The latest `before-word-edges.json` snapshot was reused. All historic
workers started at 12:29:54 UTC, after the last code commit. Queues were empty, staging and
temp mounts passed read/write probes, internal free space was 20,249,888 KiB and staging
489,518,068 KiB. Sonnics service-artifact and detection-ruling backups were refreshed with
checksum comparison and no deletions. Receipt: `canary10-word-run-20261003/preflight.json`.

All sixteen saved-draw recompositions completed. The bank still has 32 attempts with unchanged
attempt IDs and draw-bank hashes; failed jobs remain at 479, with zero new failures.
`before.json`, `after-recompose.json` and `timing-results.json` in that receipt directory bind
the comparison. All 64 immutable draw artifacts match their checksums; 63 remain valid.
936's pre-existing slot 2 still fails `non_chronological`, giving three valid votes rather
than four (`936-slot-replay.json`); no provider draw or new bank input was made.
**1311 still has one open talk-edge question**, Naomi's testimony
1056.60–1149.31, beside baptismal instructions. Its accepted word-pause relisten concerned
songs. The operator prohibited other answers and required zero open questions, so Tier C is
held pending the requested choice: park 1311 and cut twelve, or hold all cuts pending review.
No answer was invented or recorded. The thirteen excluding the three interruption-merged
runs pass the extraction dry run, but this does not resolve the zero-question condition.

**1311 continuation, 13:42 UTC:** the operator confirmed Naomi's testimony as proposed,
1056.60–1149.31 s; the father's words from 1152.28 belong to the baptism section.
`structure:ensemble-export-questions 1311` produced an exact answer map for
`ea480c55f1eaa6e5a690af4c315a4720f532e361d806e5135c3ce2c004cf0ae5`.
`structure:ensemble-apply-answers` dry run reported one ready/no attention, then `--execute`
recorded the choice through the existing review action as verified admin 1. All sixteen have
zero open questions; bank counts, attempt IDs and draw hashes remain unchanged. The batch
replay corroborates zero open and zero unreplayable runs: `batch-after-1311-answer.json`.
Refreshed preflight (`preflight-tierc.json`): unchanged code/snapshot, workers started
13:18:13 UTC, empty queued/reserved/delayed queues, read/write mounts, 20,118,940 KiB internal
and 490,289,896 KiB staging free, checksum backups refreshed successfully. At 13:50–13:51 UTC,
Tier C dispatched thirteen with zero refusals (`tierc-dispatch.txt`), excluding 1050, 1250 and
1356. Those three retain their interruption flags and await the operator's join confirmation.
The answer receipt is `1311-answer-receipt.json`; extraction/check results follow below.

**Cut/check receipt, completed 2026-10-03:** thirteen Tier C runs completed by 15:53:33 UTC,
queues drained and failed jobs remained 479 (zero new). No fresh draw or extra answer was
made. 1050/1250/1356 remain parked with interruption flags retained. `receipt.json` under
`storage/app/private/canary10-word-run-20261003/` binds all evidence; the detailed per-step
receipt is in [the cut/sync plan](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md).

- Timing: the all-sixteen step checked 153 windows, 152 reused/one decoded in 2.135 compute
  seconds; Tier C rechecked 135, all reused. No no-word fallback or missing-cache blocker.
  Ninety-eight generated-cut edge audits, 83 text disagreements; absolute movement median
  0.92 s, p90 7.22 s, p95 10.61 s, max 27.54 s. Sixty-two transcript-gap edges retained their
  originals, with reasons in `edge-inventory.json`.
- Generation/sync: thirteen sermon and 57 section audits are current version 6 and match the
  cached selected plans; 70 distinct new videos (60 quarantine/ten private staging candidates).
  Seventy cut checks plus seventeen enhancement checks passed, no source anomaly or timing
  refusal. Original AV/gap scripts covered all seventy, including ten diagnostic candidates
  omitted by the promotion inventory: zero packet jumps/gaps/repeats, max packet-end offset
  29 ms. No pre-redesign file was reused for a generated output. 1050's known final-frame gap
  is confirmed in source; it remains parked. Evidence: `in-cutter-final.json`,
  `new-videos-all.tsv`, `avscan-final.json`, `gapscan-final.json`, `file-generation-check.json`.
- **Bar not met.** Original/latest custody diffs have 9/11 attention runs; all 48 attention/
  pending strings are explained in `attention-explained.json`, without waivers. No lost live
  hold or public output. Three held extraction signatures remain unrestored: 1108 §1897,
  1221 §2718/§73036. Five other media losses belong to parked 1250. Review exits are changes
  in saved-draw projection/publication routing, not operator acceptance.
- **Naomi clip still blocked.** 1311's authorized answer closed the question in the bank,
  but the action preserves projections on runs with media. §73575 still carries the old
  disagreement flag and was skipped. It should have been reprojected while the round was
  deferred, before Tier C; that ordering was missed. The subsequent 1311-only recompose dry
  run refused `already re-run on this commit at 2026-10-03T12:48:32+00:00`. No repeat, flag/
  stamp bypass, code/freeze change or fresh draw was dispatched. Its plan is
  1056.70–1149.31; the father's 1152.28 words are excluded, but no new Naomi clip exists.
- Accuracy: scorer talk-count errors zero; copied-run cache probes leave cuts unscored, so
  `accuracy-direct.json` and `accuracy-talk-direct.json` report every selected/actual plan
  against truth spans. All sermon/talk planned boundaries are within tolerance; thirteen
  sermons/seventeen talks have new audits, with parked outputs and Naomi explicitly pending.
  `listening-list.json` has 106 moved-edge/join/comparison entries; `joins.json` supplies the
  three parked interruption candidates and 1250's four-part joins, transcript lines and
  review URLs. Only output-edge timing windows were decoded.
- Further read-only findings: five talk texts preserved/flagged as predating evidence;
  quality assessments before promotion inspect prior quarantine files; seventeen published
  song sections retain staging disk pointers although canonical new quarantine files exist.
  These were recorded without fixes. 1267's two-song/silence finding remains separate.
- Both Sonnics backups refreshed with checksum rsync, no deletions, exit zero. The fresh
  detection-ruling export contains current bank/ruling/review/hold state and its backup SHA
  matches. The final batch report ran afterwards: zero open/deferred/unreplayable questions,
  34 applied/17 stale/one conflicting answer (known 1112), one degraded attempt (known 936).
  `batch-final-after-backup.json`. Unchanged implementation gates remain valid; this receipt
  changes documentation only. **No canary acceptance, Tier A or hold release.**

The paragraphs below retain the initial pre-cut chronology; the cut/check receipt above is
the current result.

1250's all-readings ruling was recorded through the existing composition review at 12:57:59
UTC, by verified admin user 1. Recomposition remapped the earlier IDs: Job 29 73103→3128,
Job 30 3131→73104, Job 31 3132→4910, sermon 4910→73105. References and source bounds were
checked before recording; the old-ID request was safely refused because those IDs now name
different items. `ruling-receipt.json` records the remap and decision. Intervening items remain
excluded; the interruption flag is retained, so 1250 is still parked. No code or other answer
changed.

`TranscribeOutputEdges` completed for all sixteen on the Whisper queue: **153 windows checked,
152 reused, one newly decoded in 2.135 seconds**, zero no-word fallbacks, zero missing-cache
output blockers and zero failed jobs (`timing-summary.json`, `timings-receipt.json`). Queues
drained. Both custody diffs before extraction report zero attention runs, no lost live holds
and nothing public; they retain 13/14 runs pending extraction, with 25 section-media pointers
pending restoration in the latest diff. Reports: `diff-original-before-extraction.json` and
`diff-latest-before-extraction.json`. These do not pass final custody until media is restored.

The scorer reports zero talk-count errors. Its copied-run probe cannot read real-run-keyed
edge caches; `accuracy-direct.json` supplements every unscored cut with selected planned
spans and truth spans/alternatives. All sixteen planned sermon boundaries are within the ruled
tolerances; preparatory reading membership is reported separately. New-output timing scans,
in-cutter evidence, current-version/file-reuse checks and final custody remain pending cuts.
Baseline scans/score were preserved. 1050's source at PTS 920.321 has the same excess final-
frame gap (0.067 s) as the baseline cut (0.0666 s); source audio is regular in that window.

`listening-list.json` has 90 moved-edge/join entries; `joins.json` supplies raw sermon parts,
intervening readings, transcript lines and review URLs for the three parked sermons, and the
three joins of 1250's four selected parts. Full steps, source-tail evidence and log paths are
in [the cut redesign plan](CUT-FROM-SECTIONS-IN-SYNC-2026-10-02.md#authorized-canary-10-dispatch--2026-10-03-cut-dispatch-held-for-1311).
The bar has **not passed**; no canary acceptance, Tier A, Tier C, hold release or other answer
has been dispatched or recorded in this operation.
Both backups completed again with checksum comparison, exit zero and no deletions; the batch
report then ran (`batch-after-backup.json`). It reports 16 replayable runs, one open question,
33 applied, 17 stale and one conflicting ruling (the declared 1112 exception), with no
unreplayable bundle. `receipt.json` binds all evidence hashes and pending steps;
`open-question.json` includes 1311's source context and review URL. Cut dispatch still awaits
the operator's requested scope choice. No acceptance is claimed.

#### 4.0b Regular uploads are the lasting outcome

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
stored-output checks) is §4.0's tier shape. The 09-22 numbered workstream list moved to the
[log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md#moved-from-the-plan-on-2026-09-24); its open gaps are in §4.4, §4.5 and the
carried items below.

#### 4.1–4.3a Workstream status (condensed 2026-09-23)

The detailed workstream sections §4.0–§4.3a (canary, containment, §4.1a–§4.1d
censuses and rulings, transcript-loop and song-policy work, the class table and the
H1–H10b detector harness) moved **verbatim** to the
[execution log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md).
Cite it for evidence; do not copy histories back. The class-to-detector binding is
data, in `resources/detector-classes.json`. Section numbers §3.1, §4.0a, §4.1–§4.1d and
§4.3a, the §4.1b coverage matrix and the H-numbered harness steps refer to that log; this
plan's §4.0 is the corpus re-run, not the log's.

| Workstream | State on 2026-09-23 |
|---|---|
| §4.0a Repair canary | Done 09-17 (3/3). 1340 repaired 09-18. Held sermons park at extraction. Bounded retranscription of 980/1258/1343 done 09-20; 1287 re-transcribed and re-detected 09-23 (stale replay stamp superseded on dispatch, `ed92d48f2`). |
| §4.1 Containment | Missing holds from the 09-10/11 review contained. Assets stay quarantined until their text or cuts are recovered. |
| §4.1a/§4.1b Discovery censuses | Coverage censuses complete (residue, OoS order, titles, dates, sections, song loops, Scripture, consumer rendering, tails, video quality). The field coverage matrix is in the log. Human-only rows (end-to-end watching, real server/browser playback) remain open. |
| §4.1c/§4.1d Rulings, interior review | BC-06/BC-07 ruled 09-18 (§4684 moved to the talks plan). 18 interior windows and BC-05 replay done. |
| §4.2 Transcript loops | `max_context=0` fixes context drift; screen, sparse cadence (both-flank excuse) and recovery re-applied corpus-wide 09-21. Corpus re-decoding via H10b (below). Direct uploads: loops not reproduced on local Whisper. |
| §4.3 Song policy | Speech-edge trim, sustained-sound widening, 10 s introduction bridge (09-22), lyric edges, neighbour same-song rule (09-23) built. Existing outputs need re-detection and re-extraction through the pipeline. Hint/binding contradiction rule measured and not built (all genuine cases already held). |
| §4.3a Detector harness | Catalogue, five adapters, case book, replay and evaluation built. Last full evaluation (before the 09-23 detectors): 38 detectors, 5 fail, 33 not established, 0 accepted. 1341 fixed (bridge), 1337 fixed (neighbour rule), 1287 diagnosed as transcript loss (not a detector gap), 1109 below the intentional threshold. Recall via H10/H10b. |
| H10b Re-decode comparison | Built, controlled (C1/C2/C3) and run over 338 runs; decision rule applied unchanged (tripwire 2.20%). 99 restaged runs decoding as batch 2. **Listening done 2026-09-24** (352/353 judged); results and routing below and in §4.0. Details and the committed rule below. |

##### H10b, 1337 and 1287: live notes (verbatim from the 2026-09-22/23 follow-up)

- **H10b corpus preparation, 2026-09-22.**
  - **Membership census** (read-only, `h10b-membership-20260922.{php,json}`): of 455
    historic runs, **338 are decodable** (321.5 hours of audio). 117 are unassessable
    before decoding: 113 have lost their staged source (the pipeline's own
    `livestream/temp/…mkv` copy was cleaned after completion; 101 single-source, 12
    lossless concatenations, including 1003 and 1084), 7 are not completed, 3 have
    no recorded hash and 1 has no stored transcript. Restoring the 113 is a separate
    byte-identical restage decision and is not assumed here. Every decodable run has
    recorded screen blocks (none null).
  - **Strata**, recorded on every artifact and counted in the report: `original` 97,
    `recovered` 229 (stored text includes recovery or replay windows) and
    `already_redecoded` 12 (banked audio, decoded at `max_context=0`).
  - **Window labels** added test-first: each window carries the type of the section
    overlapping most of it, and its sustained-sound share from the run's RMS log
    (null when unreadable, never 0).
  - **Decision rule, committed before any corpus decode.** It is not revised after
    seeing results. Any change is a new, dated rule, and the earlier results stay
    reported under this one.
    1. *Floor.* A window **differs** when `token_distance` > 0.45, above C2's
       largest cross-process difference (0.44).
    2. *Abandon.* If more than 3% of `already_redecoded` windows differ, the floor is
       wrong. Stop and report; do not reinterpret.
    3. *Side labels.* A side is **repetitive** when its bigram redundancy is ≥ 0.20,
       otherwise **clean**. C3's screened windows ran 0.18–0.96 stored and ≤ 0.19 new.
    4. *Classes.* Each differing window takes one of the four classes in the H10b
       table from its two side labels. It is counted by stratum, never pooled across
       strata.
    5. *Speech only for listening.* Only windows whose section type is not `song`
       and whose sustained share is < 0.5 enter the listening queue. The rest are
       counted and not adjudicated: that is where the cross-process residue lives.
    6. *Listening queue* (the operator's ears; the order below is the priority):
       (a) every **uncovered repetitive/clean** window (stored screen overlap false):
       each is a candidate screen miss; (b) up to 30 **clean/clean** windows, random
       with a recorded seed: the substitution class with no detector; (c) up to 10
       **clean/repetitive**: candidate regressions in the new decode; (d) 20 random
       **covered repetitive/clean** windows for precision. `recovered`-stratum
       windows are listed separately from `original`.
    7. *Reporting.* Report counts per class and stratum with denominators, the
       number of listened minutes, and the unassessable 117. No recall figure is
       stated from counts alone. Adoption of any new decode stays per run, through
       the tested path.
  - **Corpus decode started 2026-09-22** over the frozen 338
    (`storage/scratch/h10b-corpus-20260922/membership.txt`). It runs at about
    4 min/run because each source is hashed and compressed over the staging link, so
    roughly 20 h unattended.
  - **Restage census for the 113 missing sources** (read-only,
    `h10b-restage-census-20260922.json`, `h10b-restage-hashes-20260922.json`). Most
    recorded source paths are relative to the Sonnics services root, a separate drive
    from Staging, so the originals could be hashed during the decode without
    contention. Results:
    - **86 byte-identical on Sonnics** (91 files, 50.4 GB, every SHA-256 equal to the
      recorded one): 78 original and 7 recovered are clear to restage. 959's original
      had moved to the drive root, with the same size and mtime, and hashes equal.
    - 2 are otherwise blocked (one has no recorded hash, one is not completed).
    - **25 originals are on the Staging drive**, with size and mtime matching; they are
      hashed only after the decode, to keep the faulty link to one reader. 21 are
      clear and 4 are blocked (not completed). 10 of the 25 are lossless
      concatenations, whose recorded run hash is of the assembled file, so a restage
      must reproduce the assembly byte for byte or stay unassessable.
    - **943 is lost:** its five segment files were replaced on 09-01 by a single,
      different `Sunday 28th July 2024.mp4`. Adopting that file is the existing
      source-adoption question, not a restage.
    - Net: up to **106** more decodable runs (about 91 original), which nearly doubles
      the `original` stratum. The restage itself waits until the decode finishes.
  - **Corpus decode complete 2026-09-23: 338/338 decoded, 0 unassessable.** The first
    comparison refused 69 runs whose durations differed. 30 were under 0.5 s (float and
    rounding), and 39 ran 0.5–29.8 s, **every one with the stored side longer**, because
    the stored decode stamped its last cue out to the padded final 30 s window (1007:
    last cue 4811.98 s against 4785.73 s of audio). That is an assessability rule, not
    the decision rule, and it was fixed test-first: the pair is compared over the shared
    span using the transcript's own final-window clipping, the overrun is recorded, and
    pairs 30 s or more apart stay unassessable. Re-compared: **338/338 assessable, 64
    overruns recorded** (`comparison-v2.json`).
  - **Decision rule applied unchanged** (`h10b-apply-rule-20260923.py`, seed 20260923):
    - Tripwire: 34/1,548 `already_redecoded` windows differ = **2.20%**, under the 3%
      abandon line. The floor holds, though it sits above the pilot's ~1%.
    - Differing windows by class (stored/new), `original` (11,124 windows):
      clean/clean 596, repetitive/clean 618, repetitive/repetitive 119,
      clean/repetitive 53. `recovered` (26,063): 2,413 / 1,086 / 217 / 324.
    - Speech-only (not a song section, sustained share < 0.5; no window had an unknown
      share), repetitive/clean: `original` 62 covered by the stored screen, **19 not**;
      `recovered` 103 covered, **69 not**. These are candidates, not adjudicated misses,
      and no recall is stated from them.
    - Listening queue (`listening-queue.json`): `original` 19 + 30 + 10 + 20 = 79
      windows, `recovered` 69 + 30 + 10 + 20 = 129, about 104 minutes of audio.
      Listening is the operator's.
  - **Batch 2 (restaged runs), 2026-09-23: 99/99 decoded and compared, 0 unassessable.**
    Same rule, same seed (`listening-queue-batch2.json`). It has no `already_redecoded`
    runs, so the batch-1 tripwire stands. These are the runs with no review obligation
    when they completed, and they differ far less: `original` 132/4,765 windows differ
    (about 3%, against about 12% in batch 1); `recovered` 265/1,472. Speech
    repetitive/clean windows the screen did **not** flag: 10 `original` and 2
    `recovered`, which are candidate misses on never-held runs. The queue adds 98
    windows; the listening page now holds **306** (`listening/index.html`, batch
    shown). `score-verdicts.py` reports by batch.
  - **Batch 3 (the nine concatenated runs), 2026-09-24: 9/9 decoded and compared, 0
    unassessable.** They could be decoded only once the concatenation gate had restaged
    them and stamped their hash. Same rule, same seed (`listening-queue-batch3.json`,
    `comparison-batch3.json`); no `already_redecoded` runs, so the batch-1 tripwire stands.
    `original` 71/672 windows differ (about 11%, like batch 1 rather than batch 2) and
    `recovered` 21/156. Speech repetitive/clean windows the screen did **not** flag: 4
    `original`, on **942** (750 s `other`, 1470 s sermon) and **975** (150 s notices,
    660 s reading). These are candidate misses. The queue adds 47 windows, and every run
    gets at least one, because a small batch samples all its clean/clean candidates. The
    listening page now holds **353**, with clips cut from the restaged sources.
  - **Listening, 2026-09-24: 352 of 353 windows judged, about 235 minutes** (one blank).
    **Button meanings as the operator used them** (confirmed against the page's text): button 1,
    *"Stored text is a loop / wrong; new is right"* (`stored_loop`) = stored wrong, new right;
    button 2, *"Stored text is right (real repetition or speech)"* (`real_speech`) = new wrong,
    stored right; *"Both wrong"* and *"Can't tell"* as labelled; *"New decode is wrong"* was not
    used. Scored under step 7 with `real_speech` read as `new_wrong`
    (`listening/verdicts-20260924-operator-mapping.json`, each entry keeping `as_pressed`).
    Counts, all strata and batches together (per-stratum counts in `verdict-score.json`):

    | Group | Stored wrong, new right | New wrong, stored right | Both wrong | Can't tell |
    |---|---|---|---|---|
    | (a) uncovered repetitive/clean | 81 | 2 | 20 | 0 |
    | (b) clean/clean random sample | 83 | 31 | 20 | 3 |
    | (c) clean/repetitive | 6 | 25 | 5 | 0 |
    | (d) covered repetitive/clean | 70 | 0 | 6 | 0 |

    Read per step 7, no recall stated: candidate screen misses are mostly real (81 of 103
    judged in (a)); the screen is precise where it flags (70 of 76 in (d)); the class no
    detector sees is common (stored text wrong in 103 of 134 judged in (b)); and the new
    decode does regress (30 of 36 confirmed in (c), 58 windows overall), so adoption stays
    per run. Routing per run is in §4.0, decision 2. Future listening pages ask "which text
    matches the audio?" rather than naming a verdict.
- **1337 follow-up, 2026-09-23.**
  - **Neighbour rule built test-first** (`2185ab734`). `adjacent_same_song` now also
    holds a song whose previous or next section is the same song with nothing between,
    whatever the gap. A reprise after another section is still released. A corpus
    census found this adds exactly one pair, 1337 §4274→§4275 (12 s apart); the 2 s
    rule already caught the other 4.
  - **Hint/binding contradiction rule measured, not built.** §4275 was confirmed by
    **OCR** (confidence 1), not by the transcript, and its transcript also carries a
    refrain of the bound #699, so a flat rule would overrule independent evidence. A
    census over 1,191 confirmed song sections with a hint
    (`storage/scratch/hint-binding-census-20260923.json`) found 39 whose hint matches
    neither the bound song's titles nor its lyrics. Most are modernised wording
    (Thou/You, Ye/You, 'Tis/Yes) or junk hints ("Let", "again", "number 426"). The
    genuine contradictions (1337 §4275, 1144 §2163, 1215 §2638/§2639/§2641, 1223 §2747,
    1174 §2331, 1297 §3802, 1120 §1998) are **all already held**. The rule would add no
    new hold and would hold correct bindings over wording, so it is not built.
    Revisit only if a genuine contradiction turns up unheld.
- **1287 diagnosed 2026-09-23 (read-only; `storage/scratch/run-1287-probe-20260923.php`).**
  The H10b re-decode (`max_context=0`) hears what the stored transcript did not. Song 1,
  #797 "Praise the Lord you heavens", runs 189–268 s. A **spoken reading** (Revelation
  4:9–11) runs 268–299 s. **Song 2**, #934 "There is a higher throne", runs **304–452 s**,
  where the stored transcript has an unobservable window at 299–437 s
  (`retranscription_failed`). The detector was told of two songs but could see only one,
  so it split song 1 across §3596 (180–232) and §3597 (232–299). §3597 took song 2's
  binding and absorbed the reading, and song 2 got no section. **Cause: context-drift
  transcript loss, which `max_context=0` already fixes. Not a detector gap.** The lyric
  check already caught it (§3597 held since 09-14), and the case-book entry under
  `structure-song-widened-to-sustained-sound` is mis-filed. Its widening failure is a
  consequence (20 s unsustained, the reading, before song 2), and no widening rule should
  be bent to reach it. **Repair:** re-transcribe, then re-detect, through the pipeline in
  the next bounded batch. Its staged source is present (it was in the first decode batch),
  but the retranscription command is still restricted to 980/1258/1343. *(1287 was added
  and repaired on 09-23, below.)*


##### 1287 repair result and the hold carry-through ruling — 2026-09-23

**1287 retranscribed and re-detected** (operator dispatch, completed 18:11). The transcript
is whole (no unobservable windows, no suspect blocks). Song 1 (190–277 s) and song 2
(304–458 s) are separate sections, the Revelation 4 passage is a `bible_reading`, and the
replay stamp moved to history. Clips 581–583 are `quarantined` on `historic_quarantine`,
so nothing is exposed.

**Two defects found by the post-run check:**
1. **Content holds follow `section_order`, not content.** Re-detection inserted two sections,
   so the 09-14 "wrong song" hold now sits on §3597 (the reading) as well as §3596, and
   both describe the defect the repair fixed.
2. **Derived review flags were re-derived and cleared.** "The Lord's My Shepherd", "Your
   Word" and "Who Can Cheer" went to `published` section state. That is correct for
   derived flags: they are policy output, and re-deriving is their purpose.

**How the 181 content holds were made** (census 2026-09-23): none records a person
listening. They came from mechanical checks run outside the pipeline, mostly in agent
census sessions with operator approval: loop/repetition screens 75, lyric comparisons 68,
fresh Whisper source decodes 12, frames or duration 4. 65 name no method; they are
decisions (duplicate-performance identity) or judgements (wrong title or passage,
semantic substitution). A hold records only `reason`, `held_at` and `evidence`.

**Operator ruling, 2026-09-23: holds follow content and are re-checked by the check that made them.**
1. A content hold follows its content (time span and bound item) through a re-run, never
   the section's position.
2. Each hold records **which check found it**.
3. After a repair, a hold whose check exists in code is re-run automatically. It clears if
   the check passes, and the record says why.
4. Decision and judgement holds stay until someone re-decides them.
5. Derived review flags keep re-deriving freely.

Until this is built, a batch that inserts or removes sections **misplaces content holds**.
Build it test-first before the next bounded batch larger than one run. For 1287 the two
stale holds are lyric-comparison holds, so they would clear on re-check; they are left in
place, not lifted by hand.

**Built 2026-09-23.** Every hold record carries `found_by` (`ContentHoldCheck`: loop screen,
lyric comparison, source audio, media measurement, boundary, judgement, decision), the span
and item it was found on, and the fingerprint of the transcript its check read. Section sync
carries records, live or released, by type and span overlap, so a row keeps nothing for
content it no longer covers. `ContentHoldRechecker` runs after every structure-detection sync
(and via `service:recheck-content-holds`). It re-runs loop-screen and lyric-comparison holds
only when the transcript has been rewritten since the hold, and records why each record
cleared or stayed. `service:backfill-content-hold-checks` classified the existing 181 by
reason: loop 73, lyric 29, source audio 22, boundary 22, decision 14, media 11, judgement 7.
It dropped three records left behind on rows that never carried them (§1016 run 980, §3227
run 1258, §3597 run 1287), and marked 12 holds raised before a later re-decode as superseded.
**Local re-check result:** loop holds on §3858 (1303) and §3985 (1314) cleared, because the
screen finds no loop in the re-decoded transcript. 1287's lyric hold on §3596 **stays**: the
sung words now match #797, the song the hold said was really sung, but item 11981 binds no
song, so identity is still unestablished. That needs a binding decision, not a hold lift.

**Review fixes, 2026-09-24** (`0beb1f996`…`1a5b17019`). A critical review of the 09-22/23
commits found holds that could clear without a repair, and song-matching side effects:
- A re-raised record now takes the current fingerprint and check; before, the next re-check
  compared the stale one and cleared the operator's re-hold again.
- A lyric comparison re-runs when the **binding** changes as well as the transcript, and after
  song matching, not only after detection. So §3596 now re-checks itself once 1287's binding
  decision is made; no by-hand lift is needed. It clears only on the census scorer's positive
  reading (`SongLyricIdentityCheck::confirms()`: the bound song leads with ≥4 pairs).
- **34 inferred checks corrected** by re-running `service:backfill-content-hold-checks`: 33
  short-loop and cadence holds (below the repetition screen's floors, confirmed against the
  source) and 1337 §4275 (its song, Shine Your Light, is not in the catalogue, so no lyric test
  can see it; the re-check would have cleared it) are now `source_audio`. No clearance needed
  revoking: the §3858/§3985 clears were song-loop holds the screen can re-test.
- `service:recheck-content-holds --execute` then cleared **§519 (run 929)**: its OpenLP item now
  binds #295, the announced song, which its sung words confirm with 26 pairs. 27 records stay.
- Bound single-source songs are no longer flagged `unmatched_song_section` (or, if
  speech-classified, retyped to `other`) when OCR finds nothing. `planned` counts only an item
  at the section's own place in the plan (none of 1,082 confirmed bindings relied on another).
- Released hold history for content a re-detection drops is kept on the run
  (`unplaced_content_hold_records`).
- `structure:recompute-sound-stage` now replays `SongSpeechEdges`, so it reports
  `structure_song_swallows_speech`.

**The 09-23 detectors, checked read-only on 09-24.** `detectors:evaluate` (case book frozen as
`storage/scratch/detector-case-book-20260924.json`) scores every new detector's regression
cases `missed`, because no stored run carries their output yet; that is expected before the
re-run. Replayed from banked inputs instead, each fires on every regression case:
sung-span-in-sermon 949/1014, song-swallows-speech 974/1036, speech-under-loop
§4731/§1082/§4390, section-without-song §622/§2851/§3368 (not §678, the doxology, as ruled), and
adjacent-same-song 1337 §4274/§4275. `song-identity-single-source` and
`song-identity-contradicted-by-lyrics` have **no case-book entries**; they need adjudicated
cases before they can be scored. Song boundary evidence went v5→v7 in one evening, so run the
boundary-evidence backfill **once, after the freeze** (v7 today; a gate item that touches song
boundaries would bump it).

##### Carried open items

Every box still unticked in the moved sections, grouped by the section it came from. Most
items are the first line of the original; its scope, populations and acceptance are in the
[log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md) at the line cited, which is frozen. The coverage matrix, the "not a defect"
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

#### 4.4 Bind deferred identity disputes

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
- [ ] Resolve source adoption for pairs 873/1045, 969/1307 and 1296/1297 without
  deleting the better master or substituting media beneath existing timings.
  **Ruled 2026-10-14 (operator, on recommendation): keep the real service, use the better
  file's run, set the poorer run aside.** Built and committed the same day (`12b16f5e1`):
  `historic-import:correct-run-identity --operation --processing-id --date --service --duplicate
  --note [--apply --yes]` and the new exclusion reason `duplicate_recording`, whose kept run may
  belong to another operation. With it, an excluded run never wins supersession. Dry runs on all
  three pairs read as the table below. **Not applied.**

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
    [FOLLOW-UP-ROUND §12](FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md), "Pre-dispatch review".
- [ ] Update exact release membership only after each identity decision is
  evidence-bound.

#### 4.5 Prove acceptance, then converge and release

- [x] Give runs 1004, 1143 and 1145 terminal dispositions (repair, exclude with
  reason, or an explicit accepted hold).
  - **Diagnosed read-only 2026-09-19 against the live local Sail database and
    mounted historic volume.** The exact three-key pass report is one `failed`
    and two `manual_review`, with zero open runs and zero queued historic jobs.
    Operation 4 remains `planned` and owns **zero** durable checkpoint rows.
  - **1004 (`2026-04-26-evening`) requires a fresh full-source transcription,
    not another recovery replay.** Both banked attempts were rejected by the
    pathology detector; the recorded replay is 0 words before and after across
    4,317 blind seconds. The current `service_transcript_path` points at the
    resulting empty region-recovered artifact, and neither it, the prior
    normalized artifact nor the temporary staged source now exists on the mounted
    work volume. The archive source remains hash-bound in the manifest. A bounded
    repair must therefore restage that exact source, transcribe it afresh with the
    current decoding/recovery code, and only then decide repair versus an accepted
    hold. Ordinary retry and structure redetection are correctly refused and must
    not be used to spend again on the same empty evidence.
    **Repair dispatched 2026-09-19.** The exact archive source is present and the
    run's context-bound staged source was already restored. A red-first regression
    proved that retry incorrectly resumed at structure detection when its recorded
    transcript existed but held no cues; retry now rewinds to
    `transcribe_full_service` in that exact case. All historic workers were
    gracefully restarted while queues were empty, and run 1004 is now in progress
    on `historic-whisper`. The full-manifest command independently refused a newly
    present unmanifested `2020-04-12` file; the already operation-bound run was
    therefore resumed through `UnifiedMediaProcessor::retry()` rather than weakening
    the corpus guard or using `--force`.
    **Completed 2026-09-19.** The fresh pass cleared transcription, structure,
    extraction and the remaining pipeline; the final database-owned disposition
    is `completed`, with zero historic jobs or open runs. The two earlier failure
    alerts remain retained history and the pass now also records success.
  - **1143 (`2024-08-11-morning`) is an evidence decision, not a retry.** Its
    recovered transcript still has 5,980 words; its persisted structure contains
    16 coherent sections and a 1,216-second sermon (§2157, 2203–3419, Luke
    12:49–53). RMS also contains two speech blocks over 20 minutes (410–1964 and
    2146–3656), so the baseline selector truthfully refused to choose between
    them. The structure's sermon is held for `structure_low_confidence`; an
    ordinary manifest retry deliberately leaves manual-review runs alone. The
    legitimate outcomes are an operator-approved section-bound extraction, checked
    against source audio, or an explicit accepted content hold. Selecting the
    whole second RMS block would include material outside the detected sermon and
    is not an evidence-backed shortcut.
    **Accepted hold retained 2026-09-19.** Source-timed evidence fixes the proposed
    end: prayer ends at 3419 and the final hymn follows immediately. It does not
    fix the start: 1963.74–2203.58 is explicitly unobservable and the next roughly
    630 seconds are pathological 30-second transcript chunks before coherent sermon
    text resumes. The existing manual-review hold remains the honest terminal
    disposition; no extraction or publication was approved.
  - **1145 (`2024-08-08-morning`) fits the existing recording-level exclusion.**
    Its 1,014-second capture contains two songs, a 706-second children's programme
    and a closing notice, with no sermon. A dry run of
    `historic-import:exclude-run --reason=no_sermon_in_source` changes the pass
    disposition from `manual_review` to `excluded` and keeps service 1030; no state
    was written. Applying it remains an operator factual ruling because the
    exclusion is terminal.
    **Applied 2026-09-19.** Run 1145 is now terminally excluded with
    `no_sermon_in_source`; service 1030 was kept. The exact pass report confirms
    the disposition is `excluded` and records the explanatory note.
- [x] **Build and apply the occasion exclusions ruled 2026-09-14** (Saturday
  rehearsals 1043, 1089; funerals 1051, 1098). **The following is the original
  14 September diagnosis, resolved by the 15 September implementation below:**
  at that time nothing could execute them:
  `HistoricRunExclusion::OPERATOR_REASONS` holds only `no_sermon_in_source`, its D1
  docblock forbids excluding a real service that way, and exclusion is read only by
  `HistoricVideoPassStatus`. Neither release (`ReleaseHistoricImportBatchCommand`
  refuses holds, not exclusions) nor `ChurchServiceCorpusMembership` consults it, so
  an excluded run's rows would still be released. Work, test first:
  - Add two operator reasons, one for a rehearsal that duplicates another run's
    sermon (recording the kept run: 1042, 1088) and one for a private occasion.
    Update the D1 docblock to say these differ from "this service held no sermon".
  - Make exclusion reach the rows the run already created. Sermons 977, 1016, 985 and
    1025 and song videos 211, 231 and 232 must be refused by release and absent from
    exact membership, the hymn usage artifact, search, sitemap and feeds. Either the
    exclusion withdraws them, or release refuses any row whose run is excluded; choose
    one and test it at both the release command and the membership builder.
  - Decide the service rows. The rehearsals' Saturday services (1019, 1021) were
    manufactured by the import and have no other source, so withdraw them with their
    runs. The funeral services (1020, 1022) are real occasions: keep or withdraw them
    as an explicit decision, and never show them on a public service page either way.
  - Apply through the command with an operator note citing this ruling; confirm with a
    dry run first, and record the release gate before and after, as for holds.
  - Acceptance: a feature test per reason proves the rows cannot be released; the
    gate after application refuses all four runs for their exclusion; the Sunday
    sermons 976 and 1015 are unaffected.
  - **Built test-first 2026-09-15 (`8585de074`, `61ae5b34f`) and applied.** Two operator reasons,
    `rehearsal_duplicate` and `private_occasion`. A rehearsal must name its kept run with
    `--duplicates`, and that run must be a different run of the same operation that is
    not itself excluded. *Chosen: release refuses, nothing is withdrawn.*
    `HistoricReleaseReviewHolds` refuses any sermon whose run is excluded, and any song
    video whose section's run is excluded, for every exclusion reason. The dry run and
    live release both go through it. Search, sitemap, feeds and hymn usage only read
    published rows, and none of the four services has a song usage report. There is no
    membership builder to change: the signed authorisation is the membership, and the
    gate refuses it. The tests cover each reason, the dry run, a live release, a song
    video on its own, and the kept sermon releasing once the excluded record is dropped.
  - **Gate before application** (`storage/scratch/exclusion-20260915-gate-before.json`):
    of the seven rows, only sermons 1016 and 1025 and song video 231 are refused, and only
    for unrelated review holds. **Sermons 977 and 985 and song videos 211 and 232 are
    releasable today.** Sunday sermons 976 and 1015 carry no refusal.
  - **Service rows: removed (operator ruling 2026-09-15).** Neither a funeral nor a
    rehearsal would have been uploaded had the week been processed by hand, so both
    reasons also delete the service the run created. All four services (1019, 1020, 1021,
    1022) were made by the import on 2026-09-04 from their run's livestream source record
    alone. Removal is refused when another source or another run describes the service.
    `no_sermon_in_source` keeps its service (D1). The evidence records a snapshot of the
    removed service. An excluded run no longer projects, so a retry or re-detection
    cannot bring its service back.
  - **Applied 2026-09-15** after dry runs naming each service. The removed rows are saved
    in `storage/scratch/exclusion-20260915-removed-services-snapshot.json` (sha256
    `0f195069…3ff59`): 4 services, 29 items, 6 source records, 38 assertions, 2 merge
    proposals, and the links on 21 sections, 4 runs and 3 song videos. After application no
    row of the four services remains. Services 706 and 654 and runs 1042 and 1088 are
    unchanged.
  - **Gate after** (`exclusion-20260915-gate-after.json`): all seven rows are refused as
    excluded (sermons 977, 985, 1016, 1025; song videos 211, 231, 232), and 1016, 1025 and
    231 still carry their review holds. Sunday sermons 976 and 1015 carry no refusal.
  - *Worker code:* the projection guard reaches the queue workers only after they restart.
    No projection is due for these runs, which are completed and terminal.
- [x] Design how operation 4 reaches `Complete` (§3.1 item 5) now, before
  convergence work depends on it, without manufacturing checkpoint or closeout state.
  **Designed 2026-09-19; implementation and evidence review remain open.** The
  video command bound every dispatch to operation, manifest and plan, but never
  called `HistoricImportCheckpointPlanner` or `HistoricImportCheckpointRuntime`.
  Its `--only` option was a bounded manifest selector, not a durable checkpoint.
  Retrofitting 412 post-hoc checkpoint memberships, dispatch events, source
  snapshots and item outcomes would assert history that did not happen.

  The programme authority already resolves this seam: the incremental convergence
  plan §4 lapses checkpoint exactness and HIR4/HIR5 ceremony, and §7.3 replaces
  exact closeout with a reviewed, hash-bound round evidence pack. Operation 4 uses
  that contract:
  1. finish the three dispositions above and freeze exact manifest membership;
  2. assemble, rather than reimplement, the existing video status report, manifest
     expectation, combined membership/census, asset audit, Scripture settlement,
     notification/nested-job ledger and cost/duration output; hash each and add a
     short cover binding operation id, commit, target fingerprint, manifest hash,
     plan hash, backup receipt and every non-zero residue;
  3. require every manifest identity to be completed, approved-excluded, or an
     explicitly accepted hold with a non-empty reason and evidence reference;
     refuse missing/extra membership, changed input, degraded or unexplained failed
     work, unowned assets, external historic notifications and unsettled nested jobs;
  4. add one narrow video-round verifier/transition, not a general audit framework.
     It accepts operation 4's genuine `planned`/zero-checkpoint history plus the
     reviewed §7.3 pack, persists its digest and journal event atomically, and
     transitions through an explicit round-evidence closeout state to `Complete`.
     The legacy checkpoint closeout remains unchanged for operations that actually
     used it; release keeps its hard `Complete` requirement, with wording updated
     to mean the operation's applicable verified completion contract;
  5. prove fail-closed behaviour for every refusal above, digest/binding drift,
     idempotence and release-before/after completion. Only after those tests and the
     maintainer's pack review may the transition be applied.

  This is design authority, not a completed operation: no checkpoint, outcome,
  journal, operation state or release row was created or changed in this review.
  **Verifier core implemented 2026-09-19; transition still awaits the real pack.**
  `HistoricVideoRoundEvidence` now verifies an HMAC-signed cover against operation,
  target, runtime, manifest and plan bindings; a fixed seven-report allowlist and
  each report's live SHA-256; exact unique item dispositions; and explained non-zero
  residue. `round_closeout_required` is an explicit state that can only advance to
  completion or reconciliation, and both production approval and historic-tail
  recovery treat it as a no-new-work barrier. Focused tests are green. No operation
  state was changed: the report set and reviewed cover do not yet exist.

  **First real-pack review 2026-09-19: correctly refused; Operation 4 remains
  `planned`.** The binding resolves to the 1 September manifest and plan
  (`d25d2085…` / `9351fa4e…`, 464 included and 10 manifest exclusions), not the
  older 26 August files. Against that exact membership the database-owned status
  report is 406 `completed`, five `excluded`, one `manual_review`, 48
  `not_dispatched` and four `retired`, with zero open runs and zero queued historic
  jobs. The manual-review member is the explicitly accepted 2024-08-11 hold; the
  52 not-dispatched/retired members still require the combined membership report
  to prove their exact prior/present disposition rather than relabelling them in
  the cover.

  **Membership and retained-asset follow-up 2026-09-19.** The exact combined
  membership report now resolves 455 identities as complete and 15 as excluded,
  leaving four explicit unresolved identities: 2023-02-26 morning, 2024-05-05
  morning, the already accepted 2024-08-11 morning hold, and 2024-11-03 morning.
  In particular, 45 apparent `not_dispatched` identities are completed by their
  pre-existing live runs, while the four operation runs superseded during service
  reconciliation still prove their own source consumption; neither class is
  relabelled as an operation completion.

  The old asset failures were then reproduced and separated from real custody
  loss. `audit:historic-import-assets` had forgotten each run's recorded batch
  root and resolved promoted sermon and thumbnail paths through current global
  disks. Regression-tested corrections now re-enter the run context and resolve
  every sermon asset through `asset_disk`. Pass 1 now verifies 48/48 assets, step
  11 verifies 18/18, the WebM proof verifies 5/5, and Phase 8 verifies all 1,926
  auditable assets. The two Phase 8 incomplete runs are the 2023-02-26 and
  2024-05-05 manual-review identities. One genuine stale reference remained:
  retrying the older 2026-06-28 run on 10 September had overwritten quarantined
  sermon 857's audio pointer with a path on another custody disk. The retained
  replacement MP3 was already present and independently probed; a guarded one-row
  repair restored that reference, and `SermonCreationService` now refuses future
  cross-custody media replacement (47 service tests green, PHPStan clean).

  No cover was created, reviewed or signed, and Operation 4 remains `planned`.
  The asset evidence is clean for every completed dispatch, but the pack cannot
  claim exact closure until the three unaccepted manual-review identities receive
  dispositions and the accepted 2024-08-11 hold is represented explicitly. Then
  produce the consolidated operation-wide asset report, assemble the Scripture,
  ledger and cost/duration reports, and review every non-zero residue.

  **The three manual-review identities were adjudicated on 2026-09-19 and all
  three are accepted holds, not exclusions.** The operation-bound review artifact
  is `storage/scratch/operation-4-round-evidence/manual-review-dispositions.json`
  (SHA-256 `1f3b8eb44e2620f1069f347728436db5d9a7f551fbe013a949a9907592923822`).
  For 2023-02-26, the retained proposal has an evident decimal-place timestamp
  defect (`41410s` against a `4407.681451s` source) plus a 4.9-second overlap, but
  identifies a plausible sermon and the source survives. For 2024-05-05, the
  proposal identifies a plausible 1,642.81-second sermon but incorrectly binds an
  explicitly unplanned Psalm reading into the OoS order; its source also survives.
  Neither produced projected sections or a sermon, so both remain recoverable
  structure/extraction holds. For 2024-11-03, sermon 865 and ten projected
  sections survive, including a high-confidence 1,548.98-second sermon, but a
  later proposal duplicated OoS item 4079 and left the run failed. The existing
  reconciler would resume it, but doing that before settling the duplicate claim
  and storage ownership risks replacing media references; it therefore remains
  held rather than being relabelled complete. No media, run, section, sermon or
  operation state was changed by this adjudication.

  **Seven-report set assembled 2026-09-19; unsigned.** The private files under
  `storage/scratch/operation-4-round-evidence/` now fill every fixed report slot:
  video status `e9ec0d68…`, manifest expectation `f175ffcf…`, membership census
  `ebd786b0…`, asset audit `39808a23…`, Scripture settlement `6f7b727c…`, operation
  ledger `a732df07…`, and cost/duration `8ba42bcc…` (full SHA-256 values are the
  reviewed file digests used by the future cover). The asset report records 1,997
  verified references and zero missing; Scripture records sermons 1005 and 1305
  as contained holds with zero public exposure. The ledger records 835 completed
  nested jobs and three failed-but-settled publication attempts; all three owning
  runs later completed and carry success alerts, while live jobs and external
  notifications are zero. The performance report covers 416 operation runs and
  explicitly records 68 runs without timing evidence and two with retries.

  These are explained residues, not invented zeroes. The raw membership census
  deliberately still calls the four manual-review identities unresolved because
  it reports database observation; the cover's exact item dispositions must bind
  those four to the separately hashed accepted-hold evidence rather than rewriting
  the census after the fact. No signed cover exists yet, and no operation state
  changed. Next: generate the exact 474-item cover, review every disposition and
  residue reference, sign it, run the verifier, then—and only then—apply the
  round-evidence closeout transition.

  **Cover preflight 2026-09-19 stopped at two genuine external bindings.** No
  Operation 4 backup receipt exists in the repository or retained operation
  artifacts, and `HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY` is not configured in the
  current runtime. Neither value can be inferred or replaced with a placeholder.
  While checking that boundary, the verifier was found to validate uniqueness but
  not equality with the manifest. It now parses the digested manifest-expectation
  report, verifies its manifest/plan binding, and refuses any missing, extra or
  duplicated cover identity; focused tests (30 assertions), PHPStan and Pint are
  clean. No cover was generated or signed. Resume only after the maintainer names
  the real backup receipt and configures the signing key without exposing it.
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

### 5. Go/no-go

| Gate | State | Required evidence to turn green |
|---|---|---|
| Processing | GO | Definitive passes drained; the three former failures have recorded terminal dispositions in §4.5 (19 September). This is dated execution evidence, not a new live census. |
| Queued repair readiness | **PARKED — shutdown record 2026-10-04; not a fresh live census** | Tier C was dispatched and then stopped: 15 pending FFmpeg jobs, one pending orchestration job; 1108/1112 failed extraction amid staging-mount problems. Preserve those payloads and investigate stable mounts/failures before deciding how to resume the existing chains on `fix-historic-video-custody` at the frozen revision or a doc-only descendant. Do not redispatch the round. See the cut-from-sections plan's final shutdown/resume record. Canary acceptance and later batches remain pending; output-changing fixes use the next freeze/canary. |
| Containment | **NO-GO** | The six disputed sermons and their seven song videos were held on 2026-09-16 and the sections those holds left published were demoted the same hour (§4.4), so the identity gate-clear gap is closed and published-while-held is zero again. Remaining: the current-policy and unassessable residue. Containment is not adoption — the three pairs are still undecided, and the holds are what make deferring them safe. |
| Content acceptance | **NO-GO** | The strengthened stopping rule (carried items, "Rulings and stopping rule") passes: scoped coverage and limitations, omission reconciliation, independent source evidence, content handoffs, controlled variations/interruption tests, tail and whole-output reviews. Every §4.3a class has a tested response or recorded decision; detector errors and review burden are evaluated against predeclared criteria using H9/H10's retrospective, source-adjudicated evidence, with measured units and limitations explicit. There is no reserved historic set; insufficient evidence is not a pass, and H10b disagreement counts are not complete recall. The fresh release-membership sample includes repaired/held runs and meets its separate predeclared limits. Evidence is bound to current artifacts; operator rulings are recorded. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks are scheduled within the authorised release's rollback window and must pass to close observation. |

<a id="correctness-review-2026-09-10-followup"></a>

#### Follow-up correctness review — 2026-09-10/11

Moved to the [execution log](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md); its open actions are listed under "Carried open items" above.

#### Canary 10 four-defect repair and same-sixteen re-run — 2026-10-03

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


#### Canary 10 four-defect continuation — stopped on run 964, 2026-10-03

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


#### Canary 10 projection-provenance ruling and repair — 2026-10-03

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

**Exact-file assessment and stitch-vote repair — 2026-10-03 (operator-authorised).**
The stopped round at `09c5bce3b` is retained as evidence under
`storage/app/private/canary10-provenance-rerun-20261003/`; its seven old-file verdicts
remain invalid evidence for those new cuts. The authorised replacement round is recorded
under `storage/app/private/canary10-exact-file-rerun-20261003/`.

Storage now records each fresh sermon or section video as `media_outputs`, with disk,
path, byte count and SHA-256 bound to the run, dispatch round, ensemble projection,
ordered selected spans and media-processing signature. Assessment verifies that identity
before and after grading and writes the exact graded identity beside its verdict. Missing
records/files and changed size, hash or provenance refuse assessment with a specific
reason; neither replacement markers nor a prior sermon copy select the file. The real
store → assess regression includes the marker-consuming metadata integration and proves
that the fresh staging bytes are graded while a stale quarantine copy exists. Identity
mismatch and producer regressions were also run red before their repairs. Provenance
normalises object keys while preserving span order; a reversed-span regression caught and
corrected list sorting during this repair.

Fix A keeps `structure_sermon_interruption_merged` only when more than half of a composed
section's supporting drafts carried it. The real 1050 slots 1 and 3 (two of four) no longer
retain it; three of four do. All other flags retain the union rule, including content holds.
The detection prompt and the three recorded operator confirmations are unchanged. Their
six passage decisions remain in
`storage/scratch/stitched-sermon-listening-20261003/saved/rulings/`, with the prior
`confirm-stitches-receipt.json` preserved in this round. MediaProcessingVersion remains 6:
these repairs bind assessment evidence and change review voting, without changing the
paired sound/picture cutter recipe.

Focused checks pass (129 tests, 489 assertions), PHPStan reports zero errors and Pint
passes. The full parallel gate passes: 9,193 tests / 94,493 assertions, with 163 PHPUnit
notices and no failure (7m 45.818s). Every gate ran with all workers stopped. Exact recovery
is prepared but not executed: 15 pending and one reserved payload match the preserved
bytes, reserved score and exact sixteen-run membership. No payload, file, hold, answer or
operator confirmation has been removed or changed during this preparation.


**Exact recovery and worker/snapshot preflight completed, 21:32 UTC.** Code commit
`eb95fcd4227b024f4c6a6327f7cb6579a89ad960` binds revision
`655e2ed6494ee285a9264c706d4c6a92d289d04765dc2576cdadaf7ef00daae5`.
At 21:26:11 UTC the recovery retired precisely the preserved 15 pending and one reserved
historic FFmpeg payloads, verified their bytes, UUIDs, reserved score and sixteen-run
membership, and released only their job-specific uniqueness locks. It settled all sixteen
through `markAsCompleted(..., interrupted_corpus_round_retired, ...)`, explicitly recording
the interrupted old-code round and need for a fresh replay. Full pre-transition rows,
payload copies and `recovery-receipt.json` remain; no cut file or other evidence was deleted.

All six workers were recreated and started after the 21:25:42 UTC code commit:
orchestration at 21:27:01 UTC, the other five at 21:27:13 UTC. App and worker staging/temp
write-and-read probes passed; internal free space was 19,721,216,000 bytes, staging
498,583,126,016 bytes. Ten monitored queues were empty in all three states; failed jobs
remain 479. The initial pre-start snapshot was retained as
`snapshot-before-worker-start-check.json` and superseded, without dispatch, by a fresh
`snapshot.json` after the start-time checks. That snapshot binds the same sixteen and
listening routing; the saved-draw dry run is 16 ready / zero refused. The service-artifact
checksum backup is still running; the detection-rulings backup is being refreshed again
with this recovery evidence. Recompose waits for both backups and final preflight.


**Recompose stopped on a restored saved-ruling flag, 21:41 UTC.** Both checksum backups
completed with exit 0 before dispatch. `preflight-recompose.json` records the empty queues,
post-commit worker starts, writable mounts, 19,681,169,408 internal free bytes and fresh
snapshot. `recompose-dispatch.txt` records 16 dispatched / zero refused. All sixteen saved
bundles were recomposed and projected, but the deferred orchestration tail was stopped
before completion when 1250 again required the interruption review. Timings and Tier C
were not dispatched; there are no new cuts or new quality verdicts in this round.

**New blocker: 1250's saved answer restores a flag after the majority composer removes it.**
Fix A's composer produces an unflagged sermon, but the carried canary-9 answer
`f7b2a77ec5269f967e8a8ff86b6c3fdc86fc22ae059a0ff966c9a2938f5d01a1`, ruling
`0dfb6c27-4a43-4d5d-af65-c3c71c7cbd97` (2026-10-01 19:55:39 UTC), contains
`structure_sermon_interruption_merged` in its saved resolution. The existing
`ServiceStructureEnsembleRulingApplier::apply()` copies that resolution's flags onto
the replacement section after composition. The read-only replay demonstrates the sequence:
no stitch flag before rulings, stitch flag after rulings. Section 73105 therefore has
`needs_manual_review=true` and its extraction plan has `requires_review=true`.
1050 and 1356 have no remaining stitch flag. The prior authorised passage confirmations
remain evidence; none was rewritten or repeated to clear this restored flag.

Evidence under `storage/app/private/canary10-exact-file-rerun-20261003/`:
`stitch-flag-blocker.json`, `1250-stitch-replay-readonly.json`,
`evidence-stitch-blocker.json`, `stopped-state.json` and `stopped-checks.json`.
Review: [1250 — Job's final defence](http://localhost/admin/services/400).
The 1250 four-part review and selected sections [3128, 73104, 4910, 73105] are unchanged.
Naomi §73575 remains projected at 1056.60–1149.31 s with no ensemble flag; no fresh clip
exists yet. Run 964's recorded 1166–1167.21 s prayer fragment and micro-section flag remain
unchanged. All 32 attempt IDs and bank hashes are unchanged, all 64 saved artifact
checksums match, zero questions are open and all sixteen projection-provenance guards pass.
Failed jobs remain 479.

All six workers stopped normally (exit 0). Precisely 16 pending orchestration payloads
remain preserved, with no reserved or delayed job; every other monitored queue is empty.
`stopped-workers.json`, `stopped-queues.json` and
`stopped-historic-orchestration-pending-payloads.jsonl` bind that state. They have not been
retired or resumed, and the sixteen processing states remain evidence of the interrupted
tail. The prior stopped round's cut files have not been replaced or deleted. No hold was
released and no answer was recorded. Fresh-file quality, including 1050's fresh verdict,
all final custody/AV/gap/accuracy/disk-pointer checks and the listening list remain pending.
This is a stop before extraction, not acceptance or a final cut receipt. Stopped-evidence
checksum backups are running; a read-only stopped-batch report will follow them.

## Part 2 — session notes, 2026-10-10 to 2026-10-16

Newest block first, as kept. "Read first" and "next" refer to the day each block was written.

### RE-CUT ROUND SET UP 16-10, NOT DISPATCHED (latest; read first) — plan §12 "Re-cut round (10-16)" + go checklist, 0f106d57d
- Operator: "do everything up to [dispatch]" — DO NOT dispatch without their word. Main: no workers, Redis 4/5 (restore copy env.main-before-recut-round, session 781bf913 scratchpad). Round containers UP from clone (master reset to bef6b303a; old line = branch round-20261013-line); LaunchAgent on the clone's script.
- Snapshot recut-round-20261016 (144 runs). Recompose dry 141 ready; 1035 → rerun-redetect (ready); 1126/1378 (superseded, adopted sermons) → ProcessingRunOrchestrator::reExtract. extract-runs.txt (142) written. Watcher + autotick NOT started.

### ROUND ENDED 15-10 19:45Z — plan §12 last entry, commit ed519a120
- Round containers removed; main back on Redis 0/1 with its 7 workers (`.env` from env.before-round; during-round copy in session 781bf913 scratchpad); ffmpeg LaunchAgent on main's script. Clone `../crockenhill-round` KEPT until listening accepted. Master 28 ahead of origin (unpushed).
- Quarantined re-cuts awaiting operator listening: sermons 1023, 880, 910, 1285, 918, 1114, 1273, 1267, 1305. 942/974/1363 were hand-edited structurally — NEVER recompose them.
- Readiness 12/455: biggest blockers = 520 sections awaiting song acceptance stage 2, 406 sermons without thumbnails, 81 failed runs. After-round queue: duplicate-pair apply, song stage 2, §9.1 stale song clips, OoS item 18035 re-link (1363), 1015 preacher.
- Re-cut listening DONE 15-10 (page P3cqjxMTFFzLyYKuk3324k): 8/9 right; 1023 opens on ~16 s silence (Whisper cue + word timing smeared over the children-leaving silence). Scan: 16 of 442 cut sermons lead ≥5 s silence, 5 trail ≥5 s (910 at 5.8 s heard as right). Silence-edge fix PROPOSED, awaiting operator ruling (plan §12 last entries, 6df638ae2).
- **RULING REVERSED 15-10 (529b4dd45): finished runs ARE re-cut.** 156 completed runs have pre-10-12 sermon cuts (135 v8 + 21 unversioned); re-cut every one whose replayed plan differs AT ALL (no tolerance — operator "was generous" with 10-12 'right' endings), song/talk clips too. Order: build silence-edge rule (start ≤2 s dead air; end trims dead air only, never word tail — prove vs RuledEndingsRegressionTest + 10-12 clipped set) → replay pre-flight → one round on new snapshot.
- **Dead-feed edge rule COMMITTED 20529521a (15-10, unpushed):** spoken edge in ≥2 s dead feed (≤−80 dB) moves to sound; "sound" = any live run ≥0.1 s reaching threshold once (stricter v1 would have cut 1025's ruled-clipped quiet last word — word and gate noise indistinguishable by level). Replay 70 moves, no clipped-ruled ending moves. Replay harness gotcha: evidence identity hashes the encoder — v8 runs need libx264, v9 the .env VideoToolbox; resolver returns `edge_word_timings_error` SILENTLY (empty audit). NEXT: replay pre-flight of the 156 pre-fix runs, then draft the re-cut round.
- Older blocks below are history.

### BUILT FOR THE ROUND 13-10 — plan docs/plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md rev 3 §11
- Round runs from a FROZEN CLONE (Codex reviewed rev 1; rev 2 fixed its 5 blockers; rev 3 = built, awaiting Codex re-review). NOTHING set up yet: no clone/containers; main workers + .env unchanged. Local master 17 ahead of origin (last push b712ef11d).
- Built: 943 fix (superseded runs skipped by reconciliation, e15426dc2); PHPUnit Redis 6/7 (0a67d5811), Dusk 8/9 (.env.dusk.local); guard admits round-parked held sermons (89f2b25d8); --all required for unnamed rerun-* (4b2260d41); historic-import:rerun-withdraw (5571521c8, 399691d54; dry run 105/105 ready: 66 main + 38 tierb + 1209); docker-compose.round.yml + scripts/historic-round/{round.sh,autotick.sh,queue-state.php} + replica vars (3ef104e44).
- Rulings applied: pattern A (6b8dc6165 sing-start end), B+D (6333fbafd), C (40d5a4736), readings-before-sermon (7d0f7e671, 11 runs gain readings); run answers recorded via AnswerServiceStructureEnsembleQuestion (944 talk not 2nd sermon, 1066, 997, 1344, 1281, 1363 greeting/talk split) — script storage/scratch/decisions-20261013/apply-run-answers.php; composition reviews 1014/1194 (apply-compositions.php — re-run if recompose moves identity). 930 PARKED (pre-existing duplicate_oos_item: pre-service music + welcome share item; Luke correction overlaps the 'carol' 909–1089).
- TALK ANSWERS DONE 13-10 (9 include / 6 alone) → BUILT `TalkReadingPairing` + `service:answer-talk-reading`, commit 4c638f53c; Codex review fixes (withdraw proves sections/media unchanged + cancels round-started steps; held reading holds paired talk; answer follows talk in sync; reading opening sentence-checked) d18b53ab7 (19 ahead, unpushed). 14 recorded; **944 §190427 still `other` — record its answer between its rerun-recompose and rerun-extract**; **1286 joins R1** (finished run, talk now cut with Isaiah 35). Listening note: 1271 end runs through "sovereign" (neither class).
- Replay harness now pins encoder too: config version 8 + video_extraction.encoder libx264 (signature includes encoder). compose-all.php / compose-diff.py in endings-rule/ diff composition corpus-wide.

### PRE-ROUND 13-10 (later; read first)
- PUSHED master b712ef11d (gh https push: ssh key needs passphrase → `git -c credential.helper='!gh auth git-credential' push https://github.com/GarethClarridge/crockenhill.git master`).
- VideoToolbox SHIPPED 887efbc84 (operator: use it; leave the 219 finished runs un-recut). LaunchAgent `com.crockenhill.ffmpeg-host` (:2024, --jobs 2, maps /mnt/historic-work + /var/www/html/storage; log ~/Library/Logs/ffmpeg-host.log) running, reachable from workers. .env: VIDEO_EXTRACTION_ENCODER=h264_videotoolbox, HOST_FFMPEG_URL, HISTORIC_MEDIA_WORKERS_FFMPEG=2, LLM=1 (backup scratchpad env.before). Workers NOT yet restarted (width 2 needs compose up for replicas). Cut suites 121/121 on real VT; VideoExtractionServiceTest can't run on host (stub binary + /tmp paths) — expected.
- Watcher b712ef11d: failed job stops only the watch whose snapshot has its run; watch-state `parked: [ids]` → 'parked' bucket (progress.php scratch needs the column added).
- clips.sh (decisions + full-pass-listening) now validate length vs min(len, source left); only 1129-2060-start-as short (old wrong-media file).
- FAILURE TRIAGE: only 10 failed jobs in the pass (all explained). Follow-up list `storage/scratch/endings-rule/followup-runs.php` → followup-runs.json: 438 runs (Tier B ⊂ main), 300 to touch: ~189 ready (140 amended-D1 hold, 12 detected-not-cut, 10 processing, 12 recompose, fixed failures, 940/954/1073/1268 redraw); 37 decision page; 37 CONTENT HOLDS (Sept judgement/source holds; most defects are fixed-by-recut kinds; holds stay live until operator releases — OPERATOR AGREED 13-10: cut them in the round with --held-section (holds stay), then a release page where the operator confirms each defect gone); 1223 + 1314 operator; 37 finished-run repairs (26 legacy song video, 16 marker re-match).

### FIX ROUND STATUS 13-10 (read this first)
All on local master, NOT pushed, workers still on OLD boot code (restart before any dispatch), watchers/loops stopped. Full suite 9,571 green, PHPStan 0 (Dusk not run: backend-only).
DONE: 1 (amended D1), 2 (sermon widest, conservative), 3 (P1 only), 4 (marker flag), 5, 6, 8, 9 (R1 tail + R2' sentence end), 10/19 (legacy song video overwrite), 11 (audio hole), 14 (redetect skip 16bec27c1/69d87fedb + runbook), 17 (J1 smeared start, J2 start after song's sound), 18 (rule M), version bump 9.
CODEX REVIEW 13-10 (3 × P2, all valid, all FIXED): (1) 465b86508 silent-gap join shared one loudness treatment → earlier part runs on to next start, parts MEET as separate segments (planner merges only true overlaps; sermon + its continuations still join; sentence check treats meeting segments as one stretch); (2) ac6ec1bd7 placedEdge() shared by forSpans and wordPauseEdge so sentence-check answers match guarded cuts; (3) b07ca0960 unnamed redetect never skips runs re-transcribed on the snapshot. Suite 9,573 green, PHPStan 0.
DECISION PAGE 13-10: https://claude.ai/artifact/NTGKGdBBy2kNLPdDohiQqP (17 questions, db collection `rulings`, source storage/scratch/decisions-20261013: items.py → build.py; evidence scripts holds*.php, pattern-a.php, rule-counts.php, talk-names.php, spans.php, 1344.php). The 30 composition holds regrouped on current code: A 16 sermon-end-into-hymn (spoken_end_thought_unresolved), B 5 sermon-only evening recordings (+953, 1202), C 3 interrupted-by-reading, D 2 low-confidence readings, E 3 membership (1014/1026/1194), 964 just re-decode. Apply answers next session: rules → code, run answers → corrections.
OPEN for operator: item 7 (alignment question options vs ruling), P2–P4 not built (low yield), 1224 (hymn read aloud), 1198/1111 mid-sentence ends needing wider decode, 962/1005 unpunctuated, 1240 reading→noise join, 976-960 & other J2-moved starts to listen to, the 32 song-video sections + 18 gate-refused runs + 51 hold-list completed runs + 66 old sermon videos all in the follow-up round, item 12/13/15/16/20 are ops/procedure (watchers: move BOTH started_at; per-round listening pages for the 511-file cap; validate clips; parked bucket in progress.php).

### FIX ROUND STARTED 12-10 21:35 UTC
- Watchers STOPPED (`docker stop` both), autotick/front-loop killed; queues empty. Clone branch `fix/webm-duration-fallback` FAST-FORWARDED into live master (HEAD 5f9a9c76d, local only, not pushed). Workers still up on old boot code — restart before any dispatch.
- Item 9 workspace `storage/scratch/endings-rule/`: answer key `endings-listening/answer-key.json` (340, recheck overrides applied: 274/39/25/2); `join.php` (ruling ↔ plan audit), `replay.php <out>` (read-only, rolled-back resolve/forSection on current code), `score.py <replay>` vs `replay-baseline.json` (273/274 reproduce exactly).
- **R1 BUILT, UNCOMMITTED:** `CueSafeExtractionPlan::withSpokenEndTail` — every spoken end edge ≥0.3 s past its last decoded word, capped at half the pause, applied AFTER sentence check (banked answers keep identity). Tests `a_spoken_end_leaves_tail_room_after_its_last_word` (3 cases) + 3 expectations updated; ChurchService+resolver+edges suites green; Pint/PHPStan 0. Replay: 19/30 replayable clips_word move later; 87 right endings move ≤0.3 s into a pause.
- **Evidence against "never cut inside a cue":** 18 inward end moves, 14 ruled right (they drop "Thank you."/"Let's pray") — NOT built.
- **cut_sentence (25) is heterogeneous:** 957/1184/1170 = recording ends; 1224 = sermon end pushed 22 s into hymn (spokenThrough heard singing as speech → item 18); 962-841 talk not extended to its song announcement (talks never get spokenThrough); 1198/1111/1005 end moved OUTWARD to a mid-sentence pause in the next cue (largest pause); 1335/948 moved INWARD to a mid-sentence pause. Sentence check never asked on any (spokenSides needs two adjacent non-song sections). Candidate R2: end moves only to sentence-final pauses; R3: extend a non-sentence-ending last cue to the sentence end (needs edge decode there; guard unpunctuated word-chunk transcripts like 962/1005).
- R1 COMMITTED ca0192710 on master (operator: "commit to master and continue").
- **66 completed runs' sermon VIDEOS never re-cut this pass** (list `endings-rule/not-recut.txt`; clip 31% vs 18% new). CAUSE: rerun-extract refused 65 with EnsembleReviewGate "Banked composition/answers are not reflected in projected sections" (+1126 superseded). Breakdown (`endings-rule/disputes.txt`, gate.php): ~37 answers APPLIED but never recomposed (synced=false; main-page answers applied 10-11 15:54–16:21, AFTER the 15:17 Tier A recompose — no recompose was ever dispatched for them); ~14 with open disputes and NO rulings (930,960,963,974,1004,1018,1042,1044,1096,1119,1154,1211,1281,1307); 6 conflicting rulings (987,1027,1269,1285,1305,1343); 1340 provenance only. Follow-up round: answer/resolve → recompose → cut. Item 15 extension: progress must show "awaiting answers/recompose", these looked done.
  CORRECTION: across both snapshots only 18 completed runs are deferred AND off the hold list (`endings-rule/deferred-completed.txt`) — the real gate gap; the other ~48 of the 66 are hold-list runs (51 completed/deferred/HOLD), held on purpose. All 31 sermon files >0.1 s longer than plan are these old (Sep) cuts incl. 1352 (+4 s = old audio stream); NO new cut overruns its plan → item 10's 'unknown extra tail' is closed.
- **Item 10/19 FIXED 14df1abce:** HistoricAssetPromotion::bindSongVideoToQuarantine overwrote a section's extracted_video_path with its legacy (Sep, no song_video_extraction record) SongVideo's file, so 32 re-cut sections (26 runs; 8 talks incl. 2375/2060, 24 songs; list `endings-rule/songvideo-affected.json`) point at Sep song clips; their new cuts sit in staging section-publications/. New PublicationCandidate::holdsCutOtherThan. The 32 need re-cutting/re-promotion in the follow-up round. Commits so far: ca0192710 (R1 tail), 746d5b956 (R2' sentence end), 14df1abce.
- **Item 17 (joins) — mechanism = START-edge placement, not composition** (main-page rulings exported to `endings-rule/main-rulings/`; starts 77 right/2 wrong (composition), joins 17 right/5 bad). Replay `replay-all.php <out>` + `edges.py <a> <b>` diff every edge of 354 outputs with rulings attached. FIXED: J1 2dae3ef2a — a start a decode smears a word across (>2 s) stays at detection's start when the classifier hears speech>music there (932 −19 s, 1237 −3.3 s back to sermon opening; +990, 1149, 1167 unruled). J2 023864cff — a spoken part starting ≤2 s after a song's section end starts no earlier than that song's own songEnd decision (song_end_silence/speech): 1335 +11.7 s, 1229 +4.8 s (partial: outro runs to ~2150), and 976-960 (ruled right, +7.5 s outro dropped), 1232, 1214, 1227-2799, 1291, 1203, 1040, 939 unruled → LISTEN to these in the next listening round. A classifier-only start rule is UNSAFE: 16 right-ruled starts sit in 5 s windows labelled music. Left: 1240 (reading→noise join), 1067 = item 1 (D1 prayer).
- **Item 1 (amended D1) + item 8 BUILT 1596161cc:** compose takes the section immediately before the sermon's first part when it's a prayer; resolve `throughSilentGaps` joins parts ≤15 s apart with no unselected section between; section end ≤1 s past duration clamps (1136 +0.8 s, 1219 +0.003 s). 9 existing test expectations updated (all 10 s gaps → one span); recorded under D1 in CUT-FROM-SECTIONS plan. Replay: 10 sermon outputs 2→1 parts (992, 995, 1067…). Full suite was green except those (fixed).
- **Item 5 FIXED 41b77c0f8:** EdgeWindowWords::refitted — a word timed backwards/before the window keeps its place spanning prev.end→next.start (both providers); structurally broken entries still throw.
- **Item 6 FIXED c2123536c:** hang = CueSafeExtractionPlan::spokenThrough while(true) on zero-length looped-transcript cues (1077: 6× "because you died for me" at 1935.66) — next cue must end past `through`. Both runs now 0.01 s. Other do-while loops checked: strictly monotonic.
- **Item 11 FIXED 543a0cea3:** 973 §190536's .mkv has a 0.116 s hole in its audio at 785.032; treatment's asetpts=N/SR/TB closed it → audio 0.115 s short/desync → checker refused (correctly). partFilter now fills holes: `aresample=async=1:min_hard_comp=0.01:first_pts=0` after trim. Reproduced + verified with scratch timing973.php.
- **media_processing_version 8→9 ae24e45bc** (invalidates banked edge-word decodes + sentence-check answers: the follow-up round re-decodes edges). Full suite 9,553 green, PHPStan 0, all on master (local, NOT pushed). Commits this session: ca0192710 746d5b956 14df1abce 2dae3ef2a 023864cff 1596161cc 41b77c0f8 c2123536c 543a0cea3 ae24e45bc.
- **REPLAY HARNESS AFTER THE BUMP:** banked edge evidence is bound to media_processing_version 8 — scratch scripts in endings-rule/ now pin `config(['media-processing.media_processing_version' => 8])` after bootstrap, else resolve() returns no segments (edge_word_timings_missing).
- **Item 18 FIXED 39a65a4c0 (rule M):** 1275's end went 19 s into the next song because a hallucinated 30 s "Thank you." cue widened the edge window and the largest pause after a sung "Amen." was taken; a spoken end moved outward >1 s into a stretch with musicShare ≥0.5 stays at detection's end. Replay: only 1275 moves. 1224 is NOT this: the preacher reads the hymn's words aloud (classifier hears speech), stretch stops one phrase short at the 5 s music window — left.
- **Item 7 NOT BUILT (operator decision):** 1344-q0 is the corpus's only `alignment` dispute and 1344's only dispute; alignment disputes carry candidate_groups, the export builds options from alternatives (so only neither/defer), and no applier handles them. Ensemble plan §3.4 says preserve alternatives rather than manufacture a majority → proper fix = options per candidate group + applier support; or rule it.
- **Item 4 FIXED 6153d3c2f:** composer unioned supporters' review flags, so one draft's song_title_marker_mismatch (vs THAT draft's markers) reached the composed song although composition markers agree; MatchSongs keeps it, SongCatalogueTitlePolicy demotes to inferred. Now excluded from the union; validation of the composed structure (DetectServiceStructure:430/500, merges) re-adds it only against composition markers. 107 songs currently carry the flag (updated since 10-10). Follow-up round: recompose + re-match these runs.
- Item 4 VERIFIED: marker-replay.php over 59 runs' banked draws → 107 flagged songs → 0, 0 errors.
- **Item 2 BUILT c870b1471 (ruling 5):** composer compatible(): sermons overlapping ≥0.5 whose start-stretch AND end-stretch hold no non-sermon section in ANY draft are one sermon; widestSermon() widens outward from the representative only (never narrows), stopping before claimed stretches. Claimed stretches keep old tolerance logic (may still ask — the refinement 'version leaving the item its own' NOT automated: narrowing over a 1-of-4 claimed stretch would leave it in no section). Tests: 1005 four-cuts + unanimous-boundaries expectations updated to widest. sermon-replay.php: 10 sermon questions → 5 (945, 960, 987, 1027, 1066 remain).
- **Item 3: P1 BUILT d85cf2040** (composer: reading group, one variant, absent drafts give its time to nothing (no reading, no other non-`other` item over half of it) → decided: kept if ≥3 verses (ScriptureReferenceResolver::verseCount, new; cross-chapter = many), else 25 s when ref has no ':'/none; not kept in last 5% of transcript duration). Reading-vs-prayer type conflicts stay questions (2 DetectServiceStructureTest cases). P2 (3 decisions), P3 (5), P4 (1) measured low-yield → NOT built; P3's non-song oos part already in 3e03c0b0a. reading-replay.php result in endings-rule/reading-replay.txt.
- Item 9 left: 1198/1111 end mid-sentence before next cue (need wider decode), 962/1005 unpunctuated transcripts, 1224 = item 18.

### FULL PASS FINISHED 12-10 ~20:06 UTC
All 10 queues drained, no stops since. Final: main 176 cut / 89 held-failed / ~99 parked on the hold list; Tier B 43 cut / 29 held-failed / 38 parked; failed_jobs 493. The tick cron and stop Monitor were SESSION-ONLY and die with the session (the older instructions about them no longer apply — there is nothing left to watch). Watcher containers, autotick.sh and front-loop.sh keep running in Docker: STOP them before the deploy (`docker stop crockenhill-rerun-watch crockenhill-rerun-watch-tierb`; `pkill -f autotick.sh; pkill -f front-loop.sh` inside laravel.test) so they do not dispatch against new code.
**Operator's decision 12-10: fix everything on the FIX BRANCH TODO list (items 1–20) BEFORE the next run.** New session starts with the fixes: items 9 (endings clipped 19%, answer key = endings pages' rulings), 10/19 (published media ≠ plan), 17 (sermon joins), then the older ones. Listening artifacts: main R4FScSBMDfkVPPvEyj9tFP (v48, 352 items), endings TTqUhqX7PRL4UA338Gjyoc (340), re-check 9pF9taVUGv7MyewQBYYyFX (30), Amen XK6Eg1XaeP2WDM3HimX7rn (superseded). Their rulings live in each artifact's db (`rulings`) — export with ArtifactData list.

### HANDOVER — CURRENT STATE (12-10 11:16 UTC; read this first, the log below is history)

**Running without Claude (check, don't restart unless down):**
- Workers: 7 containers on freeze 74ed3b78b (`HISTORIC_MEDIA_WORKERS_LLM=2` in .env — back to 1 after the pass). NEVER merge the fix branch while they run.
- Watchers run CLONE code (`../crockenhill-webm`, branch `fix/webm-duration-fallback`, HEAD 5f9a9c76d) with live storage: `crockenhill-rerun-watch` (main `full-pass-20261011`, log storage/logs/rerun-watch.log) and `crockenhill-rerun-watch-tierb` (`full-pass-20261011-tierb`). Restart after any clone commit: `docker restart crockenhill-rerun-watch crockenhill-rerun-watch-tierb`. Main watch-state has 24 acknowledged stop texts.
- In laravel.test: `autotick.sh` (every 10 min: progress.tsv + `rerun-extract` on both snapshots with an EXPLICIT run list = members minus `storage/scratch/full-pass-listening/amended-d1-hold.txt` via `extract-runs.php`) and `front-loop.sh` (every 60 s). Restart: `vendor/bin/sail exec -d laravel.test bash storage/scratch/full-pass-listening/{autotick,front-loop}.sh`.
- Status: `zsh storage/scratch/full-pass-listening/tick-status.sh`.

**Session-only — set up in a new session (FIRST run CronList and CronDelete any leftover full-pass jobs; on 12-10 the old session's crons were still live and every check fired twice):**
1. Full tick — the ONLY cron. CronCreate, cron `9,29,49 * * * *`, exact prompt: "Full-pass tick (historic full pass, see memory full_pass_running_2026_10_10.md HANDOVER). Run `date -u` and `zsh storage/scratch/full-pass-listening/tick-status.sh`. If a watcher stopped (watch-stop.json in storage/app/private/full-pass-20261011 or -tierb): identify the failure, archive watch-stop.json as watch-stop-<UTC>-<run>-<cause>.json, move that snapshot's watch-state started_at past a known failed job (or add exact lasting-state stop texts to `acknowledged`), `docker start` the watcher, and `queue:continue` all 10 redis queues — standing operator instruction: keep it running, never leave it paused, nothing irreversible. Check autotick/front-loop are running (restart via sail exec -d if not). Answer Tier A questions as they arrive using the four recorded rulings, showing the operator the list before writing. Refresh the listening page if new completed runs exist. Post a short update with ETAs based on the real clock. Record notable events in the memory file."
2. Stop detection — a **Monitor, NOT a cron** (operator 12-10: the 5-min stop-check cron was ~12 empty turns/h). Start a Monitor whose command blocks until a stop file appears, e.g. `until find /Users/garethclarridge/Projects/crockenhill/storage/app/private -maxdepth 2 -path '*full-pass-20261011*' -name watch-stop.json | grep -q .; do sleep 30; done` (find, not a glob — zsh errors on an unmatched glob). When it fires: handle the stop (below), record it here, post a two-line note, then START A NEW MONITOR (it is one-shot).

**Standing operator instructions:** keep it running, never leave it paused, nothing irreversible. Claude may answer questions by the recorded rules ([[full-pass-question-rules-2026-10-11]]), create-only writes. Future question batches go on ONE combined page (open questions only, answers carried by question id).

**How to handle a stop (classes seen so far):** read `watch-stop.json` stops. (a) A failed JOB → find it in failed_jobs (id > 493 now), move that snapshot's `watch-state.json` `started_at` just past its failed_at. (b) A lasting state ("lost its extracted media", "left review") → identify the cause, append the exact stop texts to `acknowledged`. Known lasting classes: bounds outside source (1136, 1219 — fix item 8), section insert shifts a song's id (1339 — [[section-insert-deletes-extracted-media]]), supersession by a sibling run. Song demotion and song→other are already auto-pending (watcher rules). New 12-10: song held by publication review (song_looped_transcript, no catalogue lyrics) + cut plan `edge_unresolved` → not_applicable (1275). **Some runs are in BOTH snapshots (1275 was) — acknowledge a lasting state in BOTH watch-states at once, or the other watcher stops on the same text minutes later.** Then archive stop file, `docker start <watcher>`, `queue:continue` the 10 queues (historic-ffmpeg, historic-whisper, historic-llm, historic-orchestration, default, audio-processing, video-processing, livestream-processing, speaker-identification, sermon-processing), record in this file.

**State 12-10 11:16 UTC:** main 141 cut, 70 held/failed (failed list unchanged at 38 runs; the rises are runs completing with held sections); Tier B 30 cut, 53 processing, 27 held/failed; ffmpeg 55 queued. Measured 06:20→09:13: ~11.5 min per ffmpeg job (ExtractSermon 10–14 min, PrepareSectionPublicationCandidates ~7 min) → queue clears ~21:00–21:30 UTC Mon 12-10. Stops: 18 so far, all resumed; last two were the same 1275 song on tierb (07:13) then main (07:15). Listening page v43, 288 items (operator rounds 1–3: 167/176 right; items 261–288 added 12-10 not yet listened). Refresh pipeline + `find clips -newer publish-files.json` → publish with `files`, then `touch publish-files.json`; a newly cut run often draws 0 items (seeded sample) — then nothing to publish.

**Held, needs operator/fix — not cut this pass:**
- Hold list (163 runs: amended D1 + song-match regression) — follow-up round.
- Sermon composition review (30): 933, 953, 956, 964, 981, 982, 989, 1014, 1026, 1049, 1060, 1062, 1063, 1110, 1116, 1155, 1159, 1179, 1185, 1194, 1216, 1218, 1226, 1235, 1238, 1251, 1267, 1271, 1346, 1348 — offer: group into patterns before ruling.
- Content-hold parks (15, holds carried from earlier phases): loop/source mismatch ×5 (946, 1064, 1084, 1121, 1176), duplicate-performance ×3 (1120, 1374, 1375), P8-Q14 loop ×2 (1068, 1187), MP3 ends early ×2 (1332, 1347), singles 1057, 1322, 1380 — re-cut each with `sermons:re-extract <pid> --held-section=<id>` once checked.
- Failed for a fix: 1010 (edge timestamps), 1023/1077 (edge hang), 1136/1219 (bounds outside source), 1339 (section insert), 16 duration-less (15 WebM + 1035).

**Open checks:** (1240 RESOLVED 12-10: recompose took effect, run cut, on listening v37); 1275 song 83 "Angel Voices Ever Singing" has no catalogue lyrics in OpenLP — adding them would let the loop check clear it; 960 two-sermon conflict; 974/1154 duplicate link; 1344-q0 alignment question with NO options (export bug); 1363 "two services joined" (check source); 6 answers need corrected sections from notes (997-q1, 1066-q0, 930-q0, 944-q1, 1281-q1, 1363-q2).

**Hardware encoding:** investigation prompt for another session at `storage/scratch/full-pass-listening/PROMPT-hardware-video-encoding.md` (operator wants overnight cutting; consistency doesn't matter). If adopted: pull queued cuts (hold-jobs.php pattern), stop workers, deploy fix branch + encoder, new snapshot of all not-yet-cut runs, one dispatch.

**FIX BRANCH TODO (deploy at pass end, workers idle; then follow-up round):** (1) amended D1 in SermonExtractionPlanResolver::compose + record in CUT-FROM-SECTIONS plan ([[amended-d1-prayer-before-sermon-and-silent-joins]]); (2) ensemble equivalence: sermon versions differing only in start/end → earliest start + latest end, but a widest version swallowing a separately identified item stays a question; (3) answer patterns P1–P4 as composer rules; (4) song-match regression: 62 confirmed→inferred with new song_title_marker_mismatch — find cause (ServiceStructureValidator::songTitleContradictsChapterMarker vs detection-time markers), fix, re-run matching for 44 runs; (5) whisper edge window: one malformed word fails the job (1010) — drop/clamp; (6) edge-job CPU hang (1023, 1077); (7) export: alignment question with no options (1344); (8) section end overshooting the source by <1 s fails the cut (1136, 1219) — clamp to source duration. Also [[section-insert-deletes-extracted-media]] (1339).
  **Added 12-10 (operator: "fix before the next run"):**
  (9) **ENDINGS CLIPPED ~19% (64/340 sermon+talk endings: 39 word clipped, 25 sentence cut off)** — end placement trusts the recogniser's last-cue end. Inside last cue 28/67 bad, ≤0.3 s after 32/190, 0.3–1 s 4/47, >1 s after 0/36. Rule to build: never cut inside a cue; last cue end + ~1 s tail room, pause midpoint if speech follows; score on the replay harness (canary14-step0) with the endings rulings as answer key (page TTqUhqX7PRL4UA338Gjyoc overridden by re-check page 9pF9taVUGv7MyewQBYYyFX); look at the sermon-end closing-sentence anchor first. Not Amen-specific (Amen groups 19–44%, other endings 17%).
  (10) **Output file ≠ plan:** 9 talks/sermons whose file is a different length from the planned cut (928/510, 1020/1344, 1102/1862, 1129/2060, 1180/2375, 1198/2486 [file 1,122 s vs 297 s planned], 1217/2670, 1240/2985, 1352) and 30 sermon files 0.1–0.9 s longer than plan (unknown extra tail). Find why published media can be a stale or different cut; add a post-extract duration check (file vs plan) that holds the section.
  (11) **`PrepareSectionPublicationCandidates` timing check** 'audio start or duration differs from selected sections' failed run 973 (SourceAwareMediaTimingChecker:94) — find the cause.
  (12) **Watcher/ops:** a failed job stops BOTH watchers whatever snapshot the run is in — move both started_at together; artifact manifest cap 511 files/version breaks the listening page's clip publishing (use assets or per-round pages); a corrupt published clip (1180-2375-end-as, 565 bytes) — validate clips after cutting.
  (13) **The main listening page under-detected clipped ends:** 70 ends were ruled on both pages; the main page (20 s end clip + 'around' clip, 'is the way it ends right?') said right for 67 and flagged 2 as clipped; the 3 s end-only page flags 9 (7 missed, 60 agree). Earlier answers were mostly sound (60/67) but the design hides last-word clipping. Future passes: 3 s end-only clips with fine / word clipped / sentence cut off, and make 'cut inside a cue' an automatic check in the pipeline once item 9 lands.
  (14) **Tool/plan traps found this run:** `rerun-redetect` with no run list admits recompose-routed runs (make it refuse them); plan §6.3.9 wrongly says the guard admits a finished transcription round on the same snapshot (Tier A→B needs a second snapshot: correct the plan); moving the code freeze mid-pass reopens every dispatched round (note in the runbook).
  (15) **Progress/watch status cannot tell parked from active:** runs whose jobs were deliberately pulled (hold list: 100 main + 38 Tier B) show as 'processing' for days and made the pass look unfinished; add a 'parked' bucket (hold-list file or a metadata flag).
  (16) **Deploy checklist at pass end:** merge `fix/webm-duration-fallback` (already built, clone ../crockenhill-webm HEAD 5f9a9c76d; frees the 15 duration-less WebM runs), then the new fixes; workers idle; `HISTORIC_MEDIA_WORKERS_LLM` back to 1; restart workers (stale code/boot env); listening pages from here on need assets or per-round pages (511-file cap).
  (17) **Sermon joins that carry non-sermon audio or drop the sermon's opening** (main listening page, operator 12-10): 1229 join1 'cuts from reading to the musical outro of the song before the sermon'; 1335 join1 'reading into music (possibly outro)'; 1240 join1 'joins the reading to noise'; 1237 join1 'no need for a join, it misses the first sentence of the sermon'; 932 join1 clips a word (11-10). Rule to build: a join into the sermon must land on the first spoken sermon word; music/outro/noise between is dropped; no join when the sermon starts right after the reading.
  (18) **Sermon end runs into the following song** (1275 'this is the start of the following song') — the sermon-end/next-song bound ([[sermon-span-ends-at-the-next-song]]) is not holding there; check with item 9's replay.
  (19) **The operator heard wrong media for talks 1180/2375 and 1129/2060** ('the clip is of a song; the wider service clip is correct') — the same file≠plan defect as item 10; add to its case list with 928/510, 1020/1344, 1102/1862, 1198/2486, 1217/2670, 1240/2985.
  (20) **Open small items:** 1202 'could be a talk or a sermon' (sermon-vs-talk ambiguity); 1127 missed segment is a song introduction (operator can't tell what it follows); four 'can't tell' answers were silent song-start clips (1005-1237, 1036-1477, 1117-1960 start; 1138 missed is silent) — already re-asked longer, no defect. **Items 338–352 of the main listening page (15 items, runs 1204, 1214, 1264, 1306, 1337) were never listened to.**

**Discoveries / later improvements (not defects):** [[full-pass-discoveries-2026-10-11]] — add new ones there.

**FOLLOW-UP ROUND (new code, new snapshot):** 163 held runs (re-cut ~50 already cut), 16 duration-less runs, 1010/1023/1077/1136/1219/1339, corrected-sections answers. Set HISTORIC_MEDIA_WORKERS_LLM back to 1.

**Pages:** listening https://claude.ai/artifact/R4FScSBMDfkVPPvEyj9tFP (pipeline: `vendor/bin/sail php storage/scratch/full-pass-listening/inventory.php full-pass-20261011/snapshot.json` → `python3 feed.py` → `vendor/bin/sail php cues.php` → `vendor/bin/sail exec -T laravel.test bash storage/scratch/full-pass-listening/clips.sh` → `python3 build.py` → publish with `files` = clips newer than publish-files.json, then `touch publish-files.json`; feed.py crashes harmlessly on an empty inventory). Questions: main https://claude.ai/artifact/LEAdv4TQqdbwHu6NcmPsxi, Tier A https://claude.ai/artifact/AmBQajR2BvgA5tWeQ7no9Y (both answered and applied).

**Launched 2026-10-10 ~19:20–19:30 UTC** per VIDEO-BOUNDARY §6.3.9. Preflight live: queues empty/unpaused, failed_jobs 483 (baseline), staging/temp/quarantine rw, Staging 465 GiB, internal 23 GiB, Sonnics 341 GiB; Staging still on the 5 Gb/s link (known, no swap); whisper :2022 + classifier :2023 up; speaker id false. Workers (6 containers) `docker start`ed fresh on HEAD. Snapshot `storage/app/private/full-pass-20261011/snapshot.json` (438, membership 5e649062…). Watcher started 19:21 (`storage/logs/rerun-watch.log`), first look no stop, 6 known passage warnings.

**Trap — `rerun-redetect` with no run list admits the 37 recompose runs too (281 ready, not 244)**: it does NOT refuse recompose-routed runs. Always pass explicit lists: Tier B = `runs-tier-b.txt`, Tier A follow-ups = `runs-tier-a.txt`. In zsh, word-split with `${=RUNS}` — unquoted `$RUNS` passes ONE argument and dispatched only run 930 (harmless; it's Tier B).

Dispatched: retranscribe 152, recompose 37, redetect 1 (930) + 243. Next each tick: watcher status, Tier A → `rerun-redetect $S ${=TIER_A}` for finished ones, `rerun-extract $S --max=500`, listening feed, ETAs to operator.

Related: [[full-pass-ready-2026-10-10]], [[queue-workers-run-stale-code-after-commit]].

**CURRENT STATE 10-10 ~19:50 UTC (read first):**
- **Tier A → B needs a second snapshot.** `AnalyzeSegments` (first job of Tier A's chain) writes a provisional sermon span on the run; the guard's `expectedBefore` allows only transcript + step, so every finished Tier A run is refused "changed since the snapshot". §6.3.9's "guard admits a finished transcription round on the same snapshot" is WRONG — canary 13/15 took `snapshot-tierb.json` (no --routing). Plan: when the 137 are transcribed, snapshot them into `full-pass-20261011-tierb/`, second watcher, redetect with explicit list.
- **WebM duration defect:** `30fec9ced`'s coverage guard (`AudioChunkingService::containerDuration`) needs a header duration; YouTube-backup WebMs have none → transcription refused. 15 Tier A runs affected: 929 971 977 1168 1195 1196 1208 1209 1265 1329 1342 1350 1353 1359 1366 (939 is WebM but has a duration). Operator chose: pull their queued `TranscribeFullService` jobs (DONE 19:49, payloads saved in `storage/app/private/full-pass-20261011/webm-removed-jobs.json`), resume the pass on the freeze, deploy the fix only when workers idle, then a follow-up round for the 15 (they sit reopened `processing`/`transcribe_full_service` until then; resume their chain on new code → tierb-style snapshot → redetect). Other 18 WebM runs unaffected (guard is transcription-only).
- **Fix BUILT, NOT merged:** clone `../crockenhill-webm`, branch `fix/webm-duration-fallback`: `containerDuration` falls back to `PacketCountedMediaDuration` (counted = recorded duration for all 15, 5–13 s each). Test `compression_measures_a_source_whose_header_declares_no_duration`. Merging into the live checkout moves CodeRevision → every dispatch on the snapshot refuses: NEVER while the pass runs.
- **Moving the freeze mid-pass is NOT cheap:** every dispatched round's stamp binds `code_revision`; runs are reopened; no tool withdraws an unstarted round. (I first told the operator it would cost ~4 transcriptions — wrong.)
- Loop cron `c880742a` (every 20 min, :07/:27/:47). Status script `storage/scratch/full-pass-listening/tick-status.sh` → `progress.tsv`.
- **10-10 20:16 UTC — autotick + second defect.** Operator: ticks must not depend on Claude. `storage/scratch/full-pass-listening/autotick.sh` runs in laravel.test (pid in `autotick/pid`, restart with `sail exec -d laravel.test bash …/autotick.sh` after a container restart): every 20 min writes `progress.tsv` (via progress.php) and dispatches `rerun-extract` (dry, then --execute if ready) on full-pass-20261011 and -tierb, skipping when watch-stop.json exists or a queue is paused; logs `autotick/latest.log`. `tick-status.sh` is now read-only. Claude cron `9,29,49` does only judgement work (Tier B snapshot, listening, update).
- **Defect 2 (fixed on the same clone branch, unmerged):** `ServiceStructureEnsembleRunner::runSubprocesses` reads slot files with `$disk->get()`; `service_artifacts` disk has `'throw' => true`, so a draw killed at the 300 s deadline (no file) throws `UnableToReadFile` and fails the whole detection attempt instead of becoming `interrupted` → lost-draw retry. Tests passed only because tests use the non-throwing transcript disk. ~6% of fresh detections (930 at 19:37); job-level retry redoes all 4 draws. Fix: `exists()` before `get()`; test `a_draw_that_banked_nothing_is_retried_on_a_disk_that_throws_on_missing_files`.
- Run 940 failed for review: "Fewer than two validated ensemble draws" (invalid, not lost).
- **10-10 20:40 UTC — throughput changes (operator):** (a) LLM width 2: `.env` `HISTORIC_MEDIA_WORKERS_LLM=2` (backup `full-pass-20261011/env-before-llm-width2.bak`), second container `queue.worker-historic-llm-2` via `sail up -d --no-recreate queue.worker-historic-llm` (NOT the 09-30 width-2 question — that was FFMPEG width, probed 1.06–1.16×, ruled width 1 = standing decision 6; I misattributed it when asking. LLM width 2 is new and unmeasured; cutting is the bottleneck, so it saves little — proposed reverting to 1 when Tier B drains). (b) Post-detection steps (projection, song matching…) were rpushed behind ~10 h of DetectServiceStructure on the same queue, so nothing could be cut until detection drained. `front-followups.php` LREM/LPUSHes non-detection jobs to the head (lossless; one queued job per run); `front-loop.sh` runs it every 60 s in laravel.test (pid `autotick/front-pid`, log `autotick/front.log`); autotick also runs it and now cycles every 10 min. Restart both loops after any laravel.test restart.
- Fix branch COMMITTED `e66624085` on `fix/webm-duration-fallback` in `../crockenhill-webm` (suite 9,519 green, Pint, PHPStan 0). Not merged, not pushed.
- **10-10 20:54–21:13 UTC — WATCHER STOP (false) + watcher now runs FROM THE CLONE.** First Tier C dispatch (21 recomposed runs, 20:48) → watcher stopped on 30 "lost its extracted media" (songs/talks pending_approval). Cause: recompose's section sync deletes unpublished working copies of sections whose signature changed (by design, `ServiceSectionSyncService::cleanupExtractedAssets`), excused while the stamp says media deferred; `rerun-extract` flips the stamp to `extracted` before the queued cut runs → custody became attention. Nothing published touched. Operator chose: fix in clone — `HistoricRerunState::sermonPlan` treats an extraction stamp with run pending/processing as deferred (tests `an_extraction_still_running_leaves_media_custody_pending`, `a_finished_extraction_that_left_a_section_without_media_needs_attention`). Watcher runs as container `crockenhill-rerun-watch` (sail-8.4/app, clone at /var/www/html, LIVE storage bind-mounted over it, same /mnt mounts), log appends to storage/logs/rerun-watch.log; its watch-state baseline (19:21) kept. Stop file archived as `watch-stop-20261010T2054Z.json`. If it must restart: `docker start crockenhill-rerun-watch`. Watcher does no code-revision check, so running clone code is safe; never run dispatch commands from the clone.
- **10-10 21:25 UTC — Tier C chains start on WHISPER:** re-extraction chain = TranscribeOutputEdges (whisper) → ExtractSermon (ffmpeg) → … → CreateSermonTranscriptFromService (whisper) → ProcessTranscriptWithAI (llm) → … So the first 21 cuts (dispatched 20:49) sat at the tail of historic-whisper behind ~130 Tier A TranscribeFullService (~9 h) — runs show `pending/completed` with no visible job. `front-followups.php` now also fronts every non-TranscribeFullService whisper job (edges 2–54 s each). front-loop picks it up each minute (its log keeps only the last line = whisper).
- Watcher fix COMMITTED `ad2fb920d` on the clone branch (after e66624085); Pint + PHPStan 0. Branch now 2 commits, unmerged, unpushed — deploy all at pass end (workers idle), then re-run 15 WebM runs.
- **10-10 21:18–22:01 UTC — STOP #2 (real failed job, known cause):** run 1223's recompose chain hit PromoteHistoricAssets → "section 2751 references candidate asset … on neither staging nor quarantine" (the §6.3.9 1223 custody defect; bytes at 9351fa4e…/sermons/songs/421/2751.mp4). 1223 now `failed`, parked for operator panel. Operator: resume, 1223 waits. watch-state `started_at` moved 19:20:54 → 21:13:20 (only the failed-job window uses it; original saved as watch-state-20261010T1920Z.json); stop file archived `watch-stop-20261010T2118Z-1223.json`.
- **10-10 22:05 UTC — OPERATOR STANDING INSTRUCTION overnight:** "Keep it going all night. Nothing is going to happen that I can't redo. I don't want to wake up to find it's been paused all night." An in-container auto-resume supervisor was BLOCKED by the auto-mode classifier — not worked around. Instead the Claude cron `6a7be02b` (:09/:29/:49) resumes after any watcher stop (archive stop file, move watch-state started_at, docker start crockenhill-rerun-watch, Queue::resume all) and records each. Listening rulings overnight are recorded, not stops.
- **10-10 22:05 UTC — STOP #3: run 1002 superseded** (at 22:01:12, by run 891 — failed June WEEKLY run, same service) when 1002's projection reconciled supersession BEFORE its songs were re-matched (MatchSongs 22:04): known class [[completed_historic_run_superseded_by_failed_run]]. A lasting state → re-stops every look. Queues resumed ~22:30 per standing instruction; stop file `watch-stop-20261010T2205Z-1002-superseded.json`. Building `acknowledged` list in watch-state.json (clone, watcher-only) so acknowledged exact stop texts become warnings.
- **10-10 22:28 UTC — STOP #4: run 964 "lost its extracted media" ×5** — its cut ended in `sermon_composition_review` (held, cuts nothing until answered). Resumed at once. Watcher rules extended in clone (UNCOMMITTED until Pint/PHPStan): (a) `watch-state.json` `acknowledged` = exact stop texts reported as warnings ("acknowledged stop: …"); 1002's superseded stop acknowledged; (b) a cut that parked the run for manual review also defers media custody. Watcher restarted 22:30, clean. Stop files: watch-stop-20261010T2228Z-964-held.json.
- Watcher acknowledged-stops + held-cut rule COMMITTED on clone branch (3 commits: e66624085, ad2fb920d, 6c47e780f); Pint + PHPStan 0.
- **10-10 22:55 UTC:** fronting whisper follow-ups starved Tier A (29/137 for 40 min; ffmpeg had ~55 cuts ≈ 9 h queued). front-followups.php now fronts whisper only when historic-ffmpeg has < 6 queued. Run 1073 held: llm_structure_validation_failed (like 940).
- **10-10 23:07 UTC — STOP #5: run 1106 superseded** (same class as 1002). Resumed 23:15. All 8 runs sharing a service with a non-historic run (1229,1126,1106,1059,1032,1379,1012,1002; `supersession-candidates.json`) pre-acknowledged in watch-state.json. Tier A stuck at 29 because ClassifyServiceAudio (ffmpeg, ~30 ms) sat behind ~32 ExtractSermon → front-followups.php now also fronts ClassifyServiceAudio/AnalyzeSegments/GenerateRmsLog on historic-ffmpeg. Front-loop log keeps only the last line (= ffmpeg now).
- **10-10 23:40 UTC — finish-first ordering:** 100+ cuts dispatched, 0 completed — each cut chain's later ffmpeg steps (EnhanceAudio, Assess, Thumbnail, PrepareSectionPublicationCandidates) rejoined the back behind ~35 new ExtractSermon. front-followups.php now fronts EVERY non-ExtractSermon ffmpeg job; on whisper with ffmpeg busy (≥6) only CreateSermonTranscriptFromService (≈0.2 s) is fronted (edges wait, Tier A keeps its turn). Run 1100 held: llm_structure_validation_failed.
- **10-10 23:40 UTC — STOP #6: run 1023 TranscribeOutputEdges hung 55 min → TimeoutExceeded** (whisper worker stuck 22:40–23:35 = the Tier A stall), run 1023 failed at transcribe_output_edges, its 4 discarded working copies → custody stops. Whisper healthy after. Resumed 23:55: started_at moved, 1023's 4 exact stops acknowledged. Operator to look at: why one edge request hung (host whisper.cpp?), and re-run 1023.
- **11-10 00:25 UTC — STOP #7: run 1219 superseded by failed HISTORIC run 959.** Resumed 00:35; supersession pre-acknowledgement widened to all 11 runs sharing a service with any other run.
- **11-10 01:40 UTC — LISTENING PAGE LIVE:** https://claude.ai/artifact/R4FScSBMDfkVPPvEyj9tFP (db capability, rulings in collection `rulings`; republish same file_path `storage/scratch/full-pass-listening/index.html` with `files` = clips/*). v1 = 10 runs, 29 items, 47 clips. inventory.php FIXED to require run status completed (stamp says `extracted` from dispatch; first build sampled 156 runs' OLD clips — discarded to `discarded-0135Z/`).
- **11-10 03:55 UTC — ensemble questions:** 79 completed runs refused for Tier C by EnsembleReviewGate (78 carry `structure_ensemble_disagrees`) = §6.3.9 step 7 holds awaiting answers. Fresh ensembles 244: 80 with disputes, 117 disputes, **0.48/run (bar 2.0)**. Exporting question page to `storage/scratch/full-pass-questions` (`structure:ensemble-export-questions --since=2026-10-10T19:20`); then publish (db), operator answers, `structure:ensemble-apply-answers`, `rerun-recompose`, autotick cuts. Held: 933 sermon_composition_review (new).
- **11-10 04:45 UTC:** full export (244 runs, 112 open Qs, 207 majority decisions, 396 clips = 273 MB) exceeds artifact 256 MB/version. Re-exporting ONLY the 80 runs with open questions (153 MB) to `storage/scratch/full-pass-questions-open`; publish in ≤64 MB chunks (≤255 files) with db capability.
- **11-10 05:20 UTC — QUESTIONS PAGE LIVE:** https://claude.ai/artifact/LEAdv4TQqdbwHu6NcmPsxi (80 runs, 112 open Qs, 68 majority decisions shown, 227 clips/158 MB in 3 publishes; db collection `rulings`; source `storage/scratch/full-pass-questions-open` with answers-map.json). After answers: `structure:ensemble-apply-answers` (check its options), then `historic-import:rerun-recompose $S <runs>`, autotick cuts.
- **11-10 06:29 UTC — STOP #8: run 968 §946 short_talk lost media** — completed cut, but section needs_manual_review (structure_low_confidence) → kept out of publication candidates until answered. Resumed 06:35 (exact text acknowledged). Clone rule (uncommitted until tests): HistoricRerunDiff reports a lost clip on a section that now needs_manual_review as PENDING ("re-cut once its review is answered"); test `a_section_under_review_that_lost_its_clip_is_pending`. Restart crockenhill-rerun-watch after tests pass.
- Held-for-review rule COMMITTED ed409a4e2 + Pint fix 66566dc9a on clone branch (now 5 commits after 74ed3b78b). Watcher restarted 06:54 with it.
- **11-10 07:35 UTC — 2nd edge-job hang: run 1077 TranscribeOutputEdges** started 06:57:08, step row "started", NO whisper call logged, PHP worker busy (R state, ~8% CPU), whisper-server idle → a CPU loop in the job BEFORE PrepareOutputEdgeWordTimings calls whisper: SermonExtractionPlanResolver::compose, CueSafeExtractionPlan::songEndSpeechEdges/spokenEndsBesideSongs. Run 1023's 55-min hang (23:35) likely the same. Job $timeout=3300 → fails ~07:52 → watcher stop → resume. TODO: reproduce offline for 1077/1023 (careful: compose may persist) and fix. Fronted the last 5 Tier A TranscribeFullService (1035,1270,1277,1278,1279) ahead of 112 edges.
- **11-10 08:05 UTC — listening round 1 (operator):** 55 rulings, 51 right, 0 wrong, 4 can't-tell — 3 openings whose first 8 s were silence/song tail (1005-1237, 1036-1477, 1117-1960) + 1138-missed (silent stretch; operator: probably correctly left out). feed.py edge clips lengthened: 20 s of output, ±15 s of recording (was 8 s / ±6 s); `feed.py --reask <run-section-which>` re-asks as "-long" items from reask-inventory.json. Page v3 = 100 items. Publish new clips with `find clips -newer publish-files.json` then `touch publish-files.json`.
- **11-10 07:52 UTC:** run 1077 edge job timed out (failed job 486); watch-state started_at moved to 07:52:09 BEFORE the watcher looked → no stop, no pause. 1077 joins re-run list with 1023.
- **11-10 08:10 UTC:** listening feed was 4.55 items/run (projected ~1,800) — talks at 1/2 × 3 items dominated (50 of 100). Operator: RATE_TALK 1/2 → 1/8 (projected ~2.2/run, ~850). Applies to newly sampled runs only; already-sampled items stay. Talk rulings so far: 30 right, 2 can't-tell (short-clip), 0 wrong.
- **11-10 08:35 UTC — BULK RULINGS (operator) written to questions page db (31 of 112):** (1) short scripture ≤50 s (call to worship/verse/blessing) "doesn't matter" → recorded "none" (so it can't become the sermon's reading); (2) song ≤30 s → not a song ("none"); (3) title variants → "either"; generic/garbled vs real title → chose real (1324 John 21, 1360 Psalm 139, 1352 See What a Morning). Left for operator: 1009 (Closing song vs We Have Heard the Joyful Sound), 1281 "God/Guard", 1252 Happy Birthday 30 s. Notes on each doc say "Bulk ruling 2026-10-11". Docs in autotick/bulk-docs/. Apply same rules to Tier A questions when they arrive. Candidate script: bulk-candidates.php (rule thresholds: reading ≤50 s, song ≤30 s, title = same span ±2 s).
- **11-10 08:20 UTC — STOP handled by ANOTHER SESSION:** run 1035 Tier A TranscribeFullService failed — Matroska (.mkv) without header duration = same defect as the WebMs (my 10-10 census only probed .webm). Other session: renamed stop file `watch-stop-20261011T0820Z-1035-mkv-duration.json`, started_at → 08:18:37 (acknowledged list kept, 16), docker start watcher, queue:continue ~08:33. Full census of all 438 sources: 35 without header duration = 33 webm + 2 mkv (1035 Tier A → follow-up round with the 15 WebMs = 16 runs; 1065 Tier B, unaffected). Fix e66624085 (packet count) covers mkv too.
- **11-10 ~08:45 UTC — oos-link questions:** 3 questions (958-q0, 974-q1, 1210-q0) differed only in oos_item_id (+summary/notes). 974-q1 changed to alt1 (linked), 1210-q0 answered alt0 (linked), notes say "Ruling 2026-10-11 … keep the linked version". Clone fixes (UNCOMMITTED until tests): composer signature ignores oos link for non-songs, different links still disagree, merged section keeps the link (test an_order_of_service_link_on_a_sermon_is_kept_without_a_question; 51 composer tests green); export labels "· linked/not linked to the order of service" (test versions_that_differ_in_their_order_of_service_link_say_so).
- **11-10 08:41 UTC — TIER A SNAPSHOT + DETECTION DISPATCHED:** `full-pass-20261011-tierb/snapshot.json` (136 runs = Tier A minus 15 WebM minus 1035; membership 627f3e2e…; no --routing); run list `storage/scratch/full-pass-prep/runs-tier-a-transcribed.txt`. Second watcher container `crockenhill-rerun-watch-tierb` (clone code, log storage/logs/rerun-watch-tierb.log), first look clean. rerun-redetect 136 dispatched. Autotick extracts both snapshots. Its own watch-state has NO acknowledged list yet.
- **11-10 ~08:55 UTC:** questions page v5 — answered questions fold to heading + "Answered: <label> — note" (tap heading to reopen; saving folds). Same patch in clone template ensemble-questions.blade.php (uncommitted, with oos-label fix). Tested via stand-in db in headless Chrome: fold/unfold OK, 360/360 px.
- **11-10 08:53 UTC tick:** no stops. New holds: 1060/1062/1063 sermon_composition_review (class now 9: 933,956,964,981,982,1060,1062,1063,1110 — worth reviewing as a set), 945 coverage 64% < floor, 954 <2 valid draws. Listening v4: 105 items (6 runs → 5 items at talk rate 1/8).
- **11-10 09:20 UTC — STOP #9 (both watchers): run 1010 TranscribeOutputEdges → "Invalid edge window word timestamps"** (LocalWhisperServiceTranscriptionService:135 rejects the whole window if ANY whisper word has end<start or start<0; whisper.cpp occasionally emits such words → new class, one bad word fails the run). Queues sat paused 09:22–09:49 (no Claude tick between sessions). Resumed 09:49: stop files archived `watch-stop-20261011T0922Z-1010-edge-timestamps.json` (both snapshots), started_at → 09:20:15 both, watchers restarted clean. New Claude cron `7b28e9e8` (:09/:29/:49). Follow-up: 1010 joins the re-run list (with 1023, 1077); consider dropping/clamping malformed words instead of throwing (fix the mechanism — check how often whisper does this).
- **11-10 10:20 UTC tick:** no stops. Tier A detected 37/136 (10:10, ~0.9/min → done ~12:00). Listening v5: 108 items (+1067). Gotchas: inventory.php wants `<snapshot>/snapshot.json` (not the dir), and each run overwrites new-inventory.json — run main, then feed, before tierb.
- **11-10 10:34 UTC tick:** no stops. Tier A: 26 DetectServiceStructure left (tierb 'detected' count is a state, not cumulative — count queued jobs instead). ffmpeg: ExtractSermon ≈4 min avg, ~7–8 cuts/h unpaused; 197 queued ≈ 26 h → Mon ~12:00 UTC, + Tier A 136 ≈ 18 h.
- **11-10 10:52 UTC — STOP #10 (main): run 992 §1177 song→other lost its extracted media** — the re-run split the spoken intro (§1177, now 'other', not publishable) from the song (new §163678 'Restore, O Lord' 1564.9–1580.4, 15.5 s, pending_approval with media); the discarded copy was never published. Correct outcome. Exact text acknowledged (17), stop file `watch-stop-20261011T1052Z-992-song-became-other.json`, resumed 10:55. Clone TODO (mechanism): HistoricRerunDiff should not call it custody when the new section type is non-publishable and the old media was never published. 10:53: 11 DetectServiceStructure left.
- **11-10 11:11 UTC — STOP #11 (both): run 943 DetectServiceStructure(reconcile) failed ×2 "No audio timeline"** — 943 is NOT a member (old completed run on service 583; member is 1148). An OoS-item change on service 583 at 10:02/10:07 made ChurchServiceReconciliationDispatcher → ReconcileServiceSections re-detect every completed run on the service. 943 has no timeline → fails; a failed reconcile keeps existing sections. Only 2 such delegations in the whole pass. Resumed 11:15 (started_at → 11:10:57 both; stop files `…T1111Z-943-reconcile-no-timeline.json`). Operator question: should the pass's OoS writes fan out to non-member sibling runs at all?
- **11-10 11:15 UTC — TIER A DETECTION DONE:** 136/136 fresh ensembles, 60 runs with 75 disputes (0.55/run). Run list `storage/scratch/full-pass-listening/tiera-question-runs.txt` (tiera-disputes.php). Exporting via the TIERB WATCHER container (clone code: pills, fold, oos labels; export is read-only) to `storage/scratch/full-pass-questions-tiera`.
- **11-10 11:35 UTC tick:** no stops. Main cut 46. Listening v6: 121 items (+8 runs: 985, 992, 995, 1029, 1037, …). Tier A export restarted 11:31 with log `full-pass-listening/tiera-export.log` (first attempt piped through tail, invisible; ~33 s/run in the build phase).
- **11-10 12:25 UTC — TIER A QUESTIONS PAGE LIVE:** https://claude.ai/artifact/AmBQajR2BvgA5tWeQ7no9Y (db, collection `rulings`, empty at publish; 68 open Qs + 79 majority decisions over 60 runs; 176 clips/127 MB in 2 publishes; source `storage/scratch/full-pass-questions-tiera`, answers-map.json; exported via tierb watcher container = clone template). Scans: bulk-candidates-tiera.php, dispute-oos-only-tiera.php. Proposed bulk list shown to operator (NOT written yet): R1 ×14 readings → none; R2 997-q1 → none; R4 1269-q0 → alt0 (linked); R3 1334-q0 → either. Held back for operator: 997-q0 (Thou vs My Great Redeemer = two different catalogue songs), 1309-q0 (Beatitudes vs Blessed Are the Poor in Spirit; A unlinked), 1134-q0 (13 s song, all 4 drafts agree it's a song, 3 titles of the Wesley hymn).
- **11-10 12:40 UTC — LISTENING ROUND 2 (operator, 121 items): 113 right, 4 can't-tell, 4 not right** (none stopped the pass; listening verdicts are not wired to the watcher). Operator observation: joins between reading and sermon (a) leave out the PRAYER FOR THE SERMON → should be included (1067; 936 has the same, unflagged because the clip didn't reach it); (b) cut over a short silence → unnecessary visible jump (992 5.2 s, 995 1.8 s — "not particularly bad"). Census (join-classes.php, 438 runs): prayer directly before sermon 34 runs; reading→sermon gap 0.5–15 s 91 runs (histogram ≤0.5:52, 0.5–5:63, 5–15:28, >15:5). Composer `SermonExtractionPlanResolver::compose` takes the concluding prayer only (D1 2026-10-02 in CUT-FROM-SECTIONS plan; 09-28 rule "prayer-before-sermon start difference = agreement"). Proposed as D1 AMENDMENT, post-pass (freeze): include a prayer section directly before the sermon; bridge reading→sermon gaps that hold no section/speech up to ~15 s. Other not-right: 932 join clips a word = reading section ends 1 s into the leader's song announcement ("Well, I want us to go to the cross again as we sing our" at 131.0, reading end 132.1); 936 start misses readings 1–2 of three (multi-reading membership); 962-841 children's talk should include the Exodus 17 reading before it (talks never take readings today). PAGE BUG FIXED: cues.php showed join clips' second half from the LEFT-OUT gap (service time) instead of the next part's start — 1067's "Let's pray" text was from the gap; page v7 retimed. Scripts: join-gaps.php, join-classes.php.
- **11-10 12:35 UTC tick:** no stops; main cut 51, ffmpeg 205 queued. Operator asked: pull affected (prayer/silent-join) runs from the cut queue? Answer: yes, after (1) D1-amendment + 15 s rulings, (2) exact query on stored compositions (the 125 = 34+91 is section adjacency only, excludes ≤0.5 s gaps), (3) autotick changed to explicit run lists (rerun-extract has no exclude; autotick passes none → would re-dispatch). Then pull queued chains saving payloads (WebM precedent), runs join the follow-up round.
- **11-10 12:40 UTC — OPERATOR RULED: D1 AMENDED.** Sermon media = sermon reading, **the prayer directly before the sermon (even with a song between reading and prayer, e.g. 936)**, the sermon, the concluding prayer; and **a gap between consecutive selected parts holding no other section, up to 15 s, plays straight through** (no silent cut). Not built yet — build on the fix branch (clone) with tests, record the amendment in CUT-FROM-SECTIONS plan D1 there, deploy at pass end.
- **11-10 12:42 UTC — 131 AFFECTED RUNS HELD for the follow-up round** (exact census `amended-d1-census.php` on stored compositions → `amended-d1.json`; 35 prayer, 126 silent join; list `full-pass-listening/amended-d1-hold.txt`). autotick now passes explicit run lists = members minus hold (`extract-runs.php`; old script `autotick-before-hold.sh`): main 307, Tier A 86. **68 queued ExtractSermon removed** (`hold-jobs.php --remove`; payloads `storage/app/private/full-pass-20261011/amended-d1-removed-jobs-20261011T124233Z.json`); ffmpeg 204 → 135 queued. Held runs sit processing/nothing queued (watcher warnings only). Already-cut held runs (~50) get re-cut in the follow-up. FOLLOW-UP ROUND = 131 amended-D1 + 16 duration-less + 1010/1023/1077, on new code, new snapshot.
- **11-10 12:55 UTC tick:** no stops; main cut 53; 133 ExtractSermon left (~Mon 06–07 UTC). Listening v8: 131 items (+1000, 1001, 1007, 1011 …).
- **11-10 13:05 UTC — RULING 5 (operator): sermon start/end disputes → take the EARLIEST start and the LATEST end of the versions** ("either is generally fine, but to be safe"). Applied on the Tier A page (sermon-span-candidates.php; docs in autotick/bulk-docs/span-*.json; writes create-only so operator answers can't be overwritten): 1111-q1, 1163-q0, 1253-q0 → alt0 (1253's earliest start holds the preacher's greeting + "Let me just pray before we look at God's word" = amended-D1 prayer). Asked operator: 1270-q0 (alt0 = latest end + majority, ref "Job 12:1-14:22"; alt1 same span, ref split per chapter; alt2 ends 39 s early, ref Job 13:20–14:22) and 1325-q0 (alt0 latest end ref Gen 46:31–47:12 vs alt1 ends 3.5 s earlier, ref adds 47:27) — refs differ, not span-only. Candidate equivalence rule for the composer (ensemble plan rule 5: encode recurring rulings).
- **11-10 13:20 UTC — ruling 5 applied further (operator agreed):** Tier A 1270-q0, 1325-q0 → alt0. MAIN page (LEAdv4…) 10 span-only sermon Qs → widest version (sermon-span-candidates-main.php; extra stretches checked: prayers before/after sermon, greetings, hymn announcements, 987's communion intro — no singing/other items): 987-q1 alt0, 1042-q0 alt1, 1078-q0 alt0, 1100-q1 alt2, 1135-q0 alt1, 1167-q0 alt1, 1214-q2 alt0, 1220-q0 alt1, 1260-q1 alt0, 1297-q1 alt1. Not covered, left to operator: 10 sermon Qs whose versions also differ in sermon_reference/title/oos (980, 1022, 1027, 1031, 1033, 1204, 1242, 1258, 1264-q1, 1288) and 4 "Sermon and Bible reading" split Qs (1178, 1188, 1190, 1363: one version starts the sermon at the reading). FIX-BRANCH TODO (agreed): (a) amended D1 in composer + plan; (b) ensemble equivalence rule: sermon versions differing only in start/end resolve to earliest start + latest end (no question). The 17 rules-1–4 Tier A candidates: still unwritten pending explicit go; operator answered several themselves.
- **11-10 13:40 UTC — operator delegated the remaining 14 main-page sermon Qs to Claude; written (notes "Claude for operator 2026-10-11 …"; docs autotick/bulk-docs/sermon-main-*.json; evidence script sermon-q-evidence.php).** Refinement of ruling 5 applied: widest version UNLESS the extra stretch is a separately identified item (notices, closing prayer, song introduction) — then the version that leaves it its own item; order-of-service title/link breaks ties. 980 A, 1022 A, 1027 B (keeps preacher's intro before the reading; its title "Sermon introduction: …" is weak — check the OoS title wins after apply), 1031 A, 1033 A, 1204 B (linked), 1242 A, 1258 A, 1264-q1 A (B linked the sermon to another OoS item), 1288 A; split Qs all "one sermon" (keeps the preacher's intro before the reading): 1178 B, 1188 B, 1190 A, 1363 B. Main page now 65 answers.
- **11-10 13:15 UTC tick:** no stops; main cut 55; ffmpeg 133 queued. Internal disk 19–20 GiB free (23 at launch; one 1.3 GB step 12:52→13:02, steady since) — watch it. 2 newly cut runs drew 0 listening items (recorded in sampled.json).
- **11-10 13:35 UTC tick:** no stops; main cut 55 (flat 20 min = one long cut, not a stall). ExtractSermon now 5–11 min each (avg ~8.5 min + follow-ups ≈ 6 cuts/h; the earlier 4-min average was skewed by short ones) → ~131 cuts ≈ 21 h → cutting done ~Mon 10:00–11:00 UTC (corrects the Mon 06–07 ETA). Operator asked for answer patterns; 4 proposed (position-based short readings incl. revising 9 rule-1 'none' answers on main page to keep; song span variants → either with catalogue check; linked beats unlinked with link-target check; real title over placeholder) — awaiting go.
- **11-10 14:00 UTC — OPERATOR ANSWER RULES (from their own answers) applied, 36 writes (apply-patterns.py → pattern-decisions.json; docs autotick/bulk-docs/pattern-*):** P1 short reading present in some drafts: a reading if 3+ verses (chapter-only ref needs ≥25 s; no ref → ≥25 s), remove if 1–2 verses or a closing blessing (last 5% of the service) — REPLACES rule 1 (≤50 s → none); 5 rule-1 answers on main revised to keep (941, 1197, 1236, 1303-q0, 1310). P2 versions of ONE catalogue song (song_title equal after stripping "#nnn", thee/thou→you): majority, else longest (operator: not "either"). P3 linked beats unlinked ONLY if the linked OoS item's title shares words with the version (or is an OoS "Reading" for a reading) — 1269-q0 (Ancient of Days linked to "Though The Nations Rage") and 1233-q0 (Yet Not I linked to "What gift of grace") are WRONG LINKS → operator. P4 real title over placeholder (Hymn N / Closing song / Unidentified singing / untitled). All candidates for the composer's equivalence rules on the fix branch.
- **11-10 13:55 UTC tick:** no stops; main cut 56. ETA CORRECTED AGAIN: earlier per-class tally parsed 'Nm Ss' durations as 0 — PrepareSectionPublicationCandidates is 1.6 min avg (up to 7.7). Real ffmpeg cost over 6 h = 9.9 min per cut (recent ones ~15) → ~128 cuts ≈ 21–32 h → main snapshot cutting done ~Mon 11:00–Mon evening UTC. Listening v9: 139 items (+1273, 1282).
- **11-10 14:15 UTC tick:** no stops; main cut 58, held 29 (+1346: sermon composition review — uncovered speech; 1346 was a parked run in canary 13). 1 newly cut run drew 0 listening items.
- **11-10 14:30 UTC — STOP #12 (main): run 1311 §4874 "I Will Sing of the Lamb" lost its extracted media** — song now song_match_type=inferred → not_applicable → not cut (inferred is ineligible BY DESIGN, [[inferred-song-match-is-the-threshold]]). Acknowledged (18), resumed 14:35, stop file `watch-stop-20261011T1430Z-1311-song-lost-oos-link.json` (name is wrong: the OoS link 10187 is intact; the MATCH was demoted).
- **SONG-IDENTITY REGRESSION FOUND (not fixed):** 62 songs that were `confirmed` with media in the snapshot are now `inferred` (936 confirmed→confirmed). Almost all now carry `song_title_marker_mismatch` (absent before) though titles are obvious hymns (Crown Him With Many Crowns, Amazing Grace, Abide With Me, Praise My Soul…). Current chapter markers (built by ServiceStructureEnsembleRulingApplier from section titles) match the titles exactly, so the flag was raised earlier in detection (validator `songTitleContradictsChapterMarker`, ServiceStructureValidator.php:528, compares section->songTitle with the covering marker) — cause NOT yet pinned; reproduce read-only on the clone. 44 runs: 12 already in amended-D1 hold; 32 others (none with a cut queued now; ~18 still processing → will stop the watcher as each is cut). Proposed to operator: add the not-yet-cut ones to the hold list, fix on the branch, re-run matching in the follow-up round.
- **11-10 14:43 UTC — song-regression runs HELD (operator: "do 1 and 2 now"):** 32 more runs appended to `amended-d1-hold.txt` (now 163; backup amended-d1-hold-before-songs.txt) — autotick main 275 / Tier A 80; 16 queued ExtractSermon removed (payloads `amended-d1-removed-jobs-20261011T144330Z.json`; my earlier "0 queued" check had a broken regex). Watcher rule (clone, UNCOMMITTED until Pint/PHPStan): HistoricRerunDiff::demotedToInferred — song confirmed→inferred that lost its clip → pending "song match demoted to inferred — clip withheld" (and its "left review" not custody); tests a_song_demoted_to_an_inferred_match_that_lost_its_clip_is_pending + a_confirmed_song_that_lost_its_clip_still_needs_attention; 47 diff+watch tests green. Restart both watchers after commit.
- **11-10 14:45 UTC — Tier A answers: all 68 answered by operator; 66 applied via structure:ensemble-apply-answers (slow: ~30 s/run, 55 runs, running in laravel.test since 14:39); 2 need corrected sections (997-q1 background music after service; 1066-q0 "a sermon on Solomon"). NEXT: historic-import:rerun-recompose full-pass-20261011-tierb/snapshot.json <answered runs minus hold> after apply finishes. Listening v10: 156 items (+1286, 1311, 1356).
- **11-10 15:05 UTC — demotion watcher rule COMMITTED 5b5999b38 on fix/webm-duration-fallback** (Pint pass; PHPStan app file 0 — the test file has 34 pre-existing errors outside phpstan.neon paths, none in new lines); both watchers restarted. Tier A apply run 1: 61 applied, 5 failed (cause unknown — output lost to tail), 2 need attention; re-run in background logging to `answers-final/tiera-apply-2.log` (already-applied are skipped).
- **11-10 15:14 UTC tick:** no stops; main cut 63. Apply re-run failures so far: 945-q0/q1 + 1096-q0/q1 'question no longer open' (run state moved on); 960-q0 'correction does not pass structural validation: multiple_sermons' (operator chose alt1 'Jesus, God's Great Gift') — look into.
- **11-10 15:17 UTC — Tier A answers applied (61; 5 failed: 945-q0/q1 + 1096-q0/q1 "no longer open", 960-q0 multiple_sermons; 2 neither need corrections) and RECOMPOSE DISPATCHED for 34 answered unheld Tier A runs** (log answers-final/tiera-recompose.log); 4 refused "already re-run on this commit" (1034, 1049, 1240, 1243). Runs still with open questions: 942 (1), 997 (1), 1269 (3), 1382 (1). autotick cuts recomposed runs not on the hold list.
- **11-10 15:35 UTC tick:** no stops; main cut 63; autotick dispatched 13 recomposed Tier A runs for cutting (main snapshot extract); ffmpeg 120 queued. Listening v11: 161 items (+1072, 1358).
- **11-10 15:45 UTC — MAIN page answers done (112); applying in background (log answers-final/main-apply.log; 113 ready, 4 need corrected sections: 930-q0 two readings + comment, transcription to redo; 944-q1 sermon with reading, all included; 1281-q1 two songs: Abba Father then I Bow Before the God of Matchless Care; 1363-q2 short talk). Operator notes worth following up: 1344-q0 alignment question showed NO options (page/export bug); 1363 "feels like two separate services joined" (concatenated source? check restage). NEXT: rerun-recompose full-pass-20261011/snapshot.json <answered main runs minus hold>.
- **11-10 15:43 UTC — STOP #13 (main): run 1079 §1742 song→other "Introduction to concluding song"** (same class as 992 STOP #10; never published). Queues paused 15:43–15:54 (no tick between). Acknowledged (19), stop file watch-stop-20261011T1543Z-1079-song-became-other.json. Second occurrence → propose clone watcher rule (song became a non-publishable type, old media never published → pending).
- **11-10 16:05 UTC — STOP #14 (main): run 1087 §3712 song→other "Introduction to concluding hymn"** (3rd of the class). Paused 16:05–16:14; acknowledged (20). **Retype rule COMMITTED 5f9a9c76d** on the clone branch (HistoricRerunDiff::retypedAwayFromPublication: type changed, now not_applicable, old never published → pending; published one still stops; tests a_never_published_song_retyped… / a_published_song_retyped…; 49 diff+watch green; Pint, PHPStan app 0); watchers restarted 16:28. TRAP: zsh `${=R}` word-split drops an empty `--entrypoint ""` → docker uses the image as entrypoint and the client HANGS; write docker commands out, don't store them in a variable. Background `docker run -i` also loses output.
- **11-10 16:26 UTC — MAIN answers applied: 102 applied, 11 failed** (duplicate_oos_item: 974-q0/q1, 1042-q0..q2, 1154-q0..q2 — a run's answers together link two sections to one OoS item, all rejected; "disputed section no longer matches the proposal": 1004-q0, 1281-q0, 1307-q0), 4 need corrected sections; 8 runs still have open questions. **RECOMPOSE DISPATCHED for 47 main runs** (answered minus hold; log answers-final/main-recompose.log; 1159 refused "already re-run on this commit").
- **11-10 16:35 UTC tick:** no stops (first looks on retype rule clean); main cut 67; ffmpeg 129 queued; 81 recomposed runs (34 Tier A + 47 main) on the LLM queue. Listening v12: 166 items (+1088, 1099).
- **11-10 16:54 UTC tick:** no stops; main cut 71. Recompose finished (LLM queue empty); autotick dispatched the answered runs → **162 ExtractSermon queued = exact remaining cut count** (+ none waiting on edges) → at 10–15 min/cut ≈ 27–40 h → cutting done ~Mon 20:00 – Tue 09:00 UTC. Offered operator a 5-min stop-only check (awaiting answer).
- **11-10 17:14 UTC tick:** no stops; main cut 73, held/failed 28; 2 new runs drew 0 listening items.
- **11-10 17:34 UTC tick:** no stops; main cut 74; ffmpeg 161 queued. Listening v13: 167 items (+1122).
- **11-10 17:54 UTC tick:** no stops; main cut 77. New failures: **1136 ExtractSermon "Invalid selected section bounds: … outside source"** — sermon §2112 ends 1221.0 s, source (Matroska/WebM) is 1220.2 s: a section end overshooting the media by <1 s is not clamped → FIX-BRANCH item 8 (clamp selected bounds to the source duration, or snap section ends to it); 1136 joins the follow-up round. 1155 sermon composition review (uncovered speech) — 11 in that class now. Listening v14: 168 items (+1124).
- **11-10 18:14 UTC tick:** no stops; main cut ~80; ffmpeg 154 queued. Listening v15: 171 items (+1156, 1157).
- **11-10 18:34 UTC tick:** no stops; main cut 80, held 31; ffmpeg working normally (ExtractSermon ~8 min; queue flat at 154 because recomposed runs keep arriving). feed.py crashes harmlessly on an empty inventory ([] not {}) — cosmetic.
- **11-10 ~18:50 UTC — operator wants cutting overnight; consistency with old cuts doesn't matter.** Proposed: hardware encoding (VideoToolbox on host — Docker VM can't reach the GPU; whisper/classifier already GPU on host) switched in by pulling queued cuts → deploy fix branch + encoder → new snapshot of all not-yet-cut runs (= follow-up round, earlier). Investigation prompt for ANOTHER session written: storage/scratch/full-pass-listening/PROMPT-hardware-video-encoding.md (read-only on live; spike in its own clone; report + patch, no deploy). Encoder seam: VideoToolbox → VideoExtractionService::videoEncoderArguments() ~l.348; MediaProcessingVersion records codec.
- **11-10 18:54 UTC tick:** no stops; main cut 83; ffmpeg 150 queued. Listening v16: 176 items (+1158, 1165).
- **11-10 19:14 UTC tick:** no stops; main cut 84; ffmpeg 150 queued; internal disk 19.3 GB.
- **11-10 19:20 UTC — LISTENING ROUND 3 (operator): 55 new items, 54 right, 1 can't-tell (1011 denoise: 'service has an echo, but the noise cancelling itself is fine').** Cumulative 176: 167 right, 5 can't-tell, 4 not right (all from round 2: 1067 prayer join, 932 clipped word, 936 missing readings, 962 talk reading). Echo/reverb on some recordings = possible future audio-treatment idea (dereverb), not a defect.
- **11-10 19:34 UTC tick:** no stops; main cut 85; ffmpeg 147 queued. Listening v17: 182 items (+1180 talks).
- **11-10 19:54 UTC tick:** no stops; main cut 87, held 32 (+1185 sermon composition review — 12 in that class: 933,956,964,981,982,1060,1062,1063,1110,1346,1155,1185).
- **11-10 20:14 UTC tick:** no stops; main cut 88; ffmpeg 144 queued.
- **11-10 20:34 UTC tick:** no stops; main cut 89; ffmpeg 142 queued; measured last hour 4 cuts/h → ~140 cuts ≈ 35 h → ~Tue 07:00 UTC on libx264. Listening v19: 198 items (+1191, 1198 — 1198 drew 13: many talks + denoise).
- **11-10 20:54 UTC tick:** no stops; main cut 91; ffmpeg 140 queued.
- **11-10 21:14 UTC tick:** no stops; main cut 93; ffmpeg 140 queued.
- **11-10 21:15 UTC — STOP #15 (main): run 1219 extraction failed 'Invalid selected section bounds … outside source' (same class as 1136, fix item 8); 3 unpublished working copies (§2697 talk, §2698/§2702 songs) not re-cut. Acknowledged (23), resumed 21:18 by the 5-min stop check (pause ~3 min). 1219 → follow-up round.
- **11-10 21:34 UTC tick:** no stops; main cut 95, held/failed 35 (+1216, 1226 composition review; 1219 bounds). Composition-review class now 19 runs: 933,953,956,964,981,982,1014,1026,1060,1062,1063,1110,1116,1155,1159,1185,1216,1226,1346 ("Resolve the identified sermon sections and uncovered speech") — growing ~1/h; worth a set review/rule. Listening v21: 200 items (+1206).
- **11-10 21:54 UTC tick:** no stops; main cut 95; ffmpeg 135 queued.
- **11-10 22:14 UTC tick:** no stops; main cut 97, held/failed 36; ffmpeg 134 queued.
  (22:14: 1238 joined sermon composition review → 20; listening v22: 204 items (+1212 talk).)
- **11-10 22:34 UTC tick:** no stops; main cut 97; ffmpeg 133 queued.
- **11-10 22:54 UTC tick:** no stops; main cut 98, held/failed 38; ffmpeg 131 queued. Listening v23: 211 items (+1237).
- **11-10 23:14 UTC tick:** no stops; main cut 100; ffmpeg 128 queued.
- **11-10 23:34 UTC tick:** no stops; main cut 101, held/failed 40; ffmpeg 127 queued.
- **11-10 23:54 UTC tick:** no stops; main cut 101; ffmpeg 125 queued.
- **12-10 00:14 UTC tick:** no stops; main cut 102. RATE DROPPED: PrepareSectionPublicationCandidates now 5.5–7 min/run (more song/talk clips per run), ExtractSermon 5–12 min → ~20–25 min ffmpeg per run ≈ 2.5–3 runs/h; ~100 cuts left → ~35–40 h → cutting done ~Tue 11:00–16:00 UTC on libx264 (hardware encoding matters more).
- **12-10 00:34 UTC tick:** no stops; main cut 103; ffmpeg 123 queued.
- **12-10 00:54 UTC tick:** no stops; main cut 105, held/failed 42 (+1322, 1332: sermon under content hold → parked; repair = sermons:re-extract <pid> --held-section=<id>, per [[repair-canary-held-sermon-baseline-recut-2026-09-17]]). Listening v27: 230 items.
- **12-10 01:14 UTC tick:** no stops; main cut 106; ffmpeg 118 queued.
- **12-10 01:34 UTC tick:** no stops; main cut 107, held/failed 44; ffmpeg 117 queued.
  (01:34: +1347 content hold, +1348 composition review → class 21. Listening v29: 236 items (+1335).)
- **12-10 ~01:50 UTC — STOP #16 (main): run 1339 song moved §4292→§166725 after a reading was inserted before it — known [[section-insert-deletes-extracted-media]] (sync by section_order); old clip pending_approval, never published. Acknowledged (24), resumed 01:54 by the stop check. Song now not_applicable/no media → follow-up round.
- **12-10 01:54 UTC tick:** queues confirmed running after STOP #16; main cut 109; ffmpeg 113 queued.
- **12-10 02:14 UTC tick:** no stops; main cut 109; ffmpeg 113 queued.
- **12-10 02:34 UTC tick:** no stops; main cut 109, held/failed 47 (+1374, 1375, 1380 sermon content holds → parked; content-hold class now 1322, 1332, 1347, 1374, 1375, 1380). ffmpeg 109 queued. 1 listening item pending (batched).
- **12-10 02:54 UTC tick:** no stops; main cut 111, held/failed 49 (+946 content hold → 7; +989 composition review → 22); Tier A held 6; ffmpeg 106 queued. Listening v31: 241 items.
- **12-10 03:14 UTC tick:** no stops; main cut 113; Tier A first cuts (2) done; ffmpeg 103 queued.
- **12-10 03:34 UTC tick:** no stops; main cut 115, held/failed 50; ffmpeg 101 queued.
- **12-10 03:54 UTC tick:** no stops; main cut 115; Tier A cut 4, held 8; ffmpeg 100 queued.
- **12-10 04:14 UTC tick:** no stops; main cut 116; Tier A cut 6, held 12. Content-hold parks now 11 (946,1057,1064,1068,1084,1322,1332,1347,1374,1375,1380); composition review 23 (+1049). ffmpeg 97 queued.
- **12-10 04:34 UTC tick:** no stops; main cut 119, held/failed 55; Tier A cut 7; ffmpeg 91 queued.
- **12-10 04:54 UTC tick:** no stops; main cut 120, held/failed 57; Tier A cut 9, held 14; ffmpeg 90 queued.
- **12-10 05:14 UTC tick:** no stops; main cut 121; Tier A cut 9, held 19; ffmpeg 88 queued. Content-hold parks 15, composition review 26 (+1179, 1194, 1218).
- **Content-hold parks grouped (15 runs, holds carried from earlier phases — content holds follow content):** short_transcript_loop_source_mismatch ×5 (946, 1064, 1084, 1121, 1176); duplicate-performance identity unresolved ×3 (1120, 1374, 1375); "saved sermon text repeats a loop" (P8-Q14) ×2 (1068, 1187); sermon MP3 ends 3–9 s before video ×2 (1332, 1347); one each: held_sample_semantic_substitution 946, reserved_fragmentation 1064, closing prayer has no section 1057, H10b wrong-transcript window 1322, title/reference describes a different talk 1380. Each re-cut via sermons:re-extract … --held-section once checked; the loop-mismatch group (5) is the biggest single batch.
- **12-10 05:34 UTC tick:** no stops; main cut 122, held/failed 62; Tier A cut 11, held 19; ffmpeg 85 queued. Listening v35: 260 items (+1056, 1118, 1130).
- **12-10 05:54 UTC tick:** no stops; main cut 125; Tier A cut 13, held 20; ffmpeg 79 queued (falling faster than earlier: ~3 jobs/10 min).
- **12-10 06:14 UTC tick:** no stops; main cut 125 (flat 30 min — a long cut); Tier A cut 13, held 21; ffmpeg 78 queued.
- **12-10 06:56 UTC tick:** no stops; main cut 125, held/failed 64; Tier A cut 13; ffmpeg 76 queued (ExtractSermon ~11 min/cut, worker healthy). Listening v36: 261 items (+1232 denoise p1).
- **12-10 07:13 UTC — STOP #17 (tierb): run 1275 §3444 "Angel Voices Ever Singing" lost media** — song publication review held it (song_looped_transcript: 92% repetition, song 83 has no catalogue lyrics) + cut plan invalid (edge_unresolved) → not_applicable; old working copy never published. Lasting state → exact text acknowledged in tierb watch-state; archived watch-stop-<ts>-1275-song-held-looped-transcript.json; watcher + 10 queues resumed ~07:16. Candidate fix: catalogue lyrics for song 83 (OpenLP). Tick: main cut 127, tierb cut 17; ffmpeg 73 queued. Listening v37: 269 items (+1240 — open check answered: its recompose took effect and it cut; +1275 sermon).
- **12-10 07:15 UTC — STOP #18 (main): same 1275 §3444 text** (1275 is in BOTH snapshots — acknowledge a shared run's lasting state in both watch-states at once). Acknowledged, archived, main watcher + 10 queues resumed ~07:18. Main progress 129 cut, 170 processing, 65 held/failed.
- **12-10 08:14 UTC tick:** no stops; main cut 130, held/failed 65; tierb cut 18, held/failed 23 (no new failed run — likely 1275 held song); ffmpeg 71 queued. Measured 06:20→07:53 ≈ 13 min/ffmpeg job → queue clears ~23:30 UTC Mon / early Tue. Listening v38: 270 items (+1279 denoise p2).
- **12-10 08:34 UTC tick:** no stops; main cut 130, held/failed 66 (+1: run 1313 completed 08:29 with a held section, no new failed run); tierb cut 19; ffmpeg 69 queued. 1313 sampled, drew 0 items → listening stays v38 (270).
- **12-10 08:54 UTC tick:** no stops; main cut 132, held/failed 68 (+2, no new failed runs — completed-with-held-sections); tierb cut 20, held/failed 25; ffmpeg 67 queued. Listening v39: 273 items (+988 talk "Daniel and Nebuchadnezzar’s Dream").
- **12-10 09:34 UTC tick:** no stops; main cut 135, held/failed 69; tierb cut 23; ffmpeg 62 queued (~11.5 min/job since 06:20 → clears ~21:30 UTC). Listening v40: 274 items (+1040 denoise p1).
- **12-10 10:14 UTC tick:** no stops; main cut 136, held/failed 69; tierb cut 25; ffmpeg 59 queued. Listening v41: 277 items (+1111 sermon start/join/end).
- **12-10 10:34 UTC tick:** no stops; main cut 140 (+4 in 20 min), held/failed 69; tierb cut 28; ffmpeg 56 queued. Listening v42: 287 items (+1127 talk denoise + missed stretch; +1129 two Release International talks what/start/end/denoise).
- **12-10 11:14 UTC tick:** no stops; main cut 141, held/failed 70; tierb cut 30, held 27; ffmpeg 55 queued. Listening v43: 288 items (+1199 talk denoise).

- **12-10 13:25 UTC (new session):** deleted leftover duplicate crons; ONE tick cron 7b28e9e8 + one-shot stop Monitor (30-min expiry, re-arm each time). State 13:11: main 149 cut/70 held, Tier B 37 cut/46 processing/27 held, ffmpeg 47 queued, no stops since takeover. Listening page v44 (298 items, 14 new clips) published; first publish is refused until the live page is viewed, then republish unchanged.
- **12-10 15:50 UTC — stop #19 (main):** failed job 494, run 973 `PrepareSectionPublicationCandidates` "Timing check: audio start or duration differs from selected sections" (SourceAwareMediaTimingChecker:94) — NEW class, run now failed/manual_review_required; add to fix-branch TODO. Archived `watch-stop-20261012T1550Z-973-timing-check-audio-differs.json`, started_at → 15:46:58, watcher restarted, queues continued. Listening v45 (308 items) current.
- **12-10 15:55 UTC — stop #20 (tierb):** same failed job 494 / run 973 (run is in BOTH snapshots) — handled identically, started_at → 15:46:58. Lesson: a failed job on a run in both snapshots stops BOTH watchers; move both `started_at` at once.
- **12-10 16:15 UTC:** listening page v46 (326 items, items 261–326 unlistened). State: main 160 cut/79 held, Tier B 43 cut/38 processing/29 held, ffmpeg ~27–30 queued, no stops since the 973 pair.
- **12-10 18:40 UTC:** listening page v47 (337 items, 262–337 unlistened). Main 171 cut/83 held; Tier B 43 cut/38 processing/29 held — Tier B's 38 are at transcribe_output_edges waiting for ExtractSermon on the shared ffmpeg queue (whisper/llm queues empty, whisper up), not stuck. ffmpeg 12–13 queued, ETA ~21:00 UTC.
- **12-10 19:45 UTC — correction:** Tier B's 38 'processing' runs are NOT waiting on the ffmpeg queue (my 17:54 note was wrong). All 38 are on the amended-D1 hold list (jobs deliberately pulled 10-11, last updated 10-11 09:16–11:22) and stay at transcribe_output_edges until the follow-up round. So Tier B is effectively finished at 43 cut + 29 held/failed + 38 held; pass ends when ffmpeg (5 queued) drains, ETA ~20:30 UTC.
- **12-10 19:46 UTC — stop #21 (main only):** failed jobs 495/496 = runs 1136 + 1219 ExtractSermon 'Invalid selected section bounds' (known fix-branch item 8; both were already on the 'failed for a fix' list). Archived watch-stop-20261012T1946Z-1136-1219-bounds-outside-source.json, started_at → 19:45:07, watcher restarted, queues continued. Not in Tier B.
- **12-10 19:49 UTC — stop #22 (tierb):** same failed jobs 495/496 stopped tierb too even though 1136/1219 are NOT in its snapshot — watchers react to ANY failed job after started_at, so ALWAYS move BOTH watch-states' started_at together. Tierb started_at → 19:45:07.
- **12-10 19:55 UTC — QUEUES DRAINED:** all 10 queues ready=0, unpaused; 1 ffmpeg job reserved (last). Main 175 cut / 88 held-or-failed / 101 processing (parked on the 163-run hold list) / 5 not_started; Tier B 43 cut / 29 held-failed / 38 parked. failed_jobs 493. NEXT (needs operator go): pass-end checklist — confirm last job done, final listening refresh, then fix-branch deploy with workers idle + HISTORIC_MEDIA_WORKERS_LLM back to 1, then follow-up round.
- **12-10 20:20 UTC — Amen-endings review PUBLISHED:** https://claude.ai/artifact/XK6Eg1XaeP2WDM3HimX7rn (134 items; scan/feed/build in storage/scratch/amen-endings-listening/; scan.php = both full-pass snapshots, completed runs, sermon video + sermon/short_talk sections; class = last cue ends on Amen / Amen cue within 3 s after the cut / last cue runs >0.4 s past the cut). Census 349 cut outputs: 115 end Amen, 16 Amen just after cut, 5 run past cut. Excluded for plan/file mismatch: 1240/2985 (stale Sep cut 184 s vs 279 s planned), 1352 sermon (+4 s). Queues all drained 20:06, no stops.
- **12-10 21:00 UTC — PASS FINISHED + final listening v48 (352 items; items 338–352 = 5 new runs, unlistened).** All queues drained 20:06, no stops since. Artifact manifest cap is 511 files/version (clips total 531): unpublished audio for 7 early items ruled 'right' (961-827, 967-1077, 1005-1237, 1025-1376 what/start/end, 1025-missed, 967 denoise, 1028-sermon-start) — files still in clips/, republish to restore; page will show no audio for those. NEXT clip-heavy refresh should move clips to the assets capability or a new page. Known bad: published clips/1180-2375-end-as.mp3 is a corrupt 565-byte file (re-cut + republish). Final counts: main 176 cut / 88 held-failed; tierb 43 cut / 29 held-failed; 38+ parked on hold list; failed_jobs 493.
- **12-10 21:30 UTC — ALL-ENDINGS review PUBLISHED:** https://claude.ai/artifact/TTqUhqX7PRL4UA338Gjyoc (340 items, last 3 s, options fine / word clipped / sentence cut off / can't tell; Amen-class endings first; storage/scratch/endings-listening/). Replaces the Amen page (XK6Eg1XaeP2WDM3HimX7rn) as the main check. 9 outputs excluded because the file on disk does not match the plan (see excluded-mismatch.json: 928/510, 1020/1344, 1102/1862, 1129/2060, 1180/2375, 1198/2486, 1217/2670, 1240/2985 talks + 1352 sermon) — likely stale/other cuts; investigate before follow-up round.
- **12-10 22:05 UTC — endings review result:** 340 ruled: 271 right, 39 word clipped, 28 sentence cut off, 2 can't tell (20% bad; Amen groups 19–44%, other endings 17%; sermons 23% / talks 12%; main 19% vs tierb 21%). Predictor: cut position vs transcript's last cue — inside cue 42% bad, ≤0.3s after 17%, 0.3–1s 13%, >1s after 0/36. Tail loudness weak. CAVEAT: 30 sermon files are 0.1–0.9s longer than plan so my 3s clips stopped short of the true end → re-check page https://claude.ai/artifact/9pF9taVUGv7MyewQBYYyFX (30 items cut from file end; 12 were earlier-bad, 18 right) at storage/scratch/endings-recheck/. NEXT (operator chose step 1 first): after re-listen, draft end rule (never cut inside a cue; last cue end + tail room, pause midpoint if speech follows) on fix branch, score with replay harness canary14-step0 using the 340 rulings (rulings export in scratchpad endings-rulings; re-export via ArtifactData list rulings on TTqUhqX7PRL4UA338Gjyoc) as answer key; re-cut in follow-up round (~11.5 min/video). Rulings of re-check page in artifact 9pF9taVUGv7MyewQBYYyFX.
- **12-10 22:20 UTC — RE-CHECK DONE (30 items cut from true file end):** 7 of the 12 earlier-bad now fine (my clip stopped short), 5 still bad, and 4 earlier-right are now bad (3 clips_word, 1 cut_sentence; extra tail hid it). Corrected totals over 340: 274 right / 39 word clipped / 25 sentence cut / 2 can't tell = 64 bad (19%). Cue-position pattern unchanged: inside last cue 28/67, 0–0.3 s after 32/190, 0.3–1 s 4/47, >1 s after 0/36. Answer key = endings page TTqUhqX7PRL4UA338Gjyoc rulings overridden by recheck page 9pF9taVUGv7MyewQBYYyFX rulings (30 ids end-X ↔ recheck-X). Open Q: sermon files are 0.1–0.9 s longer than plan (30 of 241 sermons) — what is the extra tail? NEXT: end rule on fix branch + replay scoring; operator to confirm.
