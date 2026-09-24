# Historic Video Defect Discovery and Acceptance Plan

> Formerly `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`. Renamed 2026-09-14
> once bulk processing was complete and the remaining work became discovering,
> containing and detecting defects, then proving acceptance.

> **Status — 2026-09-23: bulk processing is drained; containment, content
> acceptance and public release remain NO-GO.** Most media repairs await pipeline
> reprocessing through bounded batches (§4 prerequisites). Where the work stands:
>
> - **Detection:** the §4.3a harness is built and evaluated (38 detectors: 5 fail,
>   33 not established, 0 accepted). The remaining failures are diagnosed (see
>   "Workstream status").
> - **Miss rate:** H10b re-decoded and compared all 338 decodable runs under a rule
>   fixed before decoding (tripwire 2.20%, under 3%). A 208-window listening queue
>   awaits the operator (306 windows including batch 2, the 99 restaged runs).
> - **Repairs ready:** 1287's retranscription is dry-run ready.
> - **Operational:** temp-file cleanup is paused locally so restaged sources survive.
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
**Last reviewed:** 2026-09-22 — sequencing and regular-upload coverage reviewed
against current code. The full harness and detector programme remains active;
the operator may complete it before rerunning or choose a bounded background
repair batch first, depending on available project time (§4). Sail is running:
`vendor/bin/sail ps` succeeded outside the sandbox and listed all six workers up.
The earlier sandbox failure was not evidence of a stopped stack. This check did
not refresh database counts or verify loaded worker code, queues or mounts.

**Previous review:** 2026-09-20 — execution state, the extreme-short-loop containment,
and the bounded retranscription command/preflight are reconciled with the execution
tasks. The database census remains dated 16 September; this review does not refresh
it. Prioritise independent verification and bounded transcription/boundary repair
before dependency-grouped reruns. Earlier measurements and operator rulings remain
evidence, not current status where superseded.

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
| Song boundary/loop evidence | Version 5, input fingerprints, lyric-edge and loop risks | Successive backfills and demotions recorded in §4.3a | Current-policy scope/limitations; independent audio evaluation; identity confirmation |
| Smart cut, MP3, source audio format | Yes, including `fda414eb2` source-frame correction | Tests, source-content canary and limited real-source measurements; no completed corpus repair pass recorded | Affected media reruns; actual-server delivery checks at release |
| Structure and song identity | Prompt seconds, closing prayer/reading retention, sustained singing, mistyped sung-item risk, catalogue-title priority and 17 September speech-edge trim | Retrospective measurements and tests; trim replay is not a corpus write | Per-run re-detection/re-resolution, then extraction and derived-item repair; speech-edge exceptions |
| Local Whisper decoding | Context carry disabled; initial prompt retained on both request paths; options fingerprinted | Seven-run experiment, MP3-form check, 1314/1340/1343/1258/980 repairs and four-run recovery reconciliation; request/fingerprint tests | Wider raw-transcript census; full independent source/content and dependent-analysis checks |
| Video-quality detector | Dead-picture coverage and owning-run evidence | 48 prior rejections reassessed (28 approve/19 reject/1 review); seven prior approvals moved to review | 862 where its output lives; 897/1005 unassessed; source-versus-cut black-picture causes; independent negatives |
| Acceptance and release | Existing signed release machinery | Nine-service source inventories compared; acceptance incomplete; no new release authorised | Interior semantic review, H10 detector-negative sampling (no reserved set — H9), canary, bounded repairs, operation closeout, exact-membership QA and delivery |

The 10–11 September transcript/media-readability census and its missing-hold list
remain in the preserved follow-up review at the end of this document. They are not
fresh readability checks or current missing-hold counts. The five confirmed sermon
loops and §§988/1457/3869 were held on 13 September (§4.1).

## 4. Remaining work in execution order

