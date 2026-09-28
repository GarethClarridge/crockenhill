# Historic Video Defect Discovery and Acceptance Plan

> Formerly `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`. Renamed 2026-09-14
> once bulk processing was complete and the remaining work became discovering,
> containing and detecting defects, then proving acceptance.

> **Status — 2026-09-25: bulk processing is drained; containment, content
> acceptance and public release remain NO-GO.** Repairs now run through §4.0's corpus
> re-run: every eligible run re-detected against one frozen commit. Where the work stands:
>
> - **Freeze moved twice 2026-09-28 (§4.0 "Before the freeze", steps 8 and 9):** batch 1 failed as the canary on `adeab654c` (a reading inside a talk split the talk), then a detection evaluation against operator-ruled boundaries found the talk prompt fix (0 talk-count errors in 7 draws on gpt-5.6-luna). Canary on the step-9 commit is scored by that harness. Nothing is committed until the re-run ends. Canary 4 failed (936 supersession, 1304 hold).
>   **Canary 5 passed (2026-09-27, `e58978433`, 17 runs, both tiers and Tier C):** 0 rounds
>   needed attention, every music-plan §8 check held, 1304 is one song; Tier C completed 16
>   runs with 1262 parked for its held sermon, 4 flagged runs all explained, nothing public,
>   no hold lost. The operator then had "cut now, render later" built (§4.0 "Tier C
>   throughput"); **canary 6 (2026-09-27, `fa6dc5937`) measured it slower, and it was
>   withdrawn** (`5b60f2ee9`, whose code is identical to `e58978433`). The operator ruled to
>   freeze on the revert without a canary 7 (canary 5's evidence stands for identical code).
>   Canary 6's render check exposed a smart-cut join defect; the operator ruled to fix it and
>   every other flagged item before the freeze, done 2026-09-27 (§4.0 "Before the freeze",
>   step 6): frame-exact smart cut, a size check in place of source hashing, and a staging
>   probe name per process. **Next: snapshot and freeze on the fix commit.**
>   Six release-side items gate acceptance (§4.5) instead (operator, 2026-09-24).
> - **Detection:** the catalogue holds 79 classes: 47 promoted, 22 fixed at source, 4
>   decided not to detect, 6 unbuilt. The last full evaluation (38 detectors:
>   5 fail, 33 not established, 0 accepted) predates the 09-23 detectors, which score
>   `missed` until the re-run writes their output.
> - **Miss rate:** H10b re-decoded and compared all 338 decodable runs under a rule
>   fixed before decoding (tripwire 2.20%, under 3%), plus 99 restaged runs as batch 2.
>   The listening queue, **353 windows across 173 runs**, was judged by the operator on
>   2026-09-24 (352 judged, about 235 minutes): 128 runs route to Tier A, 45 to Tier B (§4.0);
>   126 routes are live (1112 and 1287 lapsed), so Tier A reaches 170 runs.
> - **Corpus re-run:** detection rounds for both tiers; detection reads only independently
>   sourced items; listening routes are frozen into the snapshot; the diff separates
>   quarantine from public and parked from failed (all 2026-09-25, §4.0). Tier C will park
>   74 runs held on a talk, 36 of them Tier A runs whose holds no code check can clear.
> - **Pre-freeze checks (2026-09-25, §4.0):** the pianist's hymn workbook agrees with 88% of
>   the video's songs on full recordings, and exposed two title-hint matcher defects, now fixed
>   before the freeze. The re-run stays blind to Email; duplicate catalogue songs are merged after
>   the freeze, before the convergence re-census. Half the workbook is held out for acceptance.
> - **Repairs since 09-20:** 1287 re-transcribed and re-detected 09-23.
> - **Operational:** temp-file cleanup is paused locally so restaged sources survive.
>   **All 438 eligible runs are reachable:** the 9 concatenated runs were restaged through the
>   new concatenation gate on 2026-09-24 (§4.0).
> - **Regular uploads:** the per-route fix audit is recorded in §4.
>
> The 2026-09-20/22 status block and the detailed §4.0–§4.3a workstreams moved
> verbatim to the [2026-09-12 to 09-23 execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md).
>
> Phases 0–8, their implementation diary, pass measurements and earlier reviews
> have moved unchanged to the
> [archived execution log](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md).
> Use that file only as evidence. This document contains the remaining work.
>
> The [Historic Import: Incremental Convergence Plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
> remains the programme-level authority for production rounds, convergence,
> release and one-shot retirement. This plan owns the video-output containment
> and acceptance work feeding its final convergence/release slice.

**Original date:** 2026-08-29
**Last condensed:** 2026-09-23 (previously 2026-09-12)
**Last reviewed:** 2026-09-25 (pre-freeze checks against the workbook, Email and catalogue; §4.0). Before that 2026-09-24. A critical review of the 09-23 condensation against the
code and the local database. It removed the excluded run 1051 from the canary and added
the catalogue's own talk cases, split the freeze gate into re-run and acceptance items,
decoupled Tier B from the listening queue, refreshed stale status lines, and linked each
carried item to its full text in the log. The 09-20 and 09-22 review headers and their
execution choices moved to the
[log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md#moved-from-the-plan-on-2026-09-24).

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
| Phase 9 | Blocked | Missing holds and current-policy failures below must be contained before final convergence and release. |

The archive retains the exact M1–M12 and P8-Q1–P8-Q16 narratives, commands,
measurements, hashes, repair sessions and superseded conclusions. Do not copy
those histories back here; link to the relevant anchor when they explain a new
decision.

## 3. Current measured state

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
remain in the preserved follow-up review, now in the [log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md). They are not
fresh readability checks or current missing-hold counts. The five confirmed sermon
loops and §§988/1457/3869 were held on 13 September (§4.1).

## 4. Remaining work in execution order

**Execution model (operator decisions 2026-09-23, refined 2026-09-24).** §4.0's corpus
re-run is the repair route. Before the freeze, a run is repaired only as a named exception
with its own reason, as 1287 was on 09-23, and through the preflight below. The detector
programme is no longer an alternative to background batches: the freeze waits for it.
This plan revision authorises no new processing membership or release.

### 4.0 Corpus re-run — operator decisions 2026-09-23

Most fixes since 09-15 act at structure detection or song matching (1493 prompt seconds,
sustained-sound widening and bridge, speech-edge trim, neighbour same-song rule, catalogue
title resolution, mistyped-sung flag, lyric identity check). They reach a run only when it
is re-detected, so nearly every run with a song section is affected, and selecting runs
costs more than re-running them. **Operator decisions:**

1. **Freeze detection code only after every open item that changes the re-run's output is
   closed.** Each item is either built and tested, or recorded as a decision not to detect.
   The pass then runs against one commit, and its evidence binds that commit. The gate
   (catalogue state 2026-09-24: 47 promoted, 22 fixed at source, 4 decided not to detect,
   6 unbuilt; **every item that gates the freeze is closed**):
   - Structure/typing: ~~`structure-hymn-inside-sermon-section`~~ (built `994446a12`),
     ~~`structure-spoken-quotation-typed-as-song`~~ and
     ~~`detection-unplaced-hold-refusal-discarded`~~ (both already fixed; recorded `5a071a4af`),
     ~~`song-section-without-a-song`~~ (built `c70ad59cf`; the doxology after Old Hundredth,
     §678/§3284, stays a **named limitation** by operator ruling 2026-09-23),
     ~~`talk-typed-other`~~ — **gates the freeze** (operator ruling 2026-09-23): talks plan PR1
     and PR3 (`short_talk` detection) land before the freeze, so the corpus re-run detects
     short talks in the same pass. *Landed 2026-09-23 (`887d73373`, `7e9eb824f`); PR2 and PR4
     too. The catalogue entry is `FixedAtSource` (09-24). The talks plan's own measurement and PR5 re-detection pass moved into this re-run
     (operator, 2026-09-24): the canary below checks the prompt, and Tier B re-detects the
     191-section bucket.*
   - Transcript/audio: ~~`transcript-meaning-changing-substitution`~~ (**decided not to detect**,
     operator 2026-09-24: no textual signal; H10b's re-decode sampling measures the class; both
     cases stay held),
     ~~`audio-dropout-inside-talk`~~ (promoted 2026-09-24: `AudioDropoutInsideTalk` runs last
     in the sound stage and flags a sermon or short talk that overlaps 15 s or more of source
     audio at or below −80 dB. The flag sends the talk to review, to be accepted or excluded.
     It does not stop extraction, because the cut is not in question. Replayed read-only
     through `structure:recompute-sound-stage` over all 443 eligible runs
     (`storage/scratch/dropout-20260924/`): it flags 10 talks, which are exactly the §4.1a
     census hits whose section is still a talk. Run 980's dropouts now fall in the `other`
     section before its sermon, and 1043 is excluded. It found nothing new. The `Prototype`
     status had no entries left and was removed. **Harness note, resolved 2026-09-24:** the `--all` pass listed 9
     and left out 1089 §1790, which named-run passes flagged. 1089 is excluded (the 2025-05-24
     rehearsal duplicate of 1088), and `--all` skips excluded runs while `--run=` did not. Named
     runs now obey the same eligibility and are listed as skipped, so 9 is the right count),
   - Scripture: ~~`scripture-reference-never-linked`~~ (fixed at source 2026-09-24: the
     backfill takes the newest unlinked sermons first, and every re-run diff flags a reference
     with no passage; 908–910 and 913–915 are still unlinked and the re-run re-queues them),
     ~~`scripture-multi-passage-truncated`~~ (fixed at source 2026-09-24: linking keeps a
     multi-passage reference whole; the four regain theirs when the re-run re-derives
     analysis. **Named limitation:** the passage text shows the first part only, because one
     stored passage carries one api.bible FUMS usage token),
     ~~`scripture-verse-in-prayer-typed-as-reading`~~ (**decided not to detect**, operator
     2026-09-24: the one case is a call-to-worship verse in excluded run 1043, whose signals
     match 22 genuine readings)
   - Identity/metadata: ~~`oos-item-written-from-wrong-song`~~ (fixed at source 2026-09-24.
     **The re-run could not have rebound a confirmed song.** A re-detection kept a section's
     `confirmed` type, and on a changed section dropped the match record behind it. Matching
     skips confirmed sections, so none of the corrected identity rules (two sources, lyric
     check, catalogue titles) reached one: all 136 historic confirmed songs with no match
     record are re-detected rows. Now a re-detected song goes back to matching unless a person
     reviewed its match (none has). An item the run wrote loses a song its section no longer
     confirms, and a song a person linked stays. Read-only measurement
     (`storage/scratch/oositem-20260924/`): **59 run-written items carry a song other than the
     one their heard title resolves to deterministically**, among them all the §4.1a pairs and
     published §4156. The re-run repairs them. Before this fix, all 59 would have survived it),
     ~~`published-title-contradicts-content`~~ (promoted 2026-09-24 as a reference check,
     operator choice: `published_reference_contradicts_sermon` on the sermon section when the
     published reference shares no verse with the heard `sermon_reference`, raised at analysis
     and on every reference edit. Read-only measurement over 425 historic sermons with both
     (`storage/scratch/rerun-route-20260924/published-vs-heard.json`): the 6 that disagree are
     exactly 881, 899, 954, 844, 845 and 850. The provenance refusal was not built: it would
     add only 868, whose reference agrees)
   - Carried §4.3 items outside the catalogue: ~~the continuous-speech boundary check (§988)~~
     (built `4abce5f0c`), ~~`confirmed` redefined as two independent sources~~ (built
     `8ae957628`; operator ruling: **any two** of heard, sung, projected, planned — 126 of 1,185
     current bindings would become inferred, against 231 under the stricter plan wording),
     ~~speech under looped sung text~~ (built `cb024a9a6`), and ~~song edge into an adjoining
     section~~ (**corrected at source**, operator 2026-09-24, reversing the same day's "hold is
     the outcome"). `SongLyricEdgeExtension` runs as `ExtendSongsOverOwnLyrics`, after
     `MergeSongContinuations` and before any clip is cut. It acts only on an edge
     `song-lyrics-outside-section` holds, and it moves only the song's own bound: the neighbour
     keeps its span, and candidate preparation re-cuts on the changed media signature. It never
     touches a published, approved or rejected section. Walking outward, it extends over this
     song's own lyric lines. It stops at an announcement, at a line the neighbouring song
     explains as well, at more than two other lines in a row, or at a pause over 15 s. It needs
     two lines; one line moves the edge only when it began within 3 s of it (operator: all three
     holder groups, and the nudge). The hold stays as the fallback and re-checks the corrected
     span.
     - *Measured* (`storage/scratch/songedgefix-20260924/`). The 42 held edges were adjudicated
       against timestamped whisper.cpp probes of the source audio: 12 real (5 s or more), 8 small,
       17 wrong, 2 in sections bound to the wrong song (985, 1215), 3 unclear. The 09-15
       per-edge verdicts were never banked.
       - Replaying the built class (rolled back) clears 24 of the 42 holds. It corrects 9 of the
         12 real edges in full and 1308 in part (still held), recovering 209 of 353 missed
         seconds with no overshoot.
       - 1250 and 1035 are beyond the transcript: whisper heard almost none of their missing
         verses. On today's bounds 1035 clears on a 0.2 s nudge while missing its untranscribed first
         verse; the re-run's sustained-sound widening (09-15 replay: §4813 510 s → 475 s) covers it
         at detection, before this step runs.
       - False alarms that move: 1305 clears with 6.6 s of speech, and five nudges of up to
         2.2 s clear (981, 1145, 1180, 1224, 1233). 1262 moves 6 s into the previous song and
         stays held.
       - Sound under the lines did not separate the cases line by line (1291's sung lines read
         as unsustained), so it only decides which edges are held.
     - **Named limitation (narrower):** a clip still misses lines the transcript never caught,
       lines past an announcement, and lines in a section bound to the wrong song. Those stay
       held until adjudicated.
     - ~~Untranscribed opening or ending~~ (**built 2026-09-24**, operator: both edges; "we
       rarely deviate from the verse order recorded in the catalogue").
       `song-opening-missing` / `song-closing-missing` (`SongOpeningAndClosing`, boundary
       evidence version 8, `song_ends`) holds a clip whose first placed line belongs to a later
       verse than the sequence's first, or whose last belongs to an earlier verse than its last.
       The placed line must be within 20 s of the edge, and the 10 s beyond the edge must be
       mostly sustained sound.
       - Lines are placed by content-word pairs unique to one verse. The sequence is
         `verse_order` (named verses only), or the document order, in which case any chorus may
         close. A first-verse reprise may also close.
       - It holds rather than moves an edge: it knows *that* the opening is gone, not where the
         song began.
       - *Measured* (`storage/scratch/songentry-20260924/`): over 1,177 song sections, 53 edges
         placed within 20 s, adjudicated by fresh audio beyond the edge. Openings: 11 of 13
         truncations raise; the misses are 1225 (the recording starts mid-song) and one never
         placed. 1 of 9 false alarms raises (1168 §2304). Closings: 5 of 6 truncations raise;
         1267 is missed (quiet sound). 1 of 13 false alarms raises (1119, a reading over music).
       - Without the sound test, 9 of 22 openings and 14 of 30 closings were false alarms:
         skipped or untranscribed verses beside a prayer or reading.
       - It also found a parser fault: a recorded order's unnamed verses were appended to the
         sung sequence (971 §1092's bridge read as the song's end). `OpenLpLyricsParser::sequence()`
         now keeps only named verses; `lyrics_plain` is unchanged.
   - **Gate acceptance, not the freeze (operator, 2026-09-24):** `identity-duplicate-date-pair`,
     `membership-missing-occasion`, `membership-rehearsal-imported-as-service`,
     `staging-held-candidates-not-promoted`, `release-media-file-missing` and
     `video-discredited-verdict-unreassessable`. They read stored outputs, custody or
     membership, not the re-run's output, and this corpus's occasion exclusions and
     identity holds are already applied. §4.5 still requires each before historic acceptance.
   - Each built rule was measured read-only over the corpus before commit; none was applied to
     existing rows, which change only when the re-run re-detects them. Evidence:
     `storage/scratch/{sungspan,songwithout,swallow,speechloop,confirmed}-20260923-*.json`.
2. **Tier A (re-transcription) waits for the operator's H10b listening queue.** Runs are
   chosen from the listening results, not from the tripwire alone. Only the runs in the
   queue wait; the rest start at the freeze (operator, 2026-09-24).
   **Listening done and routed 2026-09-24** (`storage/scratch/h10b-corpus-20260922/listening/
   routing-20260924.json`, bound by hash to `verdicts-20260924-operator-mapping.json`): of the
   173 queued runs, **128 "new better"** (the fresh decode right wherever it differed) go to
   **Tier A**; **16 "stored better"** go to **Tier B**; **11 "mixed"** (930, 944, 973, 975, 978,
   1051, 1211, 1322, 1330, 1377, 1378) keep their stored text in **Tier B**, with a hold on each
   window where it is wrong, because re-transcribing trades one error for another; **18
   "neither"** (only both-wrong or can't-tell windows: 936, 993, 1014, 1016, 1034, 1089, 1104,
   1138, 1167, 1214, 1262, 1321, 1325, 1332, 1336, 1347, 1351, 1367) go to **Tier B** with a
   hold on each both-wrong window, since neither decode fixes it and the hold keeps it out of
   release (operator rulings, 2026-09-24). Re-transcribing every run was considered and
   rejected: the new decode was wrong in 58 of 352 windows.
3. **All eligible historic runs are re-run**, not only those predicted to change. The
   censuses keep finding classes nobody predicted, and the diff report surfaces them.

**Shape.** Tier A re-transcribes (H10b-selected runs plus the contained transcript-loss
cases). Tier B re-detects from structure detection. At the freeze, Tier B takes every
eligible run that is neither in the listening queue (173 runs since batch 3,
`storage/scratch/h10b-corpus-20260922/listening-queue*.json`) nor held for transcript
loss. After listening, each queued run joins Tier A or Tier B, so no run is processed
twice.

*Held for transcript loss* means a live content hold (neither cleared nor released) that
says the stored text is wrong where the audio is not: `found_by` = `loop_screen`, or a
`source_audio` hold whose reason is a short loop, fragmentation, sparse-cadence loss or
BC-08 drift (`short_transcript_loop_source_mismatch`, `held_sample_short_transcript_loop`,
`reserved_fragmentation_source_mismatch`, "Saved sermon text repeats a loop…", "…lost a
passage to a sparse…", "Blind review BC-08…"). Holds on the source itself (silence,
cut-off recordings), on boundaries or on song identity are not transcript loss. A run
counts only while its hold's `transcript_sha256` matches the stored transcript, so a run
already re-transcribed at `max_context=0` goes to Tier B. **Measured read-only 2026-09-24**
(`storage/scratch/tier-b-transcript-loss-20260924.{php,json}`): 67 runs hold such a record,
3 on text since replaced (1258, 1314, 1343), leaving **64**, of which 26 are also queued.
So **202 runs wait** for listening, and Tier B can start on the rest (about 235 of 437).
**Batch 3 (2026-09-24)** adds the nine concatenated runs to the queue and none to the 64, so
**211 wait** and about 226 start;
recount both at the freeze. Tier B rounds cut no media (detection rounds, below); Tier C cuts it once, on the frozen commit, for every run whose round deferred it. Everything lands
in quarantine; release stays with §4.5.

**Membership.** Eligible means historic, completed, not superseded and not excluded (437 at
the 09-16 census; H10b's 455 includes excluded runs). It is recounted and hashed at the
freeze, and the diff report binds that hash.

**To build before the pass:**
- [x] A per-run **before/after diff report**: sections, types, spans, bindings, holds (with
  `found_by`), review flags and extraction plans. It is snapshotted before dispatch and
  compared after, so review reads "what changed and why" instead of re-examining every run.
  *Built 2026-09-24:* `historic-import:rerun-snapshot` writes a create-once private file
  holding the exact membership, its hash, the commit and each run's state, and
  `historic-import:rerun-diff` re-captures those runs and reports each change by kind.
  Sections pair by span overlap, never by order (an insert renumbers every later section).
  Holds are keyed by what they claim across the whole run, so a carried hold reads as
  carried. **Attention** (and a failing exit) is a live hold gone, a section leaving review,
  becoming published or losing its media, or a run the re-run leaves unfinished. A read-only
  probe over the 13 canary runs captured every transcript inside its staging context and
  diffed clean.
- [x] A bounded **re-detect dispatch route**, tested. `historic-import:retranscribe-video-run`
  is restricted to four runs.
  *Built 2026-09-24:* `historic-import:rerun-redetect {snapshot} [runs] [--max=10] [--execute]`
  re-detects from structure detection through the orchestrator's existing entry point, now
  on explicit `CorpusRerun` grounds. The snapshot is the batch. A run is refused unless it is
  a member; the snapshot was taken on the running commit; it is completed, not excluded and
  unchanged since the snapshot; it was not already re-run on this commit (the dispatch is
  stamped on the run under `corpus_rerun`, so a batch resumes and a canary run is not re-run
  by its batch); it passes the shared re-detection guards (not superseded, source present);
  and its staged source hashes to the recorded hash. A dry run hashes too, at about 47 s
  a run (11 canary runs took 8.5 min), so a full-corpus dry run takes hours. Run it per batch.
  The route checks runs, not workers: the preflight below still applies.
  **Staged-source census, read-only 2026-09-24**
  (`storage/scratch/rerun-route-20260924/staged-source-census.json`): 438 eligible runs
  (recount at the freeze). **429 are single-part with a present, hashed source; all 9
  concatenated runs are blocked:** 930, 936, 940, 942, 944, 950 and 975 have no staged
  source, and the other two have no recorded hash. 940/942/944 are three of the five runs the 09-14
  concatenation-song ruling says must be re-detected. They need restaging through a
  concatenation gate (each part against its recorded sha256 and the rebuilt duration against
  the run's, as the run-950 note prescribes), which is not built. Until then they stay as
  they are, quarantined.
- [x] **Restage the nine concatenated runs through a concatenation gate** (found 2026-09-24,
  census above). 930, 936, 940, 942, 944, 950 and 975 have no staged source, and two more have
  no recorded hash, so the re-detect route refuses all nine. Build the gate the run-950 note
  prescribes: each archive part against its recorded sha256 in `historic_import.sources[]`,
  and the rebuilt file's duration against the run's recorded `duration`. The byte hash of a
  concatenation is the wrong gate, because a different ffmpeg writes different container
  bytes over the same timeline. Then restage the nine and verify them. They stay quarantined
  until this is done. It blocks the 09-14 concatenation-song ruling's re-detection of 940,
  942 and 944 (below), and 936's return to the canary.
  *Done 2026-09-24:* `historic-import:restage-concatenated-source {run} [--execute]`
  (`ConcatenatedSourceRestage`) checks every part's size and sha256 in manifest order, joins
  them with the importer's recipe, and requires the join's duration within 0.01 s of the run's
  and its codec fingerprint to match. A file already staged is kept only if it carries exactly
  the join's packets (`streamhash`); the gate never overwrites one. The staged file's sha256 is
  stamped under `concatenated_source_restage`, and `stagedSourceFileHash()` (the re-run route
  and the re-decoder) checks that stamp. `file_hash` stays as the original join's
  provenance. **Matroska never writes the same bytes twice:** it writes a random segment UID
  and the date, so 975's verify join and its real restage hashed differently from the same
  parts, on the same ffmpeg, in the same minute. That is why no concatenation can be restored
  byte-identical. Result (`storage/scratch/concat-gate-20260924/`): **9/9 pass, every duration
  exact to the microsecond.** Seven were rebuilt (930, 936, 940, 942, 944, 950, 975). For 973
  and 1014, the original join still staged matched the rebuild's packets and was stamped as it
  is. All nine then passed the route's `StagedSourceVerification`.
- [ ] **Canary, run on the candidate freeze commit.** If it passes, that commit is frozen. A
  failure is fixed and the canary re-run on the new commit, so no detection change lands
  after the freeze.
  *First run 2026-09-24 on `3e90c62ee` (snapshot `canary-20260924/before.json`): failed on 949.*
  964 §872 → #304 and 1250 §3128 → #408, both `consistent`; the short-talk truth set passed
  except **949 §719**, which split into four `short_talk`s (Elmstead, Court Farm, Cold Harbour
  and Crockenhill "share and prayer"). **Ruled (operator):** a sharing-and-prayer time is
  `prayer`, never a talk; a partner presenting its work stays `short_talk`. Fixed in the
  prompt; the whole canary re-runs on the new commit. Watch 936 §608 (a reading applied to
  persecuted Christians, into prayer), which should follow 949, and 1025 and 936 §606, which
  must stay talks. 1112 parked at extraction as designed (its 09-13 operator-written
  sparse-cadence hold carried to the re-detected sermon §4862). The re-run is a detection round
  (below): its pass is judged on the diff with media custody pending, and the first run's
  full-media diff is the evidence for the media path until Tier C.
  **First run's full-media diff (`canary-20260924/diff-final.json`): custody held.** Nothing
  became public. Seven songs auto-published into **quarantine** (`publication_state:
  quarantined`), which is the historic path's designed outcome pending §4.5; four of them had
  been held only by the boundary-evidence backfill and now read clean on the re-run's evidence
  (1025 §1379, 1108 §1894, 1250 §3122 rebound to #1047 with its lyric hold cleared, 1311 §3946).
  Of the 23 sections the diff reported as leaving review, 15 are back in `pending_approval`
  (song review is a publication state, not `needs_manual_review`, which only the backfill set),
  those four are quarantined, two sermons cleared `sermon_text_predates_evidence` with new text,
  and 1112's two songs wait on its parked sermon. Two merged sections lost their old clips
  (1262 §3284, the doxology limitation; 1311 §3950 into the baptism hymn). 1112 is parked for
  its held sermon, so the second run cannot reach it until that is re-cut.
  Also from the first run: 1304 §3869, held as "two songs joined", was split by re-detection
  (141–263 s plus a new song 263–370 s) with its hold carried to the corrected section; the
  short-talk truth set missed two *proposed* types (1358 §4684 proposed nothing, not
  `childrens_talk`; 1221 §2721's `childrens_talk` proposal dropped to null), which does not
  block; and `talk_speaker_review` was not raised on 964 §874 because speaker identification is
  off locally (`SPEAKER_IDENTIFICATION_ENABLED=false`), so no local run, Tier C included, raises
  it; every talk still needs approval. A diff taken while runs are still processing cannot be
  read for custody: clips are re-cut and song review is decided only at the end of the chain
  (the detection-round diff now lists these as pending instead).
  *Second run 2026-09-24, a detection round on `2c6147f91` (snapshot
  `canary2-20260924/before.json`; 12 runs, 1112 refused as parked): failed on 949 again.*
  Custody was clean (0 attention; media pending on all 12, as designed) and the round took
  about 15 minutes with no ffmpeg work. But 949's four church slots stayed `short_talk`, and
  936 §609 stayed a talk, although the model was called with the new prompt. **Cause:
  self-anchoring.** Detection passes the model every order-of-service item as the planned
  service (`DetectServiceStructure::loadOosItems()`), including items the pipeline's own
  projection wrote. All of 949's items are `source: livestream`, and the first canary's
  projection wrote the four slots into them as `short_talk` items, which the second run then
  followed. Read-only census: of 442 eligible runs, **148 have an order of service written
  entirely by their own earlier projection and 288 partly**; one is fully independent. So
  repeated rounds are not independent, and every run's detection partly checks itself
  against its August output. **Ruled (operator, 2026-09-24):** detection reads only items with
  independent evidence (email, OpenLP or manual, by `provenanceSources()`, never the `source`
  column); projection and merge are unchanged. Also to read on the next run: 1262 §3281, a
  31 s "Introduction to prison resourcing update", became a `short_talk` (`partner_update`),
  a probable false positive; 1356's "unclear" §4483 became a 774 s song. The 12 runs now read
  `media: deferred`: clips on sections whose spans moved are gone until Tier C cuts them at the
  freeze (quarantined and re-derivable).
  *Third run (planned 2026-09-25), after the five ruled builds, all 13 runs.* Snapshot with
  the bound routing file, so each run takes its tier from the frozen routes: **Tier A (live
  `new_better`): 1250, 1304, 1356, 949**; **Tier B: 964, 1108, 1025, 1112 (route lapsed, hold
  released), 1358, 1221, 1311, 1262 (neither; §3287 now held), 936 (neither)**. Both are
  detection rounds, so the pass is judged on the diff with media pending. Checks beyond the
  truth set: 949's four slots become `prayer` (self-anchoring fixed; 949 has no independent
  items, so it is detected from its transcript alone); 1311's baptisms leave the song (§3950)
  and §3949 stays a `short_talk`; 1304 §3871 stays non-talk; read 1262 §3281 (31 s
  introduction) and 1356 §4483 (774 s song) again.
  **Result 2026-09-25 (`canary3-20260925/diff-round.json`): every truth-set check passed;
  custody clean (0 attention, media pending on 8 runs).** 949's four slots are `prayer`
  (§722, §723, §4854, §4855), so self-anchoring is fixed. 1311's three baptisms are `other`
  sections (§3955, §4865, §4874), each hymn between them its own song, and the testimonies
  are `short_talk`s proposing `testimony`. 1304's "Baptism of Roy" (§3874), 1262's Queen
  reflection (§3279), 936's pre-service audio and 949 stay non-talk. 1108 §1895, 1025 §1375
  (`partner_update`), 1112 §3735 (`testimony`), 1358 §4684 and 1221 §2721 are `short_talk`.
  964 §872 → #304 and 1250 §3128 → #408, both `consistent`. 1262 §3281 is now a 31 s
  `other` (the false positive is gone). 1356's 774 s song is a 173 s song, a prayer and a
  reading, and its cut now joins the preached reading (927–1039 s) to the sermon
  (1234–3491 s); the old span began at 1446 s, losing the sermon's opening (the 09-17
  macro-song class). All nine listening holds are live and followed their content (1262's
  moved to sermon §3288). **Open, not blocking:** 1311 §3953 (46 s) is a `short_talk`
  "Commendation of Naomi's Testimony": her father's comment plus the leader's link into the
  baptisms, probably not a talk. 1358 §4684 proposes no type (not `childrens_talk`), a
  missed proposal the operator settles at approval.
  **Runs 964 (§872) and 1250 (§3128)**, the first use of the diff report. Current
  code resolves both hints correctly (#304, #408). The canary checks that re-detection
  rebinds them, that sync overwrites the stale livestream items 6901/9371 rather than
  anchoring on them, and that `lyric_identity_check` reads `consistent`. They are not rebound
  through the review queue in the meantime (no clip, not exposed).
  **Plus nine short-talk runs** from the talks plan's §3 truth set, which stand in for that
  plan's retired measurement (operator, 2026-09-24). The new prompt widens `short_talk` to
  any substantial spoken item that is not the sermon, so the likelier failure is a false
  positive; the set is weighted to the "not talks" cluster accordingly.
  *Must become `short_talk`:* **1108** (§1895, Heidelberg Q122 — proposed `childrens_talk`
  only if the transcript carries the prompt's children cues, else null; a catechism talk
  without them is exactly BC-07's case), **1025** (§1374, Release International —
  `partner_update`), **1112** (§3735, Gavin Peacock — `testimony`), **1358** (§4684,
  "Augustine of Hippo" — `childrens_talk`; a catalogue regression case whose title does not
  say it is a talk).
  *Must stay `short_talk`:* **1221** (§2721, Heidelberg "the fall" — the other catalogue
  regression case; already `short_talk` since 09-21).
  *Mixed:* **1311** — §3949 "Baptismal testimonies" becomes `short_talk` (`testimony`) while
  §3951 "Baptisms" stays non-talk.
  *Must stay non-talk:* **1304** (§3871, "Baptism of Roy" — an ordinance), **1262** (§3279, "Reflection and prayer for Queen Elizabeth
  II"), **949** (§719, "Church sharing and prayer"), **936** (§600, "Pre-service
  preparation").
  Three of the five positives carry their answer in the title, so this checks the rule's
  exclusions more than its recall; recall over the untitled cases is read from the Tier B
  diff report of the 191-section bucket. Pass = every section as expected in the diff report.
  A miss is a prompt fix before the batches (`feedback_measure_before_generalizing_a_fix`); a
  missed *proposed type* alone does not block, because the operator confirms every type at
  approval.
  **Canary reachability, checked 2026-09-24 against the route's dry run and the listening
  queue. Ruled the same day (operator): keep the queued runs and accept that one may be
  re-detected twice if listening later sends it to Tier A. 1356 §4484 replaces 935 §599 as
  the long unidentified `other`; 936 stays out until its concatenated source is restaged.
  Canary: 964, 1250, 1108, 1025, 1112, 1358, 1221, 1311, 1304, 1356, 1262, 949.**
  **936 rejoins (2026-09-24):** its source was restaged through the concatenation gate, so the
  canary regains the "Pre-service preparation" must-stay-non-talk case. It is also the canary's
  one concatenated run, which covers the concatenated path the preflight asks for.
  Canary: 964, 1250, 1108, 1025, 1112, 1358, 1221, 1311, 1304, 1356, 1262, 949, **936**.
  **1112 goes through Tier A (operator, 2026-09-24).** It is one of the 64 runs held for
  transcript loss. Re-detected as Tier B, it would be stamped on the freeze commit, and Tier A
  could never reach it on that commit. So the canary re-transcribes it
  (`rerun-retranscribe <snapshot> 1112`) and its short-talk check reads corrected text. That
  also exercises the Tier A route before any batch. Tier B now refuses a run held for
  transcript loss, so `rerun-redetect` on the whole canary snapshot skips 1112 whichever
  command runs first.
  - The review's replacement for 1051, 935 §599, is no more re-detectable than 1051. Run 935
    is failed and was **superseded by 936 on 08-27** (the "misread a whole service" case). The
    next-closest long unidentified `other`, 930 §534, is a concatenation with no staged
    source. The only reachable section like it is **1356 §4484** ("Unclear transition",
    660 s).
  - **936** (§600) is also a concatenation with no staged source, so it cannot be re-detected
    either. *(Restaged 2026-09-24; it rejoins the canary above.)*
  - **Six canary runs are in the H10b listening queue:** 1250, 1112, 1304, 1262, 949 and
    1356. §4.0 says queued runs wait for listening so that no run is processed twice. A
    queued canary run that listening then sends to Tier A would be re-detected twice.
  So the reachable canary is **964, 1108, 1025, 1358, 1221, 1311** plus whichever queued
  runs the operator accepts processing twice. Without them it keeps one identity case (964)
  and loses every "must stay non-talk" case.
- [x] **Detection rounds: re-detect without cutting media** (operator, 2026-09-24). Several
  rounds of detect, fix and re-detect are expected before the freeze, and nothing a round judges
  needs media: the first canary spent about 20 minutes per run on detection and then about two
  hours queued behind one ffmpeg worker, re-cutting clips the next round would discard.
  *Built 2026-09-24:* `rerun-redetect` now dispatches a detection round: the livestream chain up
  to the refining projection, then `RecordDeferredCorpusRerunMedia`, then the sermonless tail
  (promotion, cleanup) that completes the run. The round records, without cutting, the two
  verdicts that do not need media: the sermon plan extraction would cut (on the stamp, as
  `deferred_extraction_plan`; `sermon_extraction_plan` still describes the media that exists),
  and each song's publication review (`song_publication_review`, from span, link and banked
  boundary evidence). The stamp says `media: deferred`. A round no longer hashes the staged
  source (about 47 s a run); the cut reads the recording, so the hash moved with it.
  **Tier C:** `historic-import:rerun-extract {snapshot} [runs] [--max=10] [--execute]` cuts a
  finished round's media through the orchestrator's `reExtract()` (sermon, analysis, video
  quality, section candidates and their review, promotion, cleanup), only for a round on the
  running commit, once, with the source hash checked. **Diff:** after a round, lost clips,
  removed sections that had media and sections leaving review are listed as *pending
  extraction* and do not fail; a lost live hold or a publication still does. The diff after
  Tier C, on the same snapshot, applies every check. Talk speaker review, sermon text and audio
  checks and video quality are decided only by Tier C. Tier A (`rerun-retranscribe`) still runs
  the whole pipeline.
- [x] **Detection reads only independently sourced order-of-service items** (ruled
  2026-09-24). `DetectServiceStructure` filters items by `provenanceSources()`
  (email, OpenLP, manual); a run whose items are all self-written is detected from its
  transcript alone. Test first: a service whose items are all written by the run's own
  projection reaches the prompt with no items. Changes detection output, so it lands before the
  freeze and the next canary measures it (949 must become `prayer`).
  *Built 2026-09-25 (`f174babcc`).* The filter is in `loadOosItems()`, which feeds both the
  prompt and the validator's context, so no section can anchor to an item the model was not
  shown. An item counts as independent when any evidence source is not `livestream`, so a
  livestream-created row that email later corroborated stays in. Projection is unchanged: the
  item sync matches by song, title and position, not by the section's `oos_item_id`.
- [x] **Tier A accepts listening-routed runs** (ruled 2026-09-24). Besides a live
  transcript-loss hold, `rerun-retranscribe` accepts a run the routing file names `new_better`,
  checked against the file's hash, so the 128 need no hand-written holds.
  *Built 2026-09-25 (`73d4634d1`).* The routes are frozen into the **snapshot**
  (`rerun-snapshot --routing=<private path> --routing-sha256=<hash>`), so both tiers read the
  same routes: Tier B refuses a `new_better` run as it refuses a transcript-loss one, and the
  order of the two commands cannot strand a run on known-wrong text. **A route, like a hold,
  describes one transcript** and lapses once the run holds different text. The routing file
  does not say which text was judged, but each decode artifact does
  (`stored_transcript.sha256`, `ServiceTranscriptRedecoder::transcriptHash`): it matched 444
  of 446 runs' current text, and the two that differ are exactly 1112 (Tier A, 09-24) and
  1287 (the 09-23 repair). The bound file
  `storage/app/private/listening-20260924/routing-bound.json` (sha256
  `2fc8903c7e239847a9a12ab449535e1b8398c588bfb88488c3bc47e230819f16`) is the operator's
  routing file plus that hash per run; it records the original's sha256
  (`6d751afa…`). **126 of the 128 routes are live; 20 of those runs also hold transcript
  loss, so Tier A's reach is 64 + 106 = 170 runs.**
- [x] **Tier A as a detection round** (ruled 2026-09-24). `rerun-retranscribe`
  re-transcribes, detects and stops before extraction, deferring media to Tier C like Tier B.
  *Built 2026-09-25 (`73d4634d1`):* `ProcessingRunOrchestrator::startDetectionRound()` starts
  the run afresh (`resuming: false`, so transcription is not reused) with the detection-only
  chain. The stamp says `media: deferred` and the run is no longer marked as re-extraction,
  so `rerun-extract` takes a finished Tier A round as it takes a Tier B one, and its
  `reExtract()` rebuilds the sermon text from the new transcript.
- [x] **A baptism is never inside a song section** (operator, 2026-09-24; prompt rule built
  2026-09-25, `f174babcc`, which keeps a baptismal testimony a `short_talk`). Canary 1
  made 1311 §3950 a 518 s "I Will Sing Of The Lamb" song spanning the baptisms (1200–1718 s),
  where the first run had a separate `other` "Baptisms" section. A baptism is its own `other`
  section; a hymn sung before, after or between the baptisms is its own song section. It is
  flagged today (`structure_macro_section`, `structure_song_swallows_speech`) and contained,
  but the clip would carry the baptisms. Fix in the structure prompt beside the ordinance
  exclusion, test first; the next canary checks that 1311's baptisms leave the song and 1304
  §3871 ("Baptism of Roy") stays non-talk.
- [x] **Re-cut 1112's held sermon** (operator). §4862 carries the operator-written 09-13
  sparse-cadence hold, pinned to the transcript Tier A replaced on 2026-09-24; listening rates
  1112 "new better". Check the passage in the new transcript, clear the hold if it is back,
  then `sermons:re-extract ae1716aa-861c-461e-8f16-96913018388e --held-section=4862`. Until
  then 1112 is `failed` and every re-run command refuses it.
  *Done 2026-09-24.* The new transcript restores the passage: 400 cues and 2,950 words
  (about 126 a minute) across 2081–3490 s, no gap over 8 s, no repeated cue. Re-cut with
  `--held-section=4862` (reading 1754–1849 s plus sermon 2051–3755 s); the run is `completed`.
  The operator then released the hold (`released_by: operator`, the evidence as its reason),
  mirroring `ContentHoldRechecker`: the flag dropped and §4862 left review
  (`storage/scratch/release-1112-hold-20260924.php`). 1112 can rejoin the next canary.
- [x] **Teach `rerun-diff` two custody facts before the Tier C diff** (proposed 2026-09-24;
  built 2026-09-25 at the operator's request). (1) Song review is a publication state (`pending_approval` with
  `song_publication_review` reasons), not `needs_manual_review`, which only the boundary-evidence
  backfill ever set on a song, so after a full-media run the diff reports "left manual review"
  for songs that are still held (15 of the first canary's 23). (2) "Became published" does not
  distinguish a quarantined song video (the historic path's designed outcome) from a public
  one. Until then, read those two attention kinds by hand.
  *Built 2026-09-25.* A section is in review while `needs_manual_review` is set **or** its
  publication is `pending_approval`; leaving review names the new status. "Became published"
  is attention only when something is public: a song video or linked sermon whose
  `publication_state` is `published`. A section published with everything that shows it still
  quarantined is the change `section_published_into_quarantine`. Two gaps closed on the way:
  **a sermon or song video turning public was never attention** (only a change), and **a run
  extraction parks for its held sermon read as a failed re-run**; it is now pending, with its
  re-cut named (the capture records `manual_review.reason_code` while review is required).
  Capture stays additive (VERSION 1).
- [x] ~~**Tier C throughput: cut now, render later**~~ **Built, measured by canary 6 and withdrawn (2026-09-27, below).** (Ruled 2026-09-27, operator: "I want to
  build it"; **gates the freeze**). Canary 5 measured Tier C at 2 h 27 min for 17 runs,
  8.6 min a run on the one `historic-ffmpeg` worker, about **63 hours** for 437 runs. Step
  time: `extract_sermon` 49%, `prepare_section_publication_candidates` 24% (both cut video),
  `audio_enhancement` 16%, `assessing_video_quality` 5%. Of canary 5's 102 cuts, 51
  re-encoded on the bitrate rule, 23 on the codec rule (VP9) and 28 smart-cut.
  - **Cut.** In Tier C a source above `reencode_above_mbps` is smart-cut
    (`VideoExtractionService`: frame-exact edges re-encoded, keyframes to keyframes copied)
    instead of re-encoded, and the run's stamp records `render: deferred`. The codec rule is
    unchanged: an undeliverable codec still re-encodes at the cut. Weekly and every other
    dispatch keep cutting and re-encoding in one step, as detection rounds keep full media
    outside the re-run.
  - **Render** (ruled: reads the stored cut). A job re-encodes the video stream of each
    stored sermon video and section candidate / song video that is still above the
    threshold, in place, with the same CRF and preset, and copies the audio stream
    untouched (song publication has already rewritten it with `-c:v copy`). It needs no
    source, staging context or guard. It verifies frame count, duration and audio packets
    against the cut before replacing it, and refuses an output still above the threshold.
  - **When** (ruled: between batches). An operator command,
    `historic-import:rerun-render {snapshot}`, dispatches renders for a batch's
    `render: deferred` runs after its diff is accepted, on the ffmpeg queue, and stamps
    `render: rendered`. One ffmpeg worker stays (recommended 2026-09-25): a detach mid-cut
    reads like missing media.
  - **Gate.** The historic release refuses any asset of a run whose latest stamp still reads
    `render: deferred`, and the diff treats it as pending, not attention. The stamp, not the
    file, is the gate: a short song clip's own average bitrate can cross the threshold on a
    source that sits below it, so a file-derived rule would hold clips the cut never
    deferred. Inside the render job, re-probing each file only makes a repeat run skip what
    is already rendered.
  - **Disk.** Staging and quarantine share `/Volumes/Staging` (474 GB free on 09-27). An
    unrendered pre-2024 run holds about 3–4 GB at source bitrate, so all ~160 would not fit;
    one era batch (~45 runs, ~170 GB) does. The batch preflight checks free space.
  - Then canary 6 on the new commit: rounds, diff, Tier C (cut), diff, render, diff, freeze.
  - **Canary 6 result (2026-09-27, `fa6dc5937`, the canary 5 set; evidence in
    `storage/app/private/canary6-20260927/`): the split is slower, withdrawn.** Operator:
    "It's clearly not worth the split we just built." Reverted in `5b60f2ee9`.
    - Rounds (Tier B, 16 runs; Tier A had no grounds, its routes consumed by canary 5):
      **0 attention**, as in canary 5; about 10 minutes.
    - Tier C cut worked as specified: every run stamped `render: deferred`, 67 smart cuts,
      23 VP9 re-encodes and **no bitrate re-encodes**. But it took **212 step-minutes
      against canary 5's 152** (08:53–12:01 UTC). The smart cut rewrites the full-size span
      about four times (stream copy, transport-stream join, remux, sound mux); through
      Docker's grpcfuse on the staging drive one 3 GB rewrite takes about 73 s, so cutting
      1250's sermon took 6 min 22 s against canary 5's 7 min 45 s re-encode.
      `promoting_historic_assets` rose from 5 to 25 minutes moving files 3.5 times larger
      (31.5 GB unrendered against 8.9 GB); disk peaked at +52 GB for 16 runs. For about
      30 minutes the dispatcher's source hashing also contended with the cuts on the same
      drive (949's cut: 1,963 s against 648 s).
    - Renders took **45 minutes** (12:11–12:56 UTC) for 267 minutes of video. Every file
      rendered kept its frame count, sound packets and length exactly, at 1.0–2.5 Mbps.
      **Five runs were refused ("the frame count changed")**: 949, 964, 1221, 1304, 1346.
      The verification was right: the cuts themselves are not frame-exact (below).
    - Diff after Tier C: 8 runs flagged (canary 5: 4). Songs published into quarantine
      (1117, 1304, 1346) and sermons or a retyped section leaving review (1028, 1108, 1050)
      are canary 5's explained classes; 936 §609 was removed by the round with its media.
      **New: six songs (949 §719/§720, 1108 §1893/§1894, 1304 §3870/§3872) fell from
      `confirmed` to `inferred`** onto order-of-service items with no `song_id`, so they
      are no longer eligible for a clip and wait for review. This is model variation
      between two passes of identical detection code, not the split. Listen: 1050's sermon
      now runs over 863–920 s, which canary 5 called a song.
    - Staging detached twice during the first Tier C dry run (a bus device with no disk;
      no power event). Three full reads of 949's 10.9 GB source then passed (host `dd`,
      host `shasum`, container `sha256sum`), matching its stored hash, so the file is sound.
- [x] **Holds for the mixed and neither runs** (ruled 2026-09-24): a hold on each
  window the operator judged wrong in the stored text (mixed) or both wrong (neither).
  *Done 2026-09-25.* 63 windows over 29 runs (mixed: 33 `stored_loop` + 8 `both_wrong`;
  neither: 22 `both_wrong`), mapped read-only onto current sections
  (`storage/scratch/listening-holds-20260925/windows.json`). **Only 9 sections can hold:**
  sermons 1262 §3287 (its opening, "Matthew 17"), 1051 §1593, 1089 §1790 (speech invented
  over silence), 1330 §4188, 1322 §4090 and 944 §667 (two windows), and songs 1034 §1459
  ("Father, heaven" for "Hark the herald") and 1214 §2623. Raised with
  `service:hold-section-content --found-by=source_audio`, one record per window, the verdicts
  file's sha256 and window key as evidence. The reason text matches no transcript-loss
  pattern, so these holds are not Tier A grounds (corpus count still 64).
  **Ruled (operator, 2026-09-25): accept the other 53 unheld.** They lie in prayers, `other`,
  notices, readings, the welcome or outside any section (930's §534 alone has 14), which
  the hold action refuses by design because nothing released reads their text. 1377's window
  touches its song for 1 s, a boundary contact, and is not held. **Consequence, accepted:**
  Tier C parks the six held-sermon runs at extraction (`sermon_section_content_held`), and
  no re-transcription clears their holds (they stay on stored text); each needs
  `sermons:re-extract --held-section` or a release. Also noted: 1034 (neither) and 1330
  (mixed) carry older transcript-loss holds, so Tier A takes them despite their route.
  Not in the ruling, for the operator: 7 `both_wrong` windows on stored-better runs (Tier B
  keeps that text) and 14 on new-better runs (the new text is wrong there too, and a hold
  can only be raised on it after Tier A writes it).
- [x] **Pre-freeze checks against the other sources (2026-09-25, operator-requested review of
  the programme).** Read-only, evidence in `storage/scratch/hymn-video-20260925/`.
  1. **The pianist's hymn workbook against the video's song bindings.** The workbook is kept by
     hand by the pianist and is reasonably accurate (operator), so it is an independent record
     of what was sung. It has sheets for 2004–2018 and 2023–2026, none for 2019–2022. The 286
     services with an eligible run in 2023–2026 were split by seed `20260925` (`split.json`,
     held-out sha256 recorded there): **143 discovery, 143 held out.** No comparison is
     computed for the held-out half until the song acceptance measurement after Tier C (§4.5).
     On full recordings in the discovery half, **278 of the video's 316 songs (88%) match the
     workbook.** Of the 38 that do not: 13 contradict the title the leader announced while the
     workbook agrees with the announcement, and current code rebinds all 13 (4 held, 9 not);
     9 are workbook titles the catalogue does not resolve (the video is right); 11 are services
     with no workbook entry; 5 are listening cases (2024-06-30's two announced but unplanned
     hymns, 2023-04-23 "Give Me the Faith", 1112 §3736 where slides, announcement and lyric check
     disagree). 23 workbook songs have no video section: mostly communion hymns sung after the
     stream stops, and run 1066, graded `full` although it starts at the Bible reading.
  2. **Two matcher defects, fixed test-first before the freeze.** A replay of current code over
     every distinct title hint found them. (a) `Song::matchKey()` split "10,000" into "10 000",
     which no key held, so "Bless the Lord, O my soul (10,000 Reasons)" linked the short chorus
     #132 instead of Ten Thousand Reasons #131. A digit-group comma now joins. It is the shared
     resolver, so the workbook's six "10,000 reasons" rows move too. (b) When a title hint names no
     catalogued title, the lyrics fallback scored bare containment as 1.0 and gave a tie to
     whichever song was scanned first ("Jesus Is Lord", in six songs, went to "How Lovely On The
     Mountains"). The tie now goes to the one tied hymn the hint titles, else to the hymn that
     sings it as a refrain (at least three times and twice any rival), else nothing; rows of one
     hymn with the same stored first line tie as one. **Measured old against new** over 656
     distinct hints and 1,306 workbook titles: 18 hints (36 sections) and 6 workbook rows change.
     13 sections move to the right song (the 10,000/2,000 hints, "Take My Life", "What a Saviour",
     "King of Kings", "Saviour of the world"). 23 are refused instead of guessed: junk hints
     ("together", "again", "Let", "Rejoice") and hints that fit several hymns ("Jesus Is Lord",
     "Holy, Holy, Holy", "Christ Is Risen"). "The Servant King", "Knowing You" and "God So Loved
     the World" still resolve. The transcript and OCR paths are unchanged.
  3. **Email stays out of the re-run (ruled, operator 2026-09-25).** The settled Email corpus
     lives in the rehearsal database; this one holds Email for three video-era services. Loading
     it would not reach detection where it matters: Email imported at evidence tier without a
     corroborating source writes no live items (rehearsal: 0 of 42 services in 2020, 6 of 55 in
     2021), and `DetectServiceStructure::loadOosItems()` reads live items. It would reach
     detection only where the video already agrees, which adds nothing and anchors the next
     round on itself. Kept blind, the video stays an independent witness for the convergence
     re-census. The "152 runs without an independent OoS" are a fact of this database, by design.
  4. **Duplicate catalogue songs wait until after the freeze (ruled, operator 2026-09-25).** 19
     groups share a first line; 28 historic sections are bound to one, and the matchers reach
     each by its full title, so the re-run is barely affected. The damage is cross-source (11
     rehearsal services where Email names one twin and OpenLP the other) and in usage history.
     Merge them in OpenLP (song title curation plan) before the convergence re-census and the
     hymn lane; merging locally reverts on the next sync.
  5. **Identity pairs (§4.4):** the workbook dates both carol-service pairs, below.
- [ ] **Before the freeze (agreed 2026-09-25).** The guards pin git HEAD, so the canary 3
  commit cannot be the freeze commit once anything else is committed. The matcher fixes above
  change song matching, so canary 4 runs on the commit that carries them and the freeze rule
  holds. Restart the workers onto that commit first.
  1. ~~Build the diff custody facts~~ (above). ~~Refresh this plan.~~ Commit.
  2. New snapshot with the bound routing file; **canary 4 on all 13 runs, both tiers, then
     Tier C** (`rerun-extract`), about 30 minutes of rounds and 1¾ hours of ffmpeg. No canary
     has exercised the frozen commit's media path: `rerun-extract` has only run in tests.
     Canary 4 covers a held sermon parking (1262 §3288), a hand re-cut run (1112), a
     concatenation (936) and four Tier A runs. Diff after the rounds and again after Tier C.
     A second pass on unchanged detection code also shows how far the model's answers vary.
  3. If it passes, freeze that commit.
  4. *(2026-09-27)* Canary 4 failed and canary 5 passed on `e58978433` (status above), but the
     freeze moved behind "cut now, render later" (Tier C throughput, above). **Built
     2026-09-27, uncommitted at the time of writing:** `VideoExtractionService` defers the
     bitrate re-encode (`deferRender`) and `renderForDelivery()` re-encodes a stored cut in
     place, verifying frames, length, sound packets and the threshold first; Tier C stamps
     `render: deferred`, which `ExtractSermon` and `PrepareSectionPublicationCandidates` read;
     `RenderDeferredCuts` (ffmpeg stage) renders every video promotion's own queries name,
     looking for all of them first; `historic-import:rerun-render {snapshot} [runs] [--max=50]
     [--execute]` dispatches it; release refuses records whose run is still deferred; the diff
     lists a deferred render as pending. **Next: commit, restart the workers, canary 6 on the
     new commit** (the canary 5 set): rounds, diff, Tier C, diff, `rerun-render`, diff (only
     the render pending should clear), then spot-check rendered files against canary 5's
     (duration, frame count, bitrate), then freeze.
  5. *(2026-09-27)* Canary 6 measured the split slower and it was withdrawn (Tier C throughput,
     above). **Ruled (operator): freeze on the revert, `5b60f2ee9` or its docs successor,
     without a canary 7.** Its application code is byte-identical to `e58978433`
     (`git diff e58978433 5b60f2ee9 -- app tests config routes database bootstrap` is empty),
     on which canary 5 passed; the guards pin HEAD, so the freeze still takes a fresh
     snapshot. Gates on `5b60f2ee9`: phpstan clean, 8,868 tests, dusk 59.
     **Open, needs a ruling before the snapshot: the smart cut is not frame-exact.** A scan of
     canary 6's 75 cuts found timestamp faults in 50, at the smart cut's joins, with the
     source regular at the same point (949: keyframes every 0.533 s, no gaps): two frames
     lost (a 0.1 s gap) where the copied GOPs meet the re-encoded closing piece, a repeated
     timestamp where the opening piece meets the copy, and the same at concatenation joins
     (964, 1108, 1221, 1304, 1346). Re-encoded cuts (the VP9 runs) are clean. It predates the
     split and is in the code being frozen: weekly clips and every sub-threshold Tier C cut
     use it, and canary 5 could not see it because no check counts frames across a join.
     About 66 ms of picture, sound unaffected. Evidence: `canary6-20260927/cut-gapscan.json`.
     Options: fix it test-first before the freeze, proving it with a harness that cuts real
     spans (sermon, song, concatenation, the mkv and variable-frame-rate sources) and checks
     frames, gaps, length and alignment, rather than a canary (a canary's diff counts no
     frames); or record it and freeze. Also open, operator's choice before or after the
     freeze: the staging write probe uses one file name for every worker, so workers
     collide on it (133,474 false "unwritable" holds in `laravel.log`, each pausing a worker
     about 5 s), and Tier C's dispatch hashes each source while the ffmpeg worker cuts from
     the same drive. 1262 is still parked for its held sermon (§4873; the operator is
     checking its span, then `sermons:re-extract … --held-section=4873`). Five canary 6 runs
     keep unrendered smart cuts in quarantine; nothing gates them after the revert, but the
     corpus re-run re-cuts every run before any release.
  6. *(2026-09-27)* **Ruled (operator): fix everything flagged before the freeze.** Built
     test-first; proven by a harness that cuts real spans instead of a canary (operator: a
     canary's diff counts no frames, so it could not show the fix).
     - **The smart cut is frame-exact.** Two separate defects. (a) Its pieces were joined by
       concatenating MPEG-TS bytes, each piece's clock restarting, and FFmpeg guessed across
       the jumps: on a source without B-frames (949, 1250, 1304) the copy landed two frames
       early over the opening and the closing two frames late. The pieces are now joined by
       the concat demuxer, each placed by its own length in frames. (b) The re-encoded opening
       and closing counted time in whole frames, so a source timed in milliseconds (Matroska
       at 30 fps, 1025 and 1050), seeked half a frame early, paired every third frame; they now
       encode on the source's clock (`-enc_time_base:v -1`, which the production image's
       ffmpeg 5.1 understands). Real-span harness
       (`storage/app/private/canary6-20260927/smartcut-harness.json`): ten cuts (mp4 without
       B-frames, mkv, the irregular 1311, VP9, a 920 s sermon from 0, two concatenations) all
       carry the source's frames with no gap or repeat, picture and sound start together, no
       decode error and no fallback. The remaining differences are the source's own (1050's
       last frame follows the one before by 0.1 s in the recording) or one edge frame (1311).
     - **The staged-source check reads the size, not the whole file.** Tier A, Tier C and the
       historic re-transcription now compare the staged source's exact size with the recorded
       one instead of hashing it. Every source was hashed as it arrived (import,
       `restage-source`, the concatenation gate), nothing else writes a staged source, and a
       replaced or truncated file changes size. A census of all 442 runs found every present
       source at its recorded size, the nine rebuilt joins included; duration was dropped as
       a second check because 35 webm and mkv sources carry none in their headers. The gate
       now stamps its rebuild's size; a lossless concatenation without the gate's stamp is
       refused. This also ends Tier C's dispatch reading each source while the ffmpeg worker
       cuts from the same drive.
     - **The staging write probe uses a name per process.** On one shared name, workers'
       writes and unlinks collided through grpcfuse (26 of 400 failed in a two-container test,
       0 of 400 on separate names), which caused the 133,474 false "unwritable" holds.
     Still with the operator: 1262's span check before its `--held-section=4873` re-cut.
     **Next: gates, restart the workers onto the fix commit, snapshot, freeze.**
  7. ***Frozen 2026-09-27 on `adeab654c`*** (operator go-ahead; local tag
     `historic-rerun-freeze-20260927`). **Nothing is committed until the corpus re-run
     ends,** docs included: every snapshot and dispatch guard pins HEAD. This entry stays
     uncommitted until then. Workers booted on `adeab654c` (17:36 BST). Membership recounted:
     **437 eligible** of 455 historic (10 superseded, 6 excluded; 1143 and 1262 failed and
     outside, each awaiting the operator: 1143's evidence decision, §4.5, and 1262's span check
     before `--held-section=4873`). Freeze snapshot `storage/app/private/freeze-20260927/
     membership.json`, membership sha256 `8fe5add72e916e3a890435921157033d879221e28ec4f9d4842b14366227f46c`,
     bound routing `2fc8903c…19f16` (routes for 166 members). Dry runs over it
     (`dryrun-{retranscribe,redetect}.txt`): **Tier A 162** (99 new-better routes, 63
     transcript-loss holds), **Tier B 275**, each refusing exactly the other's runs, none
     unreachable. Era batches run as subsets of this snapshot (`[runs]`), each diffed before the
     next. Before the first: the two rulings below (the re-transcribed holds report, the 21
     `both_wrong` windows) and the batch preflight (queues, mounts, worker code, free space).
  8. ***Freeze moved 2026-09-28*** (operator go-ahead): batch 1 on `adeab654c` failed as the
     canary (the talk-interruption regression under "Batch 1" below). The flag
     (`209dc0a9d`) and the review action (`bed982c87`) are committed with this entry; the new
     snapshot is taken on this commit, the workers restarted onto it, and batch 1's rounds
     re-run as the canary. Its baseline is batch 1's output, so the check is that 936 and
     1356 come out whole or flagged, with the rest as batch 1. **Nothing is committed until
     the re-run ends**; the new snapshot's details are recorded below, uncommitted.
     *Frozen on `4105cfa7b`* (local tag `historic-rerun-freeze-20260928`); workers restarted
     09:52:45 BST, a minute after the commit. Snapshot `freeze-20260928/membership.json`: the
     same 437 members (membership sha256 `8fe5add7…`), routing `2fc8903c…` bound (166). Batch 1
     dry runs: Tier B 16 ready, Tier A 0. **Canary dispatched 2026-09-28 (16 of 16).**
  9. ***Freeze moved again 2026-09-28*** (operator go-ahead) after the detection evaluation below:
     the talk prompt, `structure_talk_fragment` and the split review action are committed with
     this entry; new snapshot, worker restart, batch 1 as the canary, scored by the harness.
- [ ] **A report for re-transcribed runs' holds** (proposed 2026-09-25, **needs a ruling
  before the first era batch**). Tier C parks every run with a live hold on a sermon or short
  talk: **74 of 437 eligible runs** (47 `source_audio`, 11 `media_measurement`, 10 `boundary`,
  7 `judgement`, 6 `decision`). **36 of the 64 transcript-loss runs** hold only records no
  code check can re-test (none is `loop_screen`), so Tier A re-transcribes them and they
  still park, each needing 1112's manual check. Proposal: a read-only report that applies
  1112's measures to each held passage in the new transcript (words per minute, longest gap,
  repeated cues) and lists which look restored, plus an operator release command. The
  content-holds ruling (only code-found holds recheck themselves) means the report proposes
  and the operator releases; it never clears a hold itself. It runs after Tier A and changes
  no output, so it can land after the freeze.
- [x] **Recommendations ruled (operator, 2026-09-28).**
  - The 21 `both_wrong` windows outside the 09-24 ruling (7 on stored-better runs, 14 on
    new-better runs): only two touch a holdable section, each an 8 s sliver at a song's edge
    (945 §669, 1342 §4319), the boundary contact excluded for 1377. **Ruled: hold those two**
    (`source_audio`; both runs are Tier A, and a third decode may be wrong there again, so
    the hold guarantees the new text is looked at). **Accept the other 19 unheld;** whether a
    known-wrong passage in a prayer or reading needs its own review route is a question for
    after the re-run. **Raised after Tier A writes their new text (operator, 09-28):** the snapshot captures
    every hold record and `CorpusRerunGuard` refuses a run whose state differs from it, so a
    hold raised before would need a new snapshot. **Open until raised on 945 and 1342.**
  - 1311 §3953 (46 s "Commendation of Naomi's Testimony" as a `short_talk`): no prompt fix
    from one case. **Ruled:** each era batch's diff lists every new `short_talk` under 60 s,
    and each is checked by hand. **Stop before the next batch if more than 3 in one batch are
    confirmed false positives**, then fix the prompt. Genuine short talks do not count.
- [ ] **Batch 1 (ruled 2026-09-28): the canary 5 set on `adeab654c`,** 16 runs (canary 5's
  17 less 1262, outside the freeze): `freeze-20260927/batch1-runs.txt`. Dry runs
  (`batch1-dryrun-{retranscribe,redetect}.txt`): **Tier B 16 ready; Tier A 0** (canary 5
  consumed their routes). Dispatch needs `--max=16`. Preflight 2026-09-28 08:10 BST: HEAD
  `adeab654c`, all six workers booted 17:36:14 BST (one second after the commit), all queues
  empty, 0 failed jobs and 0 runs in flight, `/mnt/historic-work` writable with 440 GB free.
  1311 checks the short-talk rule at once. **Dispatched 2026-09-28 (re-detect, 16 of 16); all 16 completed.**
  *Round diff* (`batch1-diff-round.{json,txt}`, whole snapshot): 16 of 437 changed, no hold
  dropped or cleared (4 carried), media custody pending extraction on 15. Truth set as canary
  5: 964 → #304 and 1250 → #408 `consistent`; 949 four `prayer` slots and both songs back to
  `confirmed`; 1311 baptisms `other`, hymns their own songs, three `testimony` talks, the 46 s
  talk gone; 1304 one song at 140–220 s; 1108, 1025, 1112, 1221, 1358 keep their talks; 1356
  sermon from 1234 s. **Regression (operator, 2026-09-28): a reading given inside a talk is split out and the
  talk's tail becomes its own `short_talk`.** 1356: the Bach talk (204–526 s whole in canaries
  5 and 6) is now talk 204–449, Psalm 150 as `bible_reading` 449–490, and a 32 s tail talk
  490–522. 936: the partner update reads 1 Peter 1:3–9 mid-talk, then continues 2041–2108 as a
  67 s `short_talk` (canary 5 made the same split and it was missed; the under-60 s filter
  cannot see it). Tier C would cut both talks without their endings. Corpus census of
  talk–reading–talk sandwiches (contiguous): exactly these 2 of 437. The under-60 s count is
  the wrong instrument for this class.
  **Ruled (operator, 2026-09-28): flag, never merge.** A census of talk → readings/prayers → talk
  found 3 of 437: 1356 and 936 are one talk, but **1117 is two** (an Open Doors talk, a prayer,
  then "Right. Moving on…" into a new subject), and only the words at the join tell them apart.
  Built (uncommitted): `ServiceStructureValidator::FLAG_TALK_INTERRUPTED` on both talks (derived
  from the structure, so in `REANNOTATED_FLAGS`; forces review and blocks auto-extraction, so a
  truncated talk cannot publish), catalogued `structure-talk-interrupted`, and a one-step review
  action `MergeInterruptedTalk` ("Merge the next talk and what separates it into this talk" on
  the service page): the talk that began absorbs the readings, prayers and ending, carrying
  holds and resetting media as the same-type merge does. Keeping two talks is an ordinary
  confirm. **Batch 1 fails as the canary; the freeze moves:** commit, new snapshot, worker
  restart, re-run batch 1 on the new commit. The 6 attention lines are runs outside the
  batch (972, 973, 974, 975, 979, 982: sermon names a reference, no passage linked), a
  current-state check the diff applies to every member, so the exit is failing on them.
  Canary diffs never covered them. Tier C not yet dispatched.
- [x] **Detection evaluation (2026-09-28), after the canary on `4105cfa7b`.** That canary flagged
  936 and 1117 but split 1356 differently again (tail typed `other`) and merged 1311's three
  testimonies: detection varies run to run, so single canaries could not show whether a change
  helped. Read-only harness in `storage/scratch/` (no run is written): `detection-draws-20260928.php`
  runs production's `DetectServiceStructure` detect, retry and reading re-check per model;
  `detection-truth-20260928.json` is the answer key; `detection-score-20260928.py` scores it.
  - *Answer key:* the operator ruled the disputed boundaries from clips
    (artifact `AhXDwapiDdK385at73PUVy`): 1356 and 936 one talk each (reading inside), 1117's Open
    Doors talk 713 s to 1288 s (1244 acceptable), 1250's talk to 986 s (1018 acceptable), 1311 three
    testimonies, 1112 one item, 1117's Bible-study encouragement a talk or `other`. **Boundaries
    are partly editorial, so the key accepts every span the operator called acceptable; splitting
    or merging talks is never acceptable.** The published sermon runs to the next song
    (`SermonExtractionPlanResolver::resolveSermonEnd`), so sermons are scored on their start.
  - *Models* (16 runs, `medium` effort): gpt-5.6-luna 1.0–1.8 talk-count errors a draw, gpt-6-luna
    1.6, the sol models no better at 16–35× the cost (dropped). **Only 2–5 of 16 runs come out the
    same every draw on any model**: boundary wobble inside the accepted spans, which is
    tolerated. Half of all errors were one shape, a talk split around a passage its speaker read.
  - *Prompt:* a talk keeps a passage its speaker introduces and reads; a sermon's reading, and a
    reading after a talk has ended, stay their own sections; each person's testimony is its own
    talk; a partner talk ends where the prayer begins. **gpt-5.6-luna: 0 talk-count errors in 7
    of 7 draws** (readings intact after a first wording swallowed 1050's and 964's); gpt-6-luna
    0.2 a draw, 1.4× faster (40 s against 57 s a call) and about $1 cheaper over the corpus.
    **Ruled (operator): stay on gpt-5.6-luna.** gpt-6 would also need
    `OpenAiChatPayload::isReasoningModel()` widened (it sends gpt-6 a rejected `temperature` and no
    `reasoning_effort`).
  - *Also built:* `structure_talk_fragment` (a talk under 60 s; the corpus's two were "Children
    dismissed" and "Children come forward") and a review action to split a section in two.
  - **The freeze moves again** (step 9): the canary's pass bar is the harness score on batch 1
    against the answer key, 0 talk-count errors, not a diff read by eye.
- [ ] Batches by era, each checked against its diff before the next. Stop on any new regression.

**Preflight for every dispatch** (the canary, each batch, and any named pre-freeze exception):

1. Freeze exact run membership, required stages, source identity, prior artifacts,
   expected opening/ending and repair outcome. Deduplicate by run; settle upstream
   transcript/structure dependencies before spending on final extraction. Explicitly
   retain or exclude unresolved cases rather than letting them widen the batch.
2. Verify the supported dispatch route and immediate queue, worker-code, mount and
   disk readiness. *Disk (2026-09-27):* until `rerun-render`, a Tier C batch holds its
   pre-2024 runs' cuts at source bitrate, about 4 GB a run, so `/Volumes/Staging` must have
   that much free for the batch's pre-2024 runs, plus a margin, and the previous batch's
   renders must have finished. `historic-import:retranscribe-video-run` is restricted to
   980/1258/1343/1287, all complete; the re-run needs the bounded re-detect route above,
   and Tier A a tested extension of this one. *Built 2026-09-24:*
   `historic-import:rerun-retranscribe {snapshot} [runs] [--max=10] [--execute]`
   (`RetranscribeForCorpusRerun`). It shares the re-detect route's batch guards
   (`CorpusRerunGuard`: snapshot member, pinned commit, not already re-run on this commit by
   either tier, completed, not excluded, unchanged since the snapshot), the orchestrator's
   shared guards and the hash-verified staged source. It dispatches as the four-run route
   does: supersede the recovery replay, reopen at `transcribe_full_service`, `start()`. Its
   grounds are a live transcript-loss hold on the current transcript (`TranscriptLossHolds`,
   the §4.0 definition as code). Read-only over the corpus it finds exactly the census's 64
   runs, the same ones. Listening-selected runs need their grounds recorded before it accepts
   them. The per-commit stamp means no run is re-transcribed and re-detected on one commit.
   A fresh `sail ps` result alone is not this preflight.
   **Commit first, then snapshot** (2026-09-24): the batch guard binds the snapshot to the exact
   running commit, so any later commit, a plan-only one included, makes every run refuse until
   a new snapshot is taken. A docs-only commit needs no worker restart.
3. Reuse the completed canary evidence and check representative first repaired
   outputs before expanding to a larger batch. Cover any materially different
   path with source opening/ending, relevant interiors/joins, media/text agreement,
   replacement coherence and hold persistence. A frozen batch may then finish
   unattended; keep its outputs quarantined pending independent review. Stop
   expansion on a new regression or unassessable result.

**Regular uploads are the lasting outcome.** Historic imports use the livestream
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

The dependency order (transcript → structure and identity → planning → extraction →
stored-output checks) is §4.0's tier shape. The 09-22 numbered workstream list moved to the
[log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md#moved-from-the-plan-on-2026-09-24); its open gaps are in §4.4, §4.5 and the
carried items below.

### 4.1–4.3a Workstream status (condensed 2026-09-23)

The detailed workstream sections §4.0–§4.3a (canary, containment, §4.1a–§4.1d
censuses and rulings, transcript-loop and song-policy work, the class table and the
H1–H10b detector harness) moved **verbatim** to the
[execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md).
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

#### H10b, 1337 and 1287: live notes (verbatim from the 2026-09-22/23 follow-up)

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


#### 1287 repair result and the hold carry-through ruling — 2026-09-23

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

#### Carried open items

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
- [ ] Resolve source adoption for pairs 873/1045, 969/1307 and 1296/1297 without
  deleting the better master or substituting media beneath existing timings.
- [ ] Update exact release membership only after each identity decision is
  evidence-bound.

### 4.5 Prove acceptance, then converge and release

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
- [ ] Complete editorial QA for titles, slugs, references, series, speakers, songs,
  children's talks and occasions, including source-supported summary/point claims
  and meaning-changing transcript errors under §4.1b's blind review method.
- [ ] Generate thumbnails for the exact release membership only after its editorial
  QA passes (deferred for cost; operator ruling 2026-09-14). The weekly job skips
  unpublished sermons and no release-path code generates them, so this must be an
  explicit step, then checked (frame inside the span, not black or a slide).
  Existing thumbnails are checked against current cuts and editorial values too;
  deferral never certifies them.
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

## 5. Go/no-go

| Gate | State | Required evidence to turn green |
|---|---|---|
| Processing | GO | Definitive passes drained; the three former failures have recorded terminal dispositions in §4.5 (19 September). This is dated execution evidence, not a new live census. |
| Queued repair readiness | **CONDITIONAL GO for named pre-freeze exceptions and the §4.0 canary only** | The corpus re-run itself waits for the freeze gate (§4.0). Both canary blockers are closed (`283a6cd90`, `690ae1d5d`), merge-case hold persistence is verified (`8d98e45b8`), and 1340's transcript regression is repaired (`c933889b9`, `7d6bbde95`). Workers were restarted onto `1c65dd104` before that repair, and macOS Safari join playback passed; iOS is an accepted limitation. Every new bounded batch must still pass an immediate queue/mount/worker-code preflight and verify each sermon opening against source speech. Actual-server range, repaired-output, song-clip and cache checks remain release evidence. |
| Containment | **NO-GO** | The six disputed sermons and their seven song videos were held on 2026-09-16 and the sections those holds left published were demoted the same hour (§4.4), so the identity gate-clear gap is closed and published-while-held is zero again. Remaining: the current-policy and unassessable residue. Containment is not adoption — the three pairs are still undecided, and the holds are what make deferring them safe. |
| Content acceptance | **NO-GO** | The strengthened stopping rule (carried items, "Rulings and stopping rule") passes: scoped coverage and limitations, omission reconciliation, independent source evidence, content handoffs, controlled variations/interruption tests, tail and whole-output reviews. Every §4.3a class has a tested response or recorded decision; detector errors and review burden are evaluated against predeclared criteria using H9/H10's retrospective, source-adjudicated evidence, with measured units and limitations explicit. There is no reserved historic set; insufficient evidence is not a pass, and H10b disagreement counts are not complete recall. The fresh release-membership sample includes repaired/held runs and meets its separate predeclared limits. Evidence is bound to current artifacts; operator rulings are recorded. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks are scheduled within the authorised release's rollback window and must pass to close observation. |

<a id="correctness-review-2026-09-10-followup"></a>

### Follow-up correctness review — 2026-09-10/11

Moved to the [execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md); its open actions are listed under "Carried open items" above.