**Execution choices revised 2026-09-22 (operator instruction).** Keep the full
harness and detector work visible and active. Whether it precedes the next rerun
depends on the operator's available time, not an assumed decision to defer it.
The detailed sections retain their stable numbers and evidence anchors.

| Available time / intent | Next work | Completion boundary |
|---|---|---|
| Time to work actively on the project | Continue the full §4.3a harness and detector programme, the independent source reviews and the regular-upload regression work below. The operator may choose to finish this before another rerun. | Complete the named implementation/evaluation tasks and record remaining uncertainty; building a harness alone does not validate the outputs. |
| Busy; wants useful processing in the background | Prepare and dispatch an explicitly selected, bounded repair batch using fixes already implemented, after the three prerequisites below. | Runs finish into quarantine with holds preserved; source/content review can wait for the operator's return. Job completion does not accept or release them. |

Neither choice drops work from the other. Full harness completion is not a
technical prerequisite for every bounded repair, but it remains a legitimate
operator preference before rerunning. Do not automatically dispatch a batch
merely because it is technically ready. This plan revision authorises no new
processing membership or release.

### 4.0 Corpus re-run — operator decisions 2026-09-23

Most fixes since 09-15 act at structure detection or song matching (1493 prompt seconds,
sustained-sound widening and bridge, speech-edge trim, neighbour same-song rule, catalogue
title resolution, mistyped-sung flag, lyric identity check). They reach a run only when it
is re-detected, so nearly every run with a song section is affected, and selecting runs
costs more than re-running them. **Operator decisions:**

1. **Freeze detection code only after every open detector item is closed.** Each item is
   either built and tested, or recorded as a decision not to detect. The pass then runs
   against one commit, and its evidence binds that commit. The gate (catalogue state
   2026-09-23: 40 promoted, 16 fixed at source, 2 decided not to detect, **17 unbuilt, 1
   prototype**):
   - Structure/typing: ~~`structure-hymn-inside-sermon-section`~~ (built `994446a12`),
     ~~`structure-spoken-quotation-typed-as-song`~~ and
     ~~`detection-unplaced-hold-refusal-discarded`~~ (both already fixed; recorded `5a071a4af`),
     ~~`song-section-without-a-song`~~ (built `c70ad59cf`; the doxology after Old Hundredth,
     §678/§3284, stays a **named limitation** by operator ruling 2026-09-23),
     ~~`talk-typed-other`~~ — **gates the freeze** (operator ruling 2026-09-23): talks plan PR1
     and PR3 (`short_talk` detection) land before the freeze, so the corpus re-run detects
     short talks in the same pass. *Landed 2026-09-23 (`887d73373`, `7e9eb824f`); PR2 and PR4
     too. The talks plan's own measurement and PR5 re-detection pass moved into this re-run
     (operator, 2026-09-24): the canary below checks the prompt, and Tier B re-detects the
     191-section bucket.*
   - Transcript/audio: `transcript-meaning-changing-substitution`,
     `audio-dropout-inside-talk` (prototype)
   - Scripture: `scripture-reference-never-linked`, `scripture-multi-passage-truncated`,
     `scripture-verse-in-prayer-typed-as-reading`
   - Identity/metadata: `oos-item-written-from-wrong-song`,
     `published-title-contradicts-content`, `identity-duplicate-date-pair`
   - Membership/staging/release: `membership-missing-occasion`,
     `membership-rehearsal-imported-as-service`, `staging-held-candidates-not-promoted`,
     `release-media-file-missing`, `video-discredited-verdict-unreassessable`
   - Carried §4.3 items outside the catalogue: ~~the continuous-speech boundary check (§988)~~
     (built `4abce5f0c`), ~~`confirmed` redefined as two independent sources~~ (built
     `8ae957628`; operator ruling: **any two** of heard, sung, projected, planned — 126 of 1,185
     current bindings would become inferred, against 231 under the stricter plan wording),
     ~~speech under looped sung text~~ (built `cb024a9a6`), and song edge into an adjoining
     section.
   - Each built rule was measured read-only over the corpus before commit; none was applied to
     existing rows, which change only when the re-run re-detects them. Evidence:
     `storage/scratch/{sungspan,songwithout,swallow,speechloop,confirmed}-20260923-*.json`.
2. **Tier A (re-transcription) waits for the operator's H10b listening queue.** Runs are
   chosen from the listening results, not from the tripwire alone.
3. **All eligible historic runs are re-run**, not only those predicted to change. The
   censuses keep finding classes nobody predicted, and the diff report surfaces them.

**Shape.** Tier A re-transcribes (H10b-selected runs plus the contained transcript-loss
cases). Tier B re-detects every other eligible run from structure detection. Tier C,
re-extraction, follows only where a span or binding moved. Everything lands in quarantine;
release stays with §4.5.

**To build before the pass:**
- [ ] A per-run **before/after diff report**: sections, types, spans, bindings, holds (with
  `found_by`), review flags and extraction plans. It is snapshotted before dispatch and
  compared after, so review reads "what changed and why" instead of re-examining every run.
- [ ] A bounded **re-detect dispatch route**, tested. `historic-import:retranscribe-video-run`
  is restricted to four runs.
- [ ] **Canary: runs 964 (§872) and 1250 (§3128)**, the first use of the diff report. Current
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
  `partner_update`), **1112** (§3735, Gavin Peacock — `testimony`).
  *Mixed:* **1311** — §3949 "Baptismal testimonies" becomes `short_talk` (`testimony`) while
  §3951 "Baptisms" stays non-talk.
  *Must stay non-talk:* **1304** (§3871, "Baptism of Roy" — an ordinance), **1051** (§1592,
  "Family tribute and eulogy"), **1262** (§3279, "Reflection and prayer for Queen Elizabeth
  II"), **949** (§719, "Church sharing and prayer"), **936** (§600, "Pre-service
  preparation").
  Three of the four positives carry their answer in the title, so this checks the rule's
  exclusions far more than its recall; recall over the untitled cases is read from the Tier B
  diff report of the 191-section bucket. Pass = every section as expected in the diff report.
  A miss is a prompt fix before the batches (`feedback_measure_before_generalizing_a_fix`); a
  missed *proposed type* alone does not block, because the operator confirms every type at
  approval.
- [ ] Batches by era, each checked against its diff before the next. Stop on any new regression.

**Three prerequisites for the next bounded batch:**

1. Freeze exact run membership, required stages, source identity, prior artifacts,
   expected opening/ending and repair outcome. Deduplicate by run; settle upstream
   transcript/structure dependencies before spending on final extraction. Explicitly
   retain or exclude unresolved cases rather than letting them widen the batch.
2. Verify the supported dispatch route and immediate queue, worker-code, mount and
   disk readiness. The current `historic-import:retranscribe-video-run` action is
   restricted to 980/1258/1343, whose bounded dispatch is complete; wider use needs
   a tested, explicitly bounded extension or another verified existing route.
   A fresh `sail ps` result alone is not this preflight.
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
  | Temp-file cleanup pause (`CleanupTemporaryFiles`, sweep) | yes | yes | yes | yes |

  **Gaps that remain, by design or open:**
  - Direct uploads have no transcript loop detection. The loop class did not reproduce on
    the local backend (five sermons, 09-22), so no safeguard is added. **The OpenAI
    `whisper-1` client has neither the context fix nor detection**: switching the direct
    backend to OpenAI needs its own check first.
  - Auto-trim runs no song matching or clip publication, so song-identity and song-clip
    fixes do not apply to it. That is not a gap unless auto-trim starts publishing songs.
  - Historic acceptance is not evidence for the direct routes. It shares only the context
    fix and the video quality check with them.

The workstreams below retain their internal dependencies. Their numbering does
not require completion of all independent review or detector work before a
bounded background repair; the three batch prerequisites above govern dispatch.

1. **Reconcile and contain.** The read-only status/exposure reconciliation is done
   in §3, including subsequent identity containment (§4.4). Reconcile the blind-review
   hold status against dated gate evidence; act on current-policy discrepancies and
   unassessable evidence below through the tested paths. Keep historic and weekly
   populations separate; inspect both whenever a shared rule changes.
2. **Finish independent review without reusing discovery as acceptance.** The
   nine-service inventory/comparison in §4.1b is complete (1336, 1221, 936, 1097,
   1066, 1314, 1358, 950, 1034). **The 18 frozen interior semantic windows and
   BC-05 source replay completed 2026-09-18 (§4.1d).** Preserve recorded prior exposure;
   these cases now inform
   tuning and are regression evidence, not untouched evaluation. Add bounded positive and detector-negative
   audio checks for song loops and lyric edges; lyric agreement alone is not audio
   adjudication. **There is no separate reserved evaluation set** — §4.3a's H9
   rules that none survives; miss rate comes from H10's detector-negative sample.
   This is discovery
   and validation, not a nine-service claim of corpus accuracy.
3. **Prove one bounded repair canary (§4.0a).** **Done 2026-09-17 for source-content
   alignment and hold persistence.** macOS Safari playback across the shared join
   path passed; iOS is an accepted untested limitation. The remaining actual-server,
   repaired-output, song-clip and cache checks stay in §4.5's release evidence rather
   than blocking bounded repairs. The canary also proved that re-running is not
   automatically safe for a sermon span (1340 below), so every re-run is checked
   against its opening words, not just its job status. Cover the Whisper
   context fix and changed song boundaries as well as the latest smart-cut correction;
   inspect dependent sermon endings and analysis, not just durations.
4. **Repair by each run's dependencies.** Freeze a deduplicated run membership,
   required stages, code/evidence versions and expected outcomes before dispatch.
   Transcript recovery precedes dependent structure/analysis; structure and song
   identity precede planning; extraction precedes measured stored-output checks,
   derived items and final evidence banking. An unaffected run need not wait for
   an unrelated detector. Do not repeatedly encode a run whose boundaries are
   still under repair. Preserve holds until its independent acceptance passes.
5. **Close substantive gaps and operation state before or alongside the repairs.** Prioritise
   §4.2's context-drift recovery, short/varying loops and sparse loss; §4.3's
   performed-song confirmation and unresolved speech-edge cases; quoted hymns
   mistaken for singing and hymns wholly inside sermons; Scripture linking and
   missing assets. Source dropouts/truncation can end in a reasoned hold or accepted
   limitation rather than attempted reconstruction. The three failed runs have
   recorded terminal dispositions in §4.5 (19 September); verify those when
   refreshing state rather than reopening their old checklist. Resolve the
   deferred source-adoption pairs and operation 4's legitimate completion route
   before final convergence; do not fabricate checkpoints.
6. **Accept and release only exact membership (§4.5).** Independent repaired-output
   checks, current-policy evidence, editorial QA, thumbnails, convergence artifacts,
   real browser delivery and operator-signed batches remain required. Increasing
   the number of holds is not this phase's success measure.

### 4.1–4.3a Workstream status (condensed 2026-09-23)

The detailed workstream sections §4.0–§4.3a (canary, containment, §4.1a–§4.1d
censuses and rulings, transcript-loop and song-policy work, the class table and the
H1–H10b detector harness) moved **verbatim** to the
[execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md).
Cite it for evidence; do not copy histories back. The class-to-detector binding is
data, in `resources/detector-classes.json`.

| Workstream | State on 2026-09-23 |
|---|---|
| §4.0a Repair canary | Done 09-17 (3/3). 1340 repaired 09-18. Held sermons park at extraction. Bounded retranscription of 980/1258/1343 done 09-20; 1287 is allowed and dry-run ready (stale replay stamp is now superseded on dispatch, `ed92d48f2`). |
| §4.1 Containment | Missing holds from the 09-10/11 review contained. Assets stay quarantined until their text or cuts are recovered. |
| §4.1a/§4.1b Discovery censuses | Coverage censuses complete (residue, OoS order, titles, dates, sections, song loops, Scripture, consumer rendering, tails, video quality). The field coverage matrix is in the log. Human-only rows (end-to-end watching, real server/browser playback) remain open. |
| §4.1c/§4.1d Rulings, interior review | BC-06/BC-07 ruled 09-18 (§4684 moved to the talks plan). 18 interior windows and BC-05 replay done. |
| §4.2 Transcript loops | `max_context=0` fixes context drift; screen, sparse cadence (both-flank excuse) and recovery re-applied corpus-wide 09-21. Corpus re-decoding via H10b (below). Direct uploads: loops not reproduced on local Whisper. |
| §4.3 Song policy | Speech-edge trim, sustained-sound widening, 10 s introduction bridge (09-22), lyric edges, neighbour same-song rule (09-23) built. Existing outputs need re-detection and re-extraction through the pipeline. Hint/binding contradiction rule measured and not built (all genuine cases already held). |
| §4.3a Detector harness | Catalogue, five adapters, case book, replay and evaluation built. Latest evaluation: 38 detectors, 5 fail, 33 not established, 0 accepted. 1341 fixed (bridge), 1337 fixed (neighbour rule), 1287 diagnosed as transcript loss (not a detector gap), 1109 below the intentional threshold. Recall via H10/H10b. |
| H10b Re-decode comparison | Built, controlled (C1/C2/C3) and run over 338 runs; decision rule applied unchanged (tripwire 2.20%). 99 restaged runs decoding as batch 2. **Listening queue awaits the operator.** Details and the committed rule below. |

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
  but the retranscription command is still restricted to 980/1258/1343.


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
boundary-evidence backfill **once, at v7**.

#### Carried open items

Every box still unticked in the moved sections, grouped by the section it came from. **Triaged
2026-09-23** against the log, the code and the database: ticked items name their evidence,
superseded ones say why, and partial ones say what remains. For unannotated items no
evidence of completion was found, so they stay open; some (for example the §988 boundary
check against the 09-17 speech-edge trim) deserve a closer look before being built.

**1340's regression is a transcript regression — measured 2026-09-17/18**

- [x] Exercise the supported pipeline re-extraction/replacement path, including an equal-duration recut and a held-then-reprocessed run. *Triage 09-23: done. Canary 09-17 recut three held sermons (3/3); held runs 980/1258/1343 were re-transcribed and re-extracted 09-20 with holds persisting; 1340 repaired 09-18.*
- [ ] Compare source content with the actual stored video/MP3 at starts, ends, randomly selected interiors and every join.

**4.1 Contain confirmed missing holds first**

- *Standing rule, not a task (triage 09-23):* keep the assets quarantined while their text or cuts are recovered.

**Field coverage matrix (drafted 2026-09-14 from the schema; keep current)**

- [ ] Extend the machine-readable matrix with exact eligible membership and its hash, counts actually checked and unassessable (with reasons), check kind, instrument/version, thresholds, run date, input/output evidence hash….
- [ ] Split mixed populations and partially checked rows.
- [ ] Reconcile expected outputs against actual outputs from source truth and applicable pipeline contracts: talks, readings, songs, media, passage enrichment and downstream artifacts.

**Blind source review and evidence independence**

- [ ] Draw and save random interior source windows before consulting detector results, alongside complete-source review.
- [ ] For each corroborating signal, record its original evidence and every transformation that could have inherited another signal's answer.
- [ ] Separate intended from performed content.
- [ ] Give the 152 runs without an independent OoS in the recorded census their own coverage and result breakdown.

**Content alignment across processing handoffs**

- [ ] Census content alignment across every eligible sermon MP3/video pair and generated clip versus its source spans.
- [ ] Bind source identity/hash, ordered extraction spans, final artifact hashes, stored object identity and applicable policy/version in the evidence for a run.
- [ ] Compare each source audio channel with final MP3 and video audio.
- [ ] Search the whole source/output corpus for exact and near-duplicate content using hashes, audio fingerprints and transcript similarity without first requiring equal dates, references or titles.

**Controlled variations and interruption tests**

- [ ] Implement and evaluate the following relations on the affected pipeline stages, then exercise representative cases through the weekly path.
- [ ] Exercise failure around old-file removal and replacement upload in `SermonMetadataIntegrationService::organizeVideoFile`, plus downstream row linking and review recomputation.
- [x] Include a held-then-reprocessed run. *Triage 09-23: done by 980/1258/1343 (09-20, holds persisted).*
- [ ] Confirm each new regression fixture fails for its intended reason before fixing a reported bug, then passes through the standard pipeline.

**Disagreement censuses to run (all 442 active runs unless stated)**

- [ ] Complete sermon audio/video content alignment corpus-wide, including source spans and joins, under the handoff checks above.

**Changes of examiner modality**

- [ ] Watch and listen to five complete services' outputs end to end on the rendered local pages (sermon page with video and MP3, each song page), drawn across eras and including at least one held-then-repaired run.
- [ ] Verify playback through an actual server and browser on a representative set spanning source codecs, frame rates, mono/stereo, concatenation joins and repaired artifacts.
- [ ] Through real media URLs, test starting playback, seeking near the middle, across joins and near the end, and resuming playback.
- [ ] Verify replacement at an existing URL returns the current artifact through storage/server/cache delivery, including a browser that has loaded the old one.
- [ ] Carry these journeys into the separately authorised release's observation window on the actual destination, with exact asset membership checks before release and representative delivery checks after it.

**Rulings and stopping rule**

- [ ] Put every "not a defect" ruling to the operator explicitly, with the evidence, and record the decision here: the hymn cut from sermon videos (`selectBibleReading()`), the long song videos, video 970's timestamps, and….
- [ ] **Stopping rule (replaces round 2's; strengthened 2026-09-14).** Close this discovery round only when each coverage row has an explicit eligible population, check kind, actually checked and unassessable counts, eviden….
- [ ] Sample the held population on the other dimensions: draw 15 held runs and run the full checklist plus the matrix rows on them, so that clearing a hold for its recorded reason does not release an unexamined row.

**4.2 Close the transcript-loop blind spot**

- [x] After readiness verification, and **on workers running `7d6bbde95` or later**, re-transcribe 1343, 1258 and 980 through the pipeline in the canary/bounded batches. *Triage 09-23: done 09-20.*
- [ ] Verify recovered full-service evidence and saved sermon text independently.
- [ ] Apply §4.1b's blind interior-window review to fluent transcription errors and omitted or meaning-changing speech, as well as loops.
- [x] Complete the identity consequence: unusable looped text cannot provide lyric confirmation. *Triage 09-23: done; song matching refuses a transcript-derived `confirmed` where a suspect block overlaps (OCR or audited review may still confirm).*

**4.3 Refresh song policy for existing outputs**

- [ ] Reconcile the 27 boundary-policy candidates in addition to confirmed mixed section 3869.
- [x] Add a boundary check for continuous spoken material that does not depend solely on finding a wordless gap, using section 988 as the regression case. *Built 2026-09-23 (`4abce5f0c`): a song under half sustained with a 25 s spoken lead-in or 20 s tail is held, not trimmed; 34 flagged over 463 runs, 23 already held, §988 and §1475 included.*
- [ ] Independently verify repaired starts and tails, including clean negatives, no lost singing, and the adjoining sermon ending (135 replay trims follow sermons).
- [ ] Keep §988/§1475 (below the half-sustained floor), §2897 (speech over organ; proposed start trim unverified), and §1457's actual repaired output as explicit real-source checks.
- [x] Preserve the rejected broad unsung-song rule as a measured decision: 14 of 21 candidates were sung. *Triage 09-23: recorded in the log (§4.3, 09-17 speech-edge trim).*
- [ ] Re-resolve the 72 deterministic cases through the pipeline, adjudicate the three unchanged-fallback suggestions separately, and correct livestream-sourced order-of-service items after identity settles.
- [ ] Settle the 31 pending-approval sections with the same hint disagreement before anyone approves them.
- [x] Add a lyric-coverage check (calibrated in §4.1a) before a song match is recorded as `confirmed`. *Built test-first 2026-09-23.* `SongLyricIdentityCheck` ports the 09-14 lyric scorer's rule unchanged (≥15 distinct content words; a rival song with ≥4 word pairs, twice the bound song's, and +0.1 IDF coverage). `MatchSongsFromTranscript` runs it on every match not taken from OCR and records `lyric_identity_check`. A contradiction raises `song_identity_contradicted_by_lyrics`, which `SongCatalogueTitlePolicy` vetoes at any confidence (so it stays `inferred` and goes to review, and the recalculator cannot promote it). The section also stays eligible for OCR, which can settle it. It is a contradiction test, not a confirmation: an unreadable transcript (`unavailable`) or too few heard words (`insufficient_words`) does not block, because Whisper drops singing. The hand-applied `…_by_transcript` flag is a separate string and is never touched. Registered as the promoted detector `song-identity-contradicted-by-lyrics`. **Corpus measurement** (read-only, inside staging contexts; `storage/scratch/lyricid-20260923-measure.{php,json}`), 1,218 bound song sections: 954 consistent, 190 insufficient words, 15 unavailable, **58 contradicted, 56 already held**. Calibration reproduces: 0 of 42 frame-verified correct sections flagged; of the 8 known-wrong, 4 are contradicted, 2 score consistent (§519, §3218), and 2 have too few words (§508, §631). **Two new unheld candidates**: §872 (run 964; bound #275, sung words match "God We Praise You #177" at 45 pairs to 3) and §3128 (run 1250, `title_hint_fuzzy`; bound #19, sung words match "I Do Not Know What Lies Ahead #871" at 18 to 3, the title its own hint names). Both are historic, at `identified`, with no clip, so nothing is exposed. The check reaches existing rows only when matching re-runs through the pipeline. Those two need a binding decision, not a hand edit.
- [x] Redefine `confirmed` as two agreeing evidence sources whose lineage is demonstrably independent, with at least one supporting the actual performed song. *Built 2026-09-23 (`8ae957628`) as **any two** independent sources, by operator ruling; the performed-song requirement was dropped after measurement (it would demote 105 planned-and-announced bindings where Whisper missed the singing).*

**4.3a Put a detector in the pipeline for every class found**

- [ ] Each promoted detector ships with the corpus cases in this plan as regression fixtures (positive and negative), under `tests/Fixtures/StructureEval` or beside it, following the existing fixture conventions.
- [x] Re-run every promoted detector over the current eligible historic membership and reconcile its output. *Triage 09-23: done by `detectors:replay` (H7a, 09-21) and the sound-stage re-derivation (H7c, applied 09-21).*

**Detector quality and automation benefit**

- [ ] For each detector, record a source-adjudicated evaluation of true and false positives, false negatives and unassessable cases, with exact denominators and uncertainty.
- [ ] Draw detector-negative and approved examples independently of its alerts and review them against source evidence.
- [x] ~~Separate known-defect regression fixtures from the development data used to choose thresholds/prompts.~~ *Triage 09-23: superseded by the H9 ruling (no reserved set; historic results are retrospective validation).*
- [x] Before evaluating a candidate, record acceptance thresholds by defect severity, tolerances and allowed review burden. *Triage 09-23: done; H4 predeclared thresholds contract (09-21).*
- [x] Break results down by era, codec/channel setup, service kind and availability of independent evidence, explicitly including the no-OoS group. *Triage 09-23: done; the evaluation tabulates stored era, codec/channel, occasion and corroboration × independent OoS (H8 follow-up, 09-22).*
- [x] Bind results to code, model/prompt, policy and evidence versions. *Triage 09-23: done; H6 version binding, and the report binds the evaluator file hash.*
- [ ] Derive the era boundaries the breakdown needs from observed source codec/container/channel transitions in the corpus.
- [ ] Draw and review the H10 detector-negative sample: 90 transcript-negative runs for the S1 target, by sampled interior windows rather than whole-service listening, reporting the quantity actually bounded. *Triage 09-23: partly served; H10a coverage done, and the H10b listening queue (208 windows) is the transcript half. Still needs the operator's listening.*

**H8. What this discharges, and what it leaves open**

- [ ] Write the source-confirmed regression cases into the existing fixtures; distinguish test doubles from evaluations of real model outputs.
- [ ] Complete each remaining detector class's tested response or explicit recorded decision not to automate it; retain the class table as the work list.
- [ ] Complete independent positive/negative source adjudication and H10/H10b's scoped measurements, then report results and limitations against the declared criteria.

**Final verification, interpretation and next actions**

- [x] **Contain the five audio-proven corrupt transcripts and §3869/§988.** *Triage 09-23: containment verified; runs 929/1008/1068/1187/1317 sermons and §3869/§988 all carry `content_defect_hold`.*
- [ ] Recover the text or correct the cuts for those seven (through the pipeline).
- [ ] **Close the short-loop blind spot contextually.** *Triage 09-23: detection and regression coverage built (`4e42e05e7`, `e6b9326fc`, 09-19/20). Validating the remaining candidates against their audio is still open.*
- [ ] **Make policy refresh reach already-generated songs.** Reassess within each owning staging context and bind the result to current inputs.
- [ ] **Bind the three deferred duplicate pairs into Phase 9's membership.** *Triage 09-23: tracked in §4.4; kept here only as a pointer.*

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
- [ ] Re-run §4.1a's held-out validation on a fresh sample after repairs, as final
  acceptance evidence, across eras and apparently clean cases,
  covering split sermons, partial/composite recordings, corrupt transcripts,
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
| Queued repair readiness | **CONDITIONAL GO** | Both canary blockers are closed (`283a6cd90`, `690ae1d5d`), merge-case hold persistence is verified (`8d98e45b8`), and 1340's transcript regression is repaired (`c933889b9`, `7d6bbde95`). Workers were restarted onto `1c65dd104` before that repair, and macOS Safari join playback passed; iOS is an accepted limitation. Every new bounded batch must still pass an immediate queue/mount/worker-code preflight and verify each sermon opening against source speech. Actual-server range, repaired-output, song-clip and cache checks remain release evidence. |
| Containment | **NO-GO** | The six disputed sermons and their seven song videos were held on 2026-09-16 and the sections those holds left published were demoted the same hour (§4.4), so the identity gate-clear gap is closed and published-while-held is zero again. Remaining: the current-policy and unassessable residue. Containment is not adoption — the three pairs are still undecided, and the holds are what make deferring them safe. |
| Content acceptance | **NO-GO** | §4.1b's strengthened stopping rule passes: scoped coverage and limitations, omission reconciliation, independent source evidence, content handoffs, controlled variations/interruption tests, tail and whole-output reviews. Every §4.3a class has a tested response or recorded decision; detector errors and review burden are evaluated against predeclared criteria using H9/H10's retrospective, source-adjudicated evidence, with measured units and limitations explicit. There is no reserved historic set; insufficient evidence is not a pass, and H10b disagreement counts are not complete recall. The fresh release-membership sample includes repaired/held runs and meets its separate predeclared limits. Evidence is bound to current artifacts; operator rulings are recorded. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks are scheduled within the authorised release's rollback window and must pass to close observation. |

<a id="correctness-review-2026-09-10-followup"></a>

### Follow-up correctness review — 2026-09-10/11

Moved to the [execution log](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md); its open actions are listed under "Carried open items" above.
