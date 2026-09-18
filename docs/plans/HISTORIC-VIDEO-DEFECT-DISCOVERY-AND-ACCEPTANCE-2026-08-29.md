# Historic Video Defect Discovery and Acceptance Plan

> Formerly `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`. Renamed 2026-09-14
> once bulk processing was complete and the remaining work became discovering,
> containing and detecting defects, then proving acceptance.

> **Status — 2026-09-17: bulk processing is drained; containment, content
> acceptance and public release remain NO-GO.** Prevention and containment have
> advanced substantially, but most media repairs await pipeline reprocessing.
> The dated local census in §3 supersedes earlier counts. The six disputed
> sermons and seven associated videos were contained on 16 September; source
> adoption remains undecided. The nine-service source comparison is complete,
> but interior semantic checks and independent acceptance remain open. Whisper
> context and song-edge fixes are implemented and now applied to nine runs
> (canary 1221/1209/1314; macro-song re-runs 1060/1009/948/1274/1303/1340), not
> to the corpus. Readiness was verified and the workers restarted twice on
> 17 September; re-verify before the next batch rather than citing those numbers.
> **The repair canary passed on content** (§4.0a) and its two blockers are recorded:
> the silent baseline re-cut of held sermons is fixed (`283a6cd90`); the detection
> job still retries an unplaced-hold refusal. **Device playback remains unproven** —
> every 17 September check was transcription and stream measurement, not playback.
> No production state was inspected in this review.
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
**Last condensed:** 2026-09-12
**Last reviewed:** 2026-09-17 — recent commits and saved blind-review evidence
reconciled with the execution tasks. The database census remains dated 16 September;
this review does not refresh it. Prioritise bounded transcription/boundary repair
and independent verification before dependency-grouped reruns. Earlier measurements
and operator rulings remain evidence, not current status where superseded.

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
- **Worker readiness is NO-GO for queued repairs.** All six long-lived queue
  processes started on **12 September**: video, historic FFmpeg, Whisper and
  orchestration at 00:21:27 UTC; general and historic LLM at 02:06:45 UTC. They
  predate the 15–16 September changes. Shared files on disk do not refresh classes
  already loaded into PHP workers. No restart was performed during this review.
  Evidence: `plan-review-20260916-workers.txt`. The canary must first verify idle
  queues, restart the relevant workers and record fresh process/code evidence.

**Status vocabulary used below:** *implemented* means the response exists in code;
*applied* means named existing rows were processed through it; *verified* names the
specific check and population that passed; *held/deferred* preserves uncertainty
without claiming repair. These states are independent: a tested fix is not a
repaired corpus, and a correct hold is not a confirmed defect.

| Workstream | Implemented | Applied / verified on existing outputs | Remaining |
|---|---|---|---|
| Explicit holds and occasion exclusions | Yes; holds survive sync and merges | Named holds, identity containment, four occasion exclusions and publication demotions applied; dated state above | Reconcile blind-review holds; held-then-reprocessed canary; source-adoption decisions |
| Song boundary/loop evidence | Version 5, input fingerprints, lyric-edge and loop risks | Successive backfills and demotions recorded in §4.3a | Current-policy scope/limitations; independent audio evaluation; identity confirmation |
| Smart cut, MP3, source audio format | Yes, including `fda414eb2` source-frame correction | Tests and limited real-source measurements; no completed corpus repair pass recorded | Device/source-content canary, then affected media reruns |
| Structure and song identity | Prompt seconds, closing prayer/reading retention, sustained singing, mistyped sung-item risk, catalogue-title priority and 17 September speech-edge trim | Retrospective measurements and tests; trim replay is not a corpus write | Per-run re-detection/re-resolution, then extraction and derived-item repair; speech-edge exceptions |
| Local Whisper decoding | Context carry disabled; initial prompt retained on both request paths; options fingerprinted | Seven-run experiment and MP3-form check; request/fingerprint tests | Pipeline retranscription of 1314/1343/1258/980; wider raw-transcript census; dependent structure/analysis and independent audio checks |
| Video-quality detector | Dead-picture coverage and owning-run evidence | 48 prior rejections reassessed (28 approve/19 reject/1 review); seven prior approvals moved to review | 862 where its output lives; 897/1005 unassessed; source-versus-cut black-picture causes; independent negatives |
| Acceptance and release | Existing signed release machinery | Nine-service source inventories compared; acceptance incomplete; no new release authorised | Interior semantic review, fresh reserved evaluation, canary, bounded repairs, operation closeout, exact-membership QA and delivery |

The 10–11 September transcript/media-readability census and its missing-hold list
remain in the preserved follow-up review at the end of this document. They are not
fresh readability checks or current missing-hold counts. The five confirmed sermon
loops and §§988/1457/3869 were held on 13 September (§4.1).

### 3.1 Plan-review corrections — 2026-09-13

A review of this plan against code and the live local database (no state changed
since the 2026-09-11 gate recheck) found six gaps that would otherwise stall
execution. They are folded into §4 and recorded here so their origin is clear.

1. **§1457 / SongVideo 191 is unaccounted for.** The gate recheck
   (`storage/scratch/correctness-20260911-gate-recheck.json`) assesses 30 song
   sections: the 28 current-policy failures, §988 and §1457. §1457 belongs to run
   1034, which owns sermon 969 of a deferred duplicate pair. It is unheld, published
   and gate-clear. The register does not say why it was rechecked.
2. **No application path can hold these exact rows.**
   `service:screen-transcript-repetition --apply` raises a sermon hold only when its
   own detector fires, and the five confirmed loops are precisely the ones it misses.
   No command writes a current song-policy objection into `needs_manual_review`
   either. Containment needs an explicit, reasoned, tested hold path, or it waits
   on the detection change.
3. **The release-review gate never reads banked verdicts.**
   `HistoricReleaseReviewHolds` decides solely from the stored `needs_manual_review`
   column; it deliberately never re-runs policy. The defect is that current-policy
   objections never reach that column, not that the gate prefers `release_eligible`.
4. **An ordinary structure hold cannot contain identity rows.** Every existing hold
   on the pair runs is `structure_low_confidence`, which the gate deliberately
   excludes (`HistoricReleaseReviewHolds::SpanQuestioningFlags`). A sermon is refused
   only by a hold on its own sermon/children's-talk section or a span-questioning
   flag. No membership exclusion mechanism exists yet.
5. **Release requires operation 4 to be `Complete`; it is `planned`.**
   `HistoricSermonReleaseAuthorisation::resolveCompleteOperation()` refuses release
   otherwise, and `Complete` requires every checkpoint complete plus an exact
   closeout. This shapes containment and must be designed before §4.5, not
   discovered at the release step.
6. **The three failed runs need terminal dispositions.** Keeping them visible is not
   convergence.

### 3.2 Pre-execution investigation — 2026-09-13

Read-only measurements taken to size §4. Nothing was written, held or moved.

- **Durability: accepted as re-derivable (operator decision 2026-09-13).** The
  quarantine is 239 GiB, single-copy on `/Volumes/Staging`, which still links at
  USB 3.0 SuperSpeed. Neither a backup nor the cable remedy is required: these are
  quarantined, in-progress outputs, and the sources survive in the Sonnics archive.
  The accepted cost of losing the drive is re-work, not data loss. Reprocessing is
  not deterministic, so holds, transcript repairs, identity decisions and this
  review's section-id-keyed findings would need re-deriving. Bulk writes are over and
  the staging guard already contains detaches. If a census suddenly reports missing
  media, confirm the volume is mounted before trusting it.
- **§1457 belongs with §988 in 4.3.** The archived Phase 8 review
  (distinct-5-gram reading) classed it as "extended spoken introduction absorbed".
  Current policy returns no objection, so it is the same policy blind spot as §988,
  not a boundary-policy failure or an identity row.
- **The 3-second framing floor explains none of the 27 boundary candidates.** Their
  measured framing is 3.1–185.7 s: 19 `spoken_framing_exceeds_limit` (39.2–185.7 s),
  7 `spoken_framing` (3.1–29.2 s), 2 trailing content (17.2 s, 19.0 s), with two
  sections carrying two reasons. §1168 at 3.1 s is the only one near the
  false-positive band (≤1.7 s). The floor stays a separate decision.
- **The existing backfill cannot be the song hold path as it stands.**
  `service:backfill-song-boundary-evidence` selects only sections with no banked
  evidence or with banked bounds that disagree with the section; `--section` narrows
  that set and cannot widen it. All 28 hold agreeing evidence, so they are never
  selected. `SongBoundaryEvidenceBackfill::writeSection()` already raises holds
  without clearing them, so a stale-verdict selection mode on this tested path is
  the smallest route. It still cannot reach §988 or §1457, where policy finds nothing.
- **P8-Q1 transcript spans are contained.** Dry run of
  `historic-import:repair-sermon-transcript-spans` on operation 4: 159 already
  repaired, 16 repairable, 234 unaffected, 0 unresolved. All 16 sermon sections are
  held by `sermon_text_predates_evidence` (0.1–11.8% of text to remove; sermons
  1034, 1198 and 1266 lose over 10%). Repair them in the 4.2 text-recovery work.
- **Songs absorbed into sermon spans are rare and contained.** Among completed
  operation-4 sermons, the non-song sections a span absorbs up to the next song are
  almost all short song introductions (the intended rule). ~~Only two are sung
  material typed `other`: §1301 and §981~~ — **corrected 2026-09-16**: only
  §1301 “Lo He Comes With Clouds Descending” (290 s, run 1014) is sung. §981
  “O Church Arise” (31 s, run 973) is the hymn's *announcement*, and the hymn was
  never sung on that recording: the audio ends at 3132 s with no sustained sound
  after 3000 s. Both sermons remain held. A hymn *inside* the sermon section
  itself (#885's case) is invisible to section queries; it is now located and
  measured under §4.3a — run 949, 4635–4795 s inside sermon §723 — so it no longer
  waits on 4.5's held-out validation.
- **The failed runs are three different problems.** Run 1004 has no service, no
  sections and no underlying exception in `laravel.log` after RMS generation began
  (2026-09-04 12:14 UTC); diagnose before retrying. Run 1143 (2024-08-11) produced
  16 sections with one 1,216 s sermon yet failed on multiple 20-minute speech blocks.
  Run 1145 (Thursday 2024-08-08) has four sections and only a 706 s children's talk,
  matching the no-sermon event class of #978.
- **Sermons show no new unheld class.** On completed operation-4 runs, no published
  or pending sermon or children's-talk section carries an unheld structure flag. The
  six unheld sermons under 10 minutes are genuine: a funeral (run 1051), a carol
  service (1196) and short homilies (1019, 1031, 1065, 1198).
- **Song identity is the largest unmeasured dimension.** 387 of 390 published
  operation-4 songs are `confirmed` and unheld, yet lyric matching has never matched
  and the 2026-09-10 transcript check found 3 of 4 candidates wrongly identified.
  32 published, unheld songs carry `structure_oos_cross_type_inversion`, which
  `SectionReviewFlagPolicy` always demotes because it "questions which OoS item a
  section aligns to". For a song, that item is its identity. Unverified; §4.1a
  samples it.
- **Dimensions no current item audits:** song identity beyond the four adjudicated
  cases, AI analysis content (titles, Scripture references, summaries), speaker
  attribution (paused), sermon start boundaries, hymns inside a sermon section, and
  playback beyond header probes. §4.1a measures them.

## 4. Remaining work in execution order

**Execution order revised 2026-09-17.** The detailed sections retain their stable
numbers and evidence anchors; they are not a requirement to finish every detector
before exercising a repaired run. Work in this order:

1. **Reconcile and contain.** The read-only status/exposure reconciliation is done
   in §3, including subsequent identity containment (§4.4). Reconcile the blind-review
   hold status against dated gate evidence; act on current-policy discrepancies and
   unassessable evidence below through the tested paths. Keep historic and weekly
   populations separate; inspect both whenever a shared rule changes.
2. **Finish independent review without reusing discovery as acceptance.** The
   nine-service inventory/comparison in §4.1b is complete (1336, 1221, 936, 1097,
   1066, 1314, 1358, 950, 1034). Complete outstanding interior semantic checks and
   BC-05's source replay. Preserve recorded prior exposure; these cases now inform
   tuning and are regression evidence, not untouched evaluation. Add bounded positive and detector-negative
   audio checks for song loops and lyric edges; lyric agreement alone is not audio
   adjudication. Preserve the reserved evaluation set separately. This is discovery
   and validation, not a nine-service claim of corpus accuracy.
3. **Prove one bounded repair canary (§4.0a).** **Done 2026-09-17 for source-content
   alignment and hold persistence; device playback is still unproven and remains
   required before a bulk rerun.** The canary also proved that re-running is not
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
5. **Close substantive gaps and operation state alongside the repairs.** Prioritise
   §4.2's context-drift recovery, short/varying loops and sparse loss; §4.3's
   performed-song confirmation and unresolved speech-edge cases; quoted hymns
   mistaken for singing and hymns wholly inside sermons; Scripture linking and
   missing assets. Source dropouts/truncation can end in a reasoned hold or accepted
   limitation rather than attempted reconstruction. Resolve the three failed runs,
   deferred source-adoption pairs and operation 4's legitimate completion route
   before final convergence; do not fabricate checkpoints.
6. **Accept and release only exact membership (§4.5).** Independent repaired-output
   checks, current-policy evidence, editorial QA, thumbnails, convergence artifacts,
   real browser delivery and operator-signed batches remain required. Increasing
   the number of holds is not this phase's success measure.

### 4.0 Stabilise the plan's references

- [x] Commit the 2026-09-12 plan condensation and archived execution log so the
  evidence anchors this plan links to are stable. Done in `211c7f10b`.

### 4.0a Repair canary and run dependencies — added 2026-09-16

**Canary execution record — 2026-09-17 (complete; see the verdict below).** Plan revision `2f21812eb`.
Frozen membership is **1314, 1221, 1209**: full pipeline reruns for 1314's context
drift/quoted-hymn ending and 1221's spoken song edges; extraction-tail rerun for
1209's held concatenated WebM sermon (#1128, measured MP3 tail loss 8.2 s).
No stale manual segment confirmation is recorded on these runs. Expected outcomes:
fresh non-fragmented text and a source-correct ending on 1314; retained singing
with reduced speech on 1221; aligned joined picture/audio and a complete MP3 on
1209; coherent replacement and preserved holds everywhere. An unplaceable hold
must stop the run, not be cleared to make the canary pass. Tests and real-artifact
checks remain separately reported; no public release is authorised.

Readiness: all nine configured queues were empty (including reserved/delayed),
all six workers started at 16:26:11–12 UTC with the current Whisper/edge code
hashes, and staging/temp paths were readable in every worker. Host headroom was
20 GiB; staging had 606 GiB. Operation 4 still records `external_disabled`.
The existing focused suites passed **72 tests / 265 assertions**. Evidence prefix:
`storage/scratch/canary-20260917-`; source hashes, row snapshots and prior-media
backup paths are frozen in `canary-20260917-frozen-baseline.json` before dispatch.

Containment reconciliation: §3994 was already held (without the explicit content
hold); §3992/§3227/§4334 had no own review hold. The three transcript sections and
the nine BC-01/02/03 song sections now carry explicit evidence-backed content holds.
§4264 was demoted through the application path; §2719 was already not applicable.
This supersedes the comparison-session's proposed-only hold status, not its findings.

**First failure, 16:42:10 UTC: 1209 stopped before media extraction.** The existing
`sermons:re-extract` command dispatched successfully, but the resolver rejects
sermon §2572's `content_defect_hold` even though this particular hold describes
MP3 tail loss, not disputed bounds. It falls back to `processing_log` bounds
(`no_high_confidence_sermon_section`), then the extraction job refuses multiple
qualifying RMS blocks. The dry run printed bounds and success without exposing
that execution refusal. This is a repair-path gap: a held output needs a tested,
explicitly bounded repair route that retains its release hold, not blanket
permission to extract every content-held span. Do not clear the hold or manually
confirm an RMS segment to manufacture a pass. Evidence: `canary-20260917-failure-1209-plan.json`
and `canary-20260917-failure-1209-integrity.json`. MP3-tail and joined-output acceptance
remain untested by this attempt; no bulk repair is authorised by the canary.
Side effect: run 1209 now reads `failed / manual_review_required`; its previous
sermon media is untouched (no extraction ran). Cause in code:
`SermonExtractionPlanResolver::resolveSermonSpan()` takes the baseline branch
whenever `findPreferredSection()` returns nothing, and a held sermon is not preferred.

**1221 — PASS with notes (completed 16:57:18 UTC).** Fresh transcript (new
context settings); re-detection placed every hold (§2718, §2719, §2722 still held).
Song bounds against the blind inventory: §2718 114.1–251.1 (singing ~115; was
96.1, 18 s of lead-in removed); §2719 251.1–423.3 (prayer from 422; 24 s of prayer
removed); §2722 1120.0–1284.7 (singing 1122; 24 s removed). Held candidate clips
were re-cut to exactly those spans (137.0 / 172.3 s, plus §2721 435.0 s) and stay on
the staging volume only: held sections move to `not_applicable`, and promotion copies
`pending_approval` candidates alone. SongVideo 506 (§2719, carried 27 s of prayer)
was withdrawn; §2726 re-cut to SongVideo 568 (quarantined). Sermon #1139 re-cut:
1800.3 → 1777.9 s, MP3 and video hashes both changed; the forgotten-video
announcement (2445–2461) is now its own section, but the sermon start 2465.1 clips
~3 s ("Do have your Bibles open at John chapter 10", from 2461.8). §2721 is now
typed `childrens_talk`, the inventory's view; the BC-07 ruling is still open.

**1314 — stopped by the hold guard, 16:54:34 UTC.** The fresh transcript is clean
(868 cues, was 3712) and reads, at 3370–3418: "I thought I'd quote it as I finish
… It's all about meekness", then the two Wesley verses as speech, then "well let's
sing". All three detection attempts produced no song over 3381–3420, so
`UnplacedContentHoldException` refused §3994 (`song, 3380.99–3419.99`) and wrote
nothing. This is the BC-01 defect disappearing with its content, which the guard
cannot distinguish from lost content; resolution is an operator decision. The
sermon hold §3992 would carry onto the new sermon (same type, overlapping), so the
repaired ending stays held for review either way. Two further findings:
`DetectServiceStructure` retried a deterministic refusal twice (three paid
detection calls, 13 minutes), and each retry is a fresh sample that could have
re-created the wrong song span just to satisfy the guard; and the refused
detection is not persisted, so its proposed replacement cannot be inspected.

**Operator decisions, 17:00 UTC.** (1) Release §3994 and retry 1314 from detection;
(2) build a bounded repair route for a held sermon, then re-run 1209.
§3994 was released through `ConfirmServiceSection` as user 1 with the transcript
evidence recorded under `manual_review.release_note`
(`canary-20260917-release-3994.json`); 1314 was retried at 17:03:49 from
`DetectServiceStructure` (job offset 2, fresh transcript reused).

**1314 retry — structure right, sermon media WRONG, 17:10 UTC.** Every hold
placed: §3985 and §3992 still held, §3994 is now the closing benediction, and the
sermon §3992 runs 1712–3417 with the quotation inside it (BC-01 structure fixed).
But the held §3992 sent the plan to the baseline, the RMS check found one dominant
block, and **ExtractSermon silently re-cut the sermon as 1016.5–3436.0** (testimony,
prayer and reading included). Nothing was released (quarantined, held). **A full
pipeline re-run of any content-held sermon therefore degrades its media without an
error; no bulk re-run of held runs may proceed until that path refuses or parks
instead of cutting from the baseline.**

**Repair route landed, `6c7d33539`.** `sermons:re-extract --held-section=<id>`
records the named section's current span on the run (`held_sermon_span`); every
plan resolution then cuts from that section while its span is unchanged and no
other flag disqualifies it. The hold stays. The command now refuses a
recorded-bounds fallback plan, dry run included, and names any held sermon.
13 new/updated tests; focused suites 85 passed; Pint and PHPStan clean. Workers
restarted at 17:10 onto this code (queues empty).

**1209 — PASS, 17:17:19 UTC.** Plan unchanged from 09-09 (reading 1758.86–1872.99
+ sermon 2123–3742.0, was 3740.08). MP3 1733.17 s against video 1733.15 s (was
8.2 s short); local Whisper on both last 20 s ends "…in Jesus' name. Amen.";
streams start together (0.066 s) and end within 12 ms. §2572 still held.
A listen across the join (114 s) is still to do.

**1314 re-cut — PASS, 17:20:53 UTC.** `--held-section=3992`: reading + sermon
1614.0–3421.2; media 1807.2 s (audio and video agree); head transcribes as the
Philippians 2 reading, tail as the full Wesley quotation then "Well, let's sing."
Sermon text period-per-word ratio 0.07 (was 0.89; BC-08 fixed on this run).

**Canary verdict:** retranscription, song-edge trimming, hold placement and the
held-sermon repair work on these three runs. Blockers before wider repair:
(a) the silent baseline re-cut of held sermons on a full re-run; (b) the detection
job retrying an unplaced-hold refusal. Holds on all three runs remain for operator
review; nothing is authorised for release.

**Blocker (b) fixed, `690ae1d5d`, 2026-09-18.** An unplaced content hold is a
deterministic refusal, and the exception escaped `handle()`, so the queue's three
tries re-ran the detector — 1314 paid for three detections in thirteen minutes,
and each retry was a fresh sample that could have re-created the very span the
hold was placed against, satisfying the guard by regenerating the defect. The job
now catches the refusal, records the refused replacement under
`service_structure_proposal` with `refused_reason: unplaced_content_hold` (so it
is distinguishable from a proposal that failed validation — this one *passed*),
and parks the run at `manual_review_required` with reason `unplaced_content_hold`.
That code is deliberately new: neither `ProcessingPhaseRegistry` nor
`HistoricVideoImporter` treats it as re-derivable, so nothing re-detects it
automatically. No speech segments are offered and the chain stops. A reconcile
refusal leaves the completed run and its sections untouched. Both blockers in the
canary verdict are now closed.

**Merge-case hold persistence: verified, no defect, `8d98e45b8`, 2026-09-18.**
The canary left merges uncovered. Both merge paths turn out to be correct already:
`ServiceSectionSyncService::withContentHolds()` filters *every* held span
overlapping each incoming section and dedupes the holds on `(reason, evidence)`,
so a merge lands all of them on the surviving row and a split holds both new
rows; `MergeAdjacentServiceSections` and `SongContinuationMerger` each carry
before removing. Four missing cases are now pinned: a re-detection merging two
held songs keeps both reasons; a re-detection splitting a held macro song holds
both halves (over-holding costs a release click, under-holding publishes content
an operator proved wrong — the asymmetry is irreversible); a manual merge keeps
the survivor's own hold alongside the carried one; and because the action
promotes the *longer* section to primary, the row passed in as primary can be the
one deleted, so a held shorter section still has its hold carried first.

<a id="run-1340-transcript-regression"></a>

### 1340's regression is a transcript regression — measured 2026-09-17/18

**Not a detection or media fault. The macro-song split worked (447 s → 195 s).**
The source recording has a genuine 240-second hole: the left channel is dead
throughout (−107 to −111 dB), the right sits on a −52 dB noise floor to 1520, and
**1520–1580 is absolute digital silence (`-inf`)**. There is no preached reading
in the recording, so `structure_missing_preached_reading` is a true statement
about the source, not a processing defect.

Today's fresh pass filled that hole with **eight consecutive 30-second
`Thank you.` cues, 1355.1 → 1595.1**. The pathology detector did catch it (8 cues
≥ 6; 240 s ≥ 120 s) and banked `1355.08–1595.06` as `retranscription_failed`, so
the in-pipeline retry failed too. **But the loop's final cue overran the
resumption of speech.** Speech restarts at ~1576 (source RMS −26.6 dB, peak
−0.01 dB) and the filler ran to 1595.1, swallowing it; normalisation stripped the
filler, leaving a hole, so the sermon could only start at 1595. Independent local
Whisper on the source at 1555–1625, temperature 0: *"…tells the story of a woman
living in France who, when she was young, wrote lots of promises from the Bible on
little pieces of paper."* The sentence is real and in the source.

**So an "unobservable window" is not necessarily unobservable content.** The
window's bounds are the *loop's* cue bounds, and a loop's last cue can extend past
the point where speech resumes. The existing reading — blind windows are ASR loops,
not lost evidence — holds for a window's interior and breaks at its trailing edge,
which is exactly where a sermon starts.

**A re-transcription was discarding previous recovery.** The recovery replay
repoints a run *at* the recovered artifact, so a fresh pass writing
`normalized.json` silently reverts it. Measured: **146 live runs carry a
`transcript_recovery_replay` stamp, and exactly the five re-run on 09-17 (948,
1009, 1274, 1303, 1340) now point at a plain `normalized.json`** — each stamp now
misdescribes its run. The recovered files survive on disk, so it is reversible.
Checked before acting: **1343, 1258 and 980 carry no stamp and no recovered
artifact on disk**, so the three pending re-transcriptions are not exposed.
Evidence: `storage/scratch/recovery-exposure-20260917.php`,
`recovery-loss-v2-20260917.php` and `recovery-loss-20260917.json`.

**Operator ruling, 2026-09-18: fix the retry window first, then add the fallback.**

**Retry window fixed, `c933889b9`.** `PathologicalWindowSoundSpans` narrows a
window to the spans whose RMS clears −45 dB (bridging pauses under 5 s, dropping
spans under 4 s, padding by 2 s), and recovery decodes those instead of the whole
window — a 240-second clip that is five-sixths silence hands the model the very
conditions that made it loop, while a 70-second clip around the speech edge
decodes cleanly. A window with **no** sound is not decoded at all and is recorded
`window_holds_no_sound`, not `retranscription_failed`: a silent stretch of the
service is a property of the recording, a failed decode is unfinished work, and
the acceptance accounting needs them apart. Two compatibility points: an
unnarrowed window keeps the historic `…-recovery-N` artifact name that the replay
addresses banked retries by (only a split window gains a sub-index), and
`recoverUsing()` is untouched, so a replay still reproduces the decision its run
actually made.

**Superseded-transcript fallback landed, `7d6bbde95`.** `TranscribeFullService`
reads the stored transcript before overwriting it and hands it to recovery, which
consults it for any window still ending as `retranscription_failed`. Three limits:
a window measured as holding no sound is not filled (an older transcript claiming
speech there was hallucinating); a loop the older transcript had in the same window
is not carried; and carried cues keep a window entry of their own, reason
`carried_from_superseded_transcript`, because the text is real but did not come
from this decode. One known limit: 1340's other lost cue (1347.3, *"And I will be
able to find the way."*) falls *before* the banked window, so the fallback does
not reach it — the window is the unit of repair, and reaching wider would overwrite
new text on audio the fresh pass did read.

**1340's repair is therefore a re-run on this code, not a span edit**, and
§4299's content hold stops extraction until an operator names it
(`--held-section=4299`). Not yet executed: workers still hold the pre-`c933889b9`
code.

**Blocker (a) fixed, `283a6cd90`, 2026-09-17.** When no sermon section is usable and
the run has a content-held sermon, the resolver's fallback plan now carries reason
`sermon_section_content_held` (with the held section ids), and `ExtractSermon` parks
the run at `manual_review_required` before the RMS check, offering no speech blocks
and naming the `sermons:re-extract … --held-section=<id>` repair. A plain retry
resumes at extraction and parks again; `RedetectHistoricServiceStructure` does not
treat the reason as re-derivable. 230 tests across the neighbouring suites pass;
Pint and PHPStan clean; workers restarted onto it (queues empty). Read-only plan
resolution over all 46 live runs with a content-held sermon: 44 now park, and
1209/1314 cut from their authorised sections. Not covered, and a separate decision:
three live runs (1007, 1041, 1217) were cut from a dominant RMS block while a
low-confidence or interruption-merged sermon section existed; whether those cuts
are right is unmeasured.

**Those three runs, measured 2026-09-17 — the RMS cuts are right, and the narrow
scope is confirmed.** Local Whisper on the quarantined sermon MP3s: 1007 opens
"the younger ones are going to go out now… open your Bibles at 1 Timothy",
1217 opens "A beggar sits beside the road", 1041 opens "I thought I'd follow
Ralph's example and bring a few visual aids"; 1007 and 1217 close with the prayer
and "Let's stand… and sing", before the singing. The service audio confirms singing
ends exactly where each cut starts. So **the sections are wrong, not the media**:
§1258 "No One Is Good" (2241–2672) overruns 92 s into the sermon and §2675
"O Church Arise" (2000–2647) overruns 475 s, while §1259 and §2676 start that much
late. Parking a disqualified (unheld) sermon would have replaced better media with
worse in two of three cases, so the parking rule stays scoped to content holds.

**New defect class: an overlong song section swallows the sermon's opening.** The
late sermon section truncates what is sliced from it: §2676's text begins mid-
sentence "In an experiential way…", missing the beggar illustration, and §1259's
begins 92 s in. Corpus screen — a song section over 360 s ending within 60 s of a
sermon start, live historic runs: **8** (1217/§2675 647 s, 1060/§1647 507 s,
1009/§1276 459 s, 1340/§4298 447 s, 1007/§1258 431 s, 1274/§3437 424 s,
1303/§3861 391 s, 948/§709 385 s); all 8 carry `structure_macro_section`, and
948, 1009 and 1060 published a song clip from that section. Six were cut from the
sections, so their **media** starts late too: verified on 1060, where the service
audio has the preacher from ~2180 ("the little ones are going out… turn to Luke 23")
through the whole A.J. Gordon bird-cage illustration, while the published sermon
starts at 2342 and loses ~2.7 minutes. 1009, 1274, 1303, 1340 and 948 are the same
shape and unlistened. 
**The three published clips each hold the sermon's opening (Whisper, last 100 s).**
§709 (948, 385 s) ends inside a Matthew 6 sermon introduction; §1276 (1009, 459 s)
ends inside a sermon opening; §1647 (1060, 507 s) ends inside the A.J. Gordon
bird-cage illustration.

**Re-detection repairs the class — run 1060 re-run 18:04–18:14 UTC.** With the
09-17 song trim and Whisper settings, the 507 s macro song split into two real songs
("Man of Sorrows" 1830–2002, "Speak O Lord" 2018–2183, both ~170 s), every review
flag cleared, and the **sermon moved from 2342 to 2190**, where the preacher in fact
begins. Effects: sermon media 1973 → 2154 s and its text now opens "Well, the little
ones are going to go out…" (3346 → 3673 words) where it previously began mid-
illustration inside a Whisper loop ("helplessly, and helplessly, and helplessly");
the wrong SongVideo 216 was withdrawn cleanly (row and file gone) and §1648's new
candidate is 164.6 s of singing at both ends. Baseline snapshot
`redetect-20260917-1060-baseline.json`; media backed up under
`/mnt/historic-work/redetect-20260917/1060/`. **All five remaining re-runs executed 2026-09-17, 18:26–19:52 UTC** (baseline
`redetect-20260917-macro5-baseline.json`; media backed up under
`/mnt/historic-work/redetect-20260917/`; comparison script
`redetect-20260917-compare.php`). Four repaired, one regressed:

| Run | Macro song | Sermon start | Text now opens | Verdict |
|---|---|---|---|---|
| 1009 | 459 s → 162 + 192 s | 2199 → 2131 | "If someone asks you… the good news or the bad news first" | repaired; media and re-cut 161.6 s clip verified by ear |
| 948 | 385 s → 284 s | 2290 → 2191 | "Do have your Bibles open at Matthew chapter 6" | repaired; new §709 candidate ends on the last verse, no speech |
| 1274 | 424 s → 289 s | 2360 → 2220 | "I love my wife, and from time to time, I will buy her flowers" | repaired; text 3406 → 3632 words |
| 1303 | 391 s → song 166 s + reading 183 s + prayer 42 s | 1713 → 1709 | unchanged, already correct | repaired structure; the §3858 content hold carried, and its clip withdrew with it |
| 1340 | 447 s → 195 s | 1576 → **1595** | "who, when she was young…" (was "Montgomery Boyce tells the story of a woman living in…") | **regressed**: media and text lose the opening sentence, and 1340–1595 (the preached reading) is now unsectioned, raising `structure_missing_preached_reading` |

1340 §4299 was held for content on 2026-09-17 so the truncated sermon cannot be
released; repair it through the pipeline rather than by editing the span, and the
hold now stops extraction until an operator names it (`--held-section=4299`).
Three runs (948, 1340) also carry `sermon_text_predates_evidence` after the re-run,
the ordinary regeneration debt. So the class repairs by re-running, 4 of 5 here; §2897's continuous speech over organ stays the
known gap, untested by this case. Incidental: split contractions ("don t we")
appear at similar rates in old and new transcripts (111 in 1007's old text against
62–70 in today's), so they are not a regression from the Whisper change.

- **Execution authorised 2026-09-17:** the operator requested this plan update,
  a commit to master, then the bounded canary. This authorises the necessary local
  pipeline repair and readiness work, not release or automatic hold clearance.
- [ ] Include run 1314 for context drift and the quoted-hymn/sermon-ending challenge,
  plus the smallest additional membership needed for a concatenated sermon, known
  MP3 tail loss, effective pre-existing hold and an eligible speech-edge trim.
  A still-wrong quoted-hymn boundary is a recorded canary failure, not permission
  to adjust it manually. Freeze exact membership and expected outcomes before writes.
- [ ] Select the smallest set of runs covering a live content hold, a concatenated
  sermon, a known MP3 tail loss and a changed song boundary. Cases may overlap;
  include different recording formats where the extraction path differs. Bind the
  selection to source hashes and expected content, with approvals/releases disabled.
- [ ] Verify queue backlog/reserved jobs before restarting the relevant general,
  video and historic workers. Record revision, fresh process starts and the code
  seen in their mounted checkout. Current §3 processes are not ready. Restart is
  a prerequisite to the canary, not evidence that existing outputs are repaired.
  - *Working disk, 2026-09-16:* 2.6 GB free of 460 GB on the host, with MySQL live.
    The canary's re-encodes and transport-stream intermediates need headroom;
    free space before dispatching, not during.
- [ ] Exercise the supported pipeline re-extraction/replacement path, including an
  equal-duration recut and a held-then-reprocessed run. A matching old duration must
  not cause the repaired output to be silently reused. Verify a failed/interrupted
  replacement leaves the prior generation and its holds coherent, then resumes.
- [ ] Compare source content with the actual stored video/MP3 at starts, ends,
  randomly selected interiors and every join. Check picture identity, audio content,
  alignment, channels, sample rate, lengths and full decode; durations alone missed
  the initial smart-cut defect. Verify no generation mixes old media with new text,
  bounds or verdicts. Missing/unreadable measurements are **unassessable**, never a
  successful cut; resolve or explicitly contain them before acceptance.
- [ ] Play one repaired sermon and song clip on Safari/iOS, including seeking
  across joins, then test the real served URLs/ranges and cache freshness in the
  recorded environment. Local/device proof does not replace destination checks.
  - **Desktop Safari plays a transport-stream join cleanly (2026-09-16).** The
    unverified item in the 09-15 smart-cut note is answered for macOS Safari only.
    A two-minute artifact was cut from run 1066's own source through
    `VideoExtractionService::extractConcatenatedSegmentAsFile()` — the last minute
    of its preached reading (145–204.994) joined to the first minute of its sermon
    (382.998–442.998), so the join falls at 60 s
    (`storage/scratch/safari-20260916-cut.php`, output
    `storage/scratch/safari-20260916/run1066-join.mp4`). Measured before playback:
    a clean full decode, a keyframe at 60.066 s, audio continuous across the join
    (5,167 packets, 0.066–120.043 s, no gap), matched levels either side (−33.0 and
    −33.7 dB mean), and `moov` before `mdat`. The operator played it in macOS Safari
    with seeking across the join: **no stall, no desync**. This matters beyond one
    file because **192 of 438 completed runs (44%) are `concat_spans`** and every
    one is joined by `joinThroughTransportStream`.
  - *Not covered by that result:* iOS Safari, a real *repaired* output, a song clip,
    a full-length file, the planned span starts, range requests over the actual
    server, and cache freshness after replacement. The temp disk was overridden to
    `local` for the artifact, so it was not produced on the staging volume.
- [ ] **Restore the staging volume before any extraction (found 2026-09-16).**
  `MEDIA_PROCESSING_TEMP_DISK=historic_temp` roots at `/mnt/historic-work/temp`,
  and that bind mount is stale: `mount` lists it, `ls` reports it missing and the
  parent shows `d?????????`, because the drive detached. Every extraction path
  fails at `UnableToCreateDirectory` until it is back. Remount the host volume
  (`diskutil verifyVolume /Volumes/Staging`); restarting Docker does not clear a
  stale `/host_mnt` entry. This is a canary prerequisite alongside the workers.
- [x] Confirm content holds follow the affected content after replacement, and
  unrelated holds remain. **Proven 2026-09-17 on replacement only**: 1221's three
  song holds, 1303's §3858 hold and 1314's §3992 sermon hold all carried onto the
  re-detected sections, and the guard refused 1314 outright when §3994's held content
  ceased to exist. **Merges are still uncovered.**
- [x] Confirm the same across merges. **Done 2026-09-18 (`8d98e45b8`): verified,
  no defect.** Both merge paths already carry every covered hold and dedupe on
  reason and evidence; merge, split, survivor's-own-hold and the longer-section
  swap are now pinned by tests. Re-assess current evidence only after final song
  bindings and boundaries are settled. No automatic clearance follows merely
  from a clean new detector result; adjudicate carried holds explicitly.
- [x] Save canary results and failures against the exact code and artifacts. Done
  2026-09-17: `canary-20260917-*` and `redetect-20260917-*` under `storage/scratch`,
  media backups under `/mnt/historic-work/redetect-20260917/`, and the commits named
  in this section. Before dispatching bounded repair batches with per-run dependency lists. Measure
  correct outputs, unassessable outputs, false flags and review time, not just job
  completion or hold counts.

The already identified batches are: five prompt-offset re-detections; the
sustained-song/lyric-edge re-detections (including 1014 for mistyped singing and
1287's shifted songs); 72 deterministic title re-resolutions, with the three
fallback suggestions separately adjudicated; seven closing-prayer and four
preached-reading replans; and the 12 MP3-tail repairs plus affected smart-cut/audio
format outputs. These memberships overlap: derive their union by **run id**, keep
the reason/stage mapping, and do not sum their counts into a batch size. Correct
livestream-derived OoS items after identity is settled. Record a response or
explicit decision for remaining detector classes before final acceptance; do not
weaken that requirement to make the repair canary proceed.

### 4.1 Contain confirmed missing holds first

- [x] Decide and build the hold path (§3.1 item 2): a narrowly scoped, tested action
  that holds a named section with a recorded reason and evidence reference, and that
  the review-flag recomputes cannot silently clear. **Built 2026-09-13:**
  `HoldSectionForContentReview` raises `content_defect_hold` and records each reason
  and evidence under `content_holds`. It refuses types the release gate cannot refuse
  on (anything but sermon, children's talk and song). `service:hold-section-content
  --section=… --reason=… --evidence=… [--execute]` is dry-run by default and
  all-or-nothing over the named membership.
  - The flag recompute and the structure rederive keep the flag; tests prove both.
  - The two spoken-announcement retypes and both children's-talk speaker-naming
    paths used to force the review column false; they now keep a content hold.
  - Only operator confirmation releases the hold; the recorded reasons remain as
    history.
  - Re-running a service replaces section metadata from detection, and with it the
    hold. Re-apply the hold after any re-run. **Superseded 2026-09-15:**
    `ServiceSectionSyncService::sync()` now carries a live hold to every incoming
    section of its type that overlaps the held span (rows are kept by order, so the
    hold follows content, not the row), keeps released holds' history, and refuses
    the whole write with `UnplacedContentHoldException` when a live hold overlaps
    nothing. **Merges covered 2026-09-16:** both mergers re-raise a removed section's
    live holds on the survivor through `HoldSectionForContentReview::carry()`, and
    `service:scrub-prompt-echo-sections` keeps and names a held section rather than
    deleting its containment. Released holds are never re-raised. The
    held-then-reprocessed *run* below is still required.
  - Dry run on 2026-09-13 mapped §531/§1263/§2411/§3703/§4032 to sermons
    872/943/1106/1214/1242, and §988/§1457/§3869 to song videos 135/191/371.
    Executed the same day; see the gate recheck below.
- [x] Put effective holds on sermons 872, 943, 1106, 1214 and 1242 on their **sermon
  sections** (§531, §1263, §2411, §3703, §4032). Holds on other sections of those
  runs do not refuse the sermon.
- [x] Put effective holds on song sections 3869 and 988.
- [x] Put an effective hold on §1457 alongside §988 (§3.2: same absorbed-speech
  class, invisible to current policy).
- [x] Recheck the live content-review gate and record exact before/after evidence.
  **2026-09-13, local database.** Before (17:34:35 UTC), `HistoricReleaseReviewHolds`
  returned no refusal for sermons 872/943/1106/1214/1242 or song videos 135/191/371.
  After holding all eight (17:34:53 UTC) it refuses every one. Each sermon is refused
  on its own sermon section's `content_defect_hold`; each song video is refused
  because its section awaits review. All eight remain `quarantined`. Evidence:
  `storage/scratch/content-holds-20260913-gate-before.json` and `-gate-after.json`.
- [ ] Keep the assets quarantined while their text or cuts are recovered.

### 4.1a Measure what the known defects do not cover

The defects in §4.1–4.4 are what one review's instruments could see. Every Phase 8
review found a class its predecessor had no instrument for, so treat this plan as
"known defects", not "all defects". Measure the residual rate before designing the
heavier repairs, because a new class could change them. Run this after §4.1's holds
and before §4.2–4.4.

- [x] Draw a random, stratified held-out sample of about 30 services across eras and
  service kinds (Sunday, special, concatenated/partial recordings). Exclude members
  already named in §4.1–4.4 so the sample measures the unknown residue.
  **Drawn 2026-09-13** (seed 20260913) from 442 completed active historic runs, less
  92 already named (§4.1–4.4, the 09-11 register, text-evidence and continuation
  holds, adjudicated song identity): 3 concatenated, 4 special, 15 Sunday morning
  across 2020–2026, 8 Sunday evening. Runs: 940, 944, 1014, 1234, 1246, 1260, 1233,
  938, 1347, 1325, 1307, 1255, 1259, 1197, 1243, 1182, 1137, 1167, 1112, 1102, 983,
  1001, 980, 1175, 1141, 1094, 1116, 1071, 1010, 992.
- [x] Check every sampled service against one fixed checklist, from source media and
  the saved outputs:
  - song identity and count, against the audio and the order of service;
  - song clip boundaries;
  - sermon start and end, including a hymn inside the sermon section;
  - saved sermon-text integrity, including loops and missing passages;
  - Scripture references, title and summary;
  - children's-talk span;
  - playback, decoding the clip rather than probing its header.
  Method: per-run dossiers of sections against the order of service, boundary
  transcript excerpts, saved-versus-derived text diffs, ≥3-repeat loop scans; five
  frames per generated song clip; targeted source-audio re-transcription, loudness
  and frames wherever a signal appeared; a full container decode of every sermon
  video, sermon audio and song video (84 files).
- [x] Record per-dimension results. Zero defects in 30 bounds that dimension's rate
  below about 10% at 95% confidence (rule of three); report the bound, not "clean".
  Services with a defect, with the two-sided 95% upper bound:

  | Dimension | Found | Upper bound | Defects |
  |---|---|---|---|
  | Song identity and count | 2/30 | 22% | §3024 bound to the wrong song; 944's "My Hope Is Built" typed `other`, so no clip |
  | Song clip boundaries | 1/30 | 17% | §2897 ends in ~25 s of spoken benediction; policy silent |
  | Sermon start/end, hymn inside | 1/30 | 17% | 980's recording starts mid-sermon over a silent opening |
  | Saved sermon-text integrity | 2/30 | 22% | 1112 lost ~12 min to a new ASR class (below); 1347's reading has "the Lord" ×16 (§4.2 class) |
  | Scripture, title, summary | 1/30 | 17% | 944's title and reference describe a different talk from its summary and video |
  | Children's-talk span | 0/8 | 37% | Only 8 sampled services have one |
  | Playback (full decode) | 0/84 files | 4.3% | 30 sermon videos, 30 sermon MP3s, 24 song videos decode end to end with no errors; six MP3s run 2–8 s short of the stored duration and two song videos 2–3.5 s long (a note, not a failure) |

  Not defects: in 11 runs the saved text omits the prayer and hymn between the
  reading and the sermon, and 26/30 sermon videos equal reading plus sermon to
  within a second, so the intervening hymn is cut by design
  (`SermonExtractionPlanResolver::selectBibleReading()`).
- [x] Probe song identity directly, alongside the sample:
  - sample about 8 of the 32 published, unheld songs carrying
    `structure_oos_cross_type_inversion`;
  - compare title with transcript on a random set of `confirmed` published songs.
  **Result:** only 20 such songs remain published, unheld and with a video (15
  outside known runs); 0/8 sampled are wrong (§2598 plausible, not proven). Of 12
  random `confirmed` songs, 1 is wrong (§631, upper bound 38%). The inversion flag
  is not the identity signal; divergence between printed and sung order is.
- [x] Take every defect found as a class to census corpus-wide, contain it through the
  §4.1 hold path and add it to §4.2–4.4. Do not repair from the sample alone.
  Censuses (all 442 runs unless stated) and containment, 2026-09-13:
  - **Sparse 30-second-cadence transcript loss (new).** Whisper emits one short cue
    per 30 s chunk instead of the speech inside it. Too few repeats for the loop
    screen, too few words for the density fallback, and no unobservable window.
    178 cadence spans in 139 runs are almost all "Amen"/"Thank you" over music;
    those flanked by dense speech inside a talk are 1112 §3739 and 1278 §3490, both
    confirmed by fresh audio, and 1362 (already held). → §4.2.
  - **Wrong song identity in `confirmed`, unheld clips.** Heard section title versus
    bound song over 460 generated videos found §508, §519, §631, §2350 and §2638
    wrong, confirmed by the sung lyrics and announcements; §2683 was a false positive.
    §3024 escapes that census because its heard title agrees with the wrong binding,
    so the census is a floor. Mechanism in §3024: printed and sung order diverge
    around a reading or a back-to-back pair. → §4.3.
  - **Published title/reference describes another talk.** Published versus heard
    reference over 438 sermons: 422 agree, 10 have no heard reference, 6 disagree.
    Confirmed: sermon 881 (944); 954 (1019, 3 John published, 2 John preached);
    844/845/850 (1380–1382), pre-existing rows created 2026-05-28 with null title
    provenance that keep other weeks' titles. Candidate: 899 (963, a carol-service
    reading as reference). → §4.5 editorial QA.
  - **Source audio dropout inside a talk.** RMS ≤ −80 dB for ≥15 s: confirmed 1089
    §1790 (frames show the preacher) and 980 §1016; unheld candidates 988 §1152,
    1080 §1748 (children's talk), 1087 §3711, 1088 §1786, 1339 §4291 and 1043 §1530
    (also a §4.2 candidate); 940, 1322, 1360, 1362 already held. Not repairable from
    the pipeline; accept or exclude by operator decision.
  - **Held through `service:hold-section-content`:** sermon sections 3739, 3490, 667,
    4666, 4667, 4678, 1335, 1790, 1016; song sections 3024, 631, 508, 519, 2350, 2638,
    2897. The gate before (18:01:28 UTC) refused only sermon 1220, for an unrelated
    macro-section flag; after (18:02:04 UTC) it refuses all 9 sermons and 7 song
    videos, all still `quarantined`.
  - Evidence: `storage/scratch/residue-20260913-register.json` and the
    `residue-20260913-*` files beside it.
- [x] Keep the 27 unvalidated short-loop candidates out of the sample; they are
  §4.2's.

**Conclusion.** The known list is not the whole defect population: the sample found
defects in 7 of 30 services outside §4.1–4.4, and three new classes (cadence
transcript loss, title/reference drift on adopted rows, source dropouts), plus a
wider song-identity problem than the four adjudicated cases. The heavier repairs
should be designed with these classes in, and §4.5's fresh sample stays mandatory.

#### Round 2 — 2026-09-13/14

Seven problem services in 30 was too many to stop on, so a second sample was drawn
with a stopping rule: **sample again until a round finds no new class.**

- [x] Draw a second seeded sample (20260914) of 30 from the 305 runs not already
  sampled, held or named as candidates, with the same strata. Runs: 955, 973, 975,
  1306, 1195, 1293, 1329, 1372, 933, 1323, 1316, 1285, 1286, 1214, 1222, 1118,
  1142, 1165, 1106, 1036, 1011, 997, 949, 1188, 1186, 1046, 1081, 1065, 996, 1017.
- [x] Check it against the same checklist.

  | Dimension | Found | Defects |
  |---|---|---|
  | Song identity and count | 0/30 | Slides or audio agree with all 26 generated clips |
  | Song clip boundaries | 1/30 | §1475 (1036) ends in ~40 s announcing and starting the reading |
  | Sermon start/end, hymn inside | 1/30 | §1684 (1065) ends "fourthly, worship" at the end of its only source file |
  | Saved sermon-text integrity | 0/30 | Gaps are songs, and a seated reading (1323) |
  | Scripture, title, summary | 0/30 | — |
  | Children's-talk span | 0/9 | — |
  | Playback (full decode) | 0/84 files | 29 sermon videos, 29 sermon MP3s and 26 song videos decode end to end. Video 970 (1036) logs 880 duplicate-timestamp warnings from the discard output, but its stored packets are strictly increasing and it decodes cleanly: a note, not a failure |

  **No new class within the checklist.** Both defects belong to classes round 1
  already found, so the round 2 stopping rule is met *as written*. Across both
  rounds, 9 of 60 services had a defect outside the known list (95% upper bound
  26%). Run 955 produced nothing because both source files are silent, although the
  video shows the service; that is correct.

  **Review correction (2026-09-14).** That stopping rule is checklist-bounded and
  is withdrawn as an acceptance signal. "No new class" means no new class in the
  seven dimensions the checklist opens; a checklist cannot report a dimension it
  never asks about, and every new class this programme has found came from a new
  instrument (the cadence scan, the lyric scorer, the hint census, the RMS dropout
  scan, the published-versus-heard reference comparison), not from a further sample.
  Thirty draws also leave about a one-in-five chance of missing a class present in
  5% of services, and two rounds do not change that below about 5%. Both samples
  further excluded every held run, so a run held for one reason has had its other
  fields examined by nobody. The 26% bound is a rate for the known dimensions on
  the unheld population; it is not evidence about unknown dimensions. §4.1b replaces
  the stopping rule with a coverage rule and moves discovery from sampling to
  corpus-wide instruments.

  **Notes reclassified as open items (2026-09-14).** Three observations were
  recorded above as "a note, not a failure" without investigation. Under the
  programme's definition (an error in any field, media or data), each is an
  unclassified finding until shown otherwise, and is carried into §4.1b:
  - six sermon MP3s run 2–8 s short of their stored `duration` (either the field or
    the encode is wrong; determine which and whether the tail is lost speech);
  - two song videos run 2–3.5 s longer than their section span;
  - video 970 (1036) logs 880 duplicate-timestamp warnings on decode;
  - §1216 (1001) was passed on slide evidence although its transcript loops
    "and the risen lamb, who never is" for the whole second half, which the §4.3
    lyric-coverage check cannot score. Looping song transcripts are a class of
    their own, not a property of the identity check.
- [x] Census song identity directly, rather than sampling it further.
  - **Lyric instrument:** score each song section's transcript against every song's
    lyrics. It flags 5 of 7 known-wrong clips and 0 of 38 frame-verified correct
    ones; the misses have looping transcripts. It flagged 15 published, unheld clips,
    and slides confirm all 15 are wrong.
  - **Hint instrument:** compare `song_title_hint` with the bound song. It found 4
    more, confirmed by slides (§1956, §4070, §4372, §4533). Most of its other
    disagreements are alternate titles for the same song (It Is Well / When Peace Like
    A River, The Servant King / From Heaven You Came).
  - **Mechanism, the same in every case:** the detector hears the right title, and
    `title_hint_fuzzy` resolves it to a song that shares a title or first-line word.
    Rock of Ages → O Safe To The Rock; O Lord My God → Fill All My Life; Behold Our
    God → All glory be to Christ; God of Glory → Almighty Lord Most High; I Know That
    My Redeemer Lives #462 → #907; King of Kings → Listen! Wisdom Cries Aloud.
    Livestream-sourced order-of-service items are then written from the wrong song.
  - **25 generated clips confirmed wrong** in total (6 in round 1, 19 now).
  - **31 pending-approval sections** carry the same hint disagreement (one, §3580, is
    an alternate title). They have no clip yet, and approving them would publish the
    wrong song. They are not held; they are listed in the register.
- [x] Census talks cut by the recording itself: §1684 (1065), §1793 (1090) and §3943
  (1310) end mid-flow at the end of their only source file, and §1421 (1031) starts
  mid-thought. There is no second part on the archive for any of them.
- [x] Census sung items typed as non-songs: 2 (a sung Psalm 46 typed as a reading in
  1253, a sung hymn typed as prayer in 1349), neither inside a sermon span. This is a
  floor: 944's §666 escapes it because its transcript loops.
- [x] **Held:** song sections 991, 1007, 1054, 1326, 1817, 2213, 2750, 2929, 2994,
  3054, 3122, 3191, 4134, 4184, 4250, 1956, 4070, 4372, 4533 and 1475; sermon sections
  1684, 1793, 3943 and 1421. The gate refused none of the 4 sermons and 20 song videos
  before (19:01:55 UTC) and all 24 after (19:02:03 UTC); all remain `quarantined`.
  Evidence: `storage/scratch/residue-20260914-register.json`.

### 4.1b Find the unknown unknowns — coverage, not sampling

Added 2026-09-14 after the review of round 2. The aim of the programme is that
every future service processes correctly, so the question is not "what is the
residue rate on the seven checked dimensions" but "which outputs has no instrument
ever opened". This section runs after §4.1 and before the heavier repairs in
§4.2–4.4 are finalised, because a class found here changes their design, exactly
as §4.1a's classes did. It does not replace §4.1a's sample; it supplies the
dimensions the sample must cover next time.

**Method.** Complementary discovery mechanisms, including checks that can expose
classes not named in advance. None establishes that all possible errors are covered:

1. *Field and expected-output coverage.* Enumerate every column, file and derived
   artifact the pipeline should produce, including outputs with no row yet, and
   record its check. Unchecked rows identify gaps in the current instruments.
2. *Disagreement census.* For every field, find a second, independent derivation
   and census the disagreement corpus-wide. Disagreements are candidates to
   adjudicate; agreement can share an upstream error. Trace evidence lineage before
   counting two views as corroboration, including planned versus performed content.
3. *Tail inspection.* For every numeric field, examine the extreme few percent by
   hand. New classes cluster in tails.
4. *Whole-output consumption.* Watch and listen to complete outputs as a
   congregant would, on the rendered pages, not as frames and excerpts.
5. *Consumer-side rendering.* Render the whole quarantined membership through the
   public surfaces, then verify representative playback through the real server and
   browser delivery path. An in-process render does not exercise media transport.
6. *Blind source review.* Record what happened in the original recording before
   opening generated answers, including random interior passages and meaning.
7. *Content alignment across handoffs.* Compare source, planned spans, extracted,
   stored and delivered content, including joins and audio channels.
8. *Controlled variations and interruptions.* Change a real input in a way with a
   known expected relationship, or interrupt and resume processing, to expose hidden
   assumptions without needing a perfect expected answer for every field.

With 442 completed runs in the recorded baseline, cheap automated checks run over
the whole eligible corpus. Reconcile current membership before each new census,
including failed runs and missing-output cases where applicable; do not silently
reuse 442 as every denominator. Sampling is reserved for expensive or human checks,
with its scope and uncertainty reported. Prioritise blind source review, content
alignment and controlled variations before finalising the heavier repairs.

#### Field coverage matrix (drafted 2026-09-14 from the schema; keep current)

Status: **corpus** = the named instrument ran over its recorded eligible population;
**sample** = it ran over a stated subset; **none** = no check yet. These describe
execution coverage, not correctness or detector sensitivity. A "second view" is a
proposed comparison whose independence must be established. Each row must distinguish
presence, internal consistency and source-truth checks; passing one does not pass
the others. Human-only checks may remain sampled with a justified design and bound.

- [ ] Extend the machine-readable matrix with exact eligible membership and its
  hash, counts actually checked and unassessable (with reasons), check kind,
  instrument/version, thresholds, run date, input/output evidence hashes, evidence
  lineage, known blind spots and adjudication state. An unavailable transcript,
  source or external plan is unassessable, never a pass. Reprocessing or changed
  policy invalidates dependent evidence until the relevant checks run again.
- [ ] Split mixed populations and partially checked rows. "Not produced" requires
  a scoped reason and decision, not an empty-column inference. Deferring the 422
  default preacher assignments does not validate the 16 non-default assignments;
  audit those against source evidence. Likewise inspect the 31 existing thumbnails
  even though generation for the remaining membership is deferred.
- [ ] Reconcile expected outputs against actual outputs from source truth and
  applicable pipeline contracts: talks, readings, songs, media, passage enrichment
  and downstream artifacts. Include failed runs, no-output runs, pending candidates
  and held rows. Distinguish legitimate absence, deferred approval/generation,
  exclusion and unexplained omission. A planned OoS item alone does not prove it was
  performed. Existing-row scans and writer scans cannot establish completeness.

| Output | Existing check | Status | Second view to census |
|---|---|---|---|
| Sermon `date`, `service` slot | `identity_correct` in the item ground truth (IC3); date census 2026-09-14 (service, filename, mtime, weekday, pre-import service row) | corpus | no new mis-dated row (P8-Q7 sermons 969, 1045, 1297 reconfirmed); **class**: Saturday rehearsals imported as services (1043, 1089); funerals 1051 and 1098; both excluded by ruling 2026-09-14 |
| Sermon `content_type` (sermon vs children's talk) | sample checklist | sample | OoS item section type; duration band |
| Sermon `title`, `title_provenance` | sample; adopted-row census (§4.1a); title census 2026-09-14 (summary, section title, transcript, `ai_analysis`) | corpus | no new wrong title (844, 845, 850, 881 reconfirmed); null provenance on 31 rows is pre-tracking, not adoption |
| Sermon `reference`, `scripture_passage_id` | published-vs-heard census (§4.1a); Scripture census 2026-09-14 (passage verses, listed, plan and page reading, spoken) | corpus | **defects**: multi-passage truncation on link (4), whole-letter references rejected (2), 7 never linked, page names the wrong reading (157), wrong reference 899 |
| Sermon `summary`, `show_summary` | sample (summary vs video) | sample | title; transcript keyword overlap; run `ai_analysis` |
| Sermon `meta_description` | — | not produced (0/438; derived from summary at render) | check the rendered value in consumer-side rendering |
| Sermon `points`, `show_points` | — | none | transcript; summary |
| Sermon `slug` | — | none | title; uniqueness; placeholder pattern (`ReslugPlaceholderSermons`) |
| Sermon `preacher`, `preacher_id`, `preacher_source`, `preacher_confidence`, `needs_preacher_review` | coverage census 2026-09-14 (422/438 `default`) | 422 defaults deferred until retraining (ruling 2026-09-14); 16 non-default assignments unchecked | source identification; OoS email preacher; retrained speaker model |
| Sermon `series` | — | none | OoS; adjacent weeks' series; empty census |
| Sermon `segment_start_time`, `segment_end_time`, `duration` | duration census 2026-09-14 | corpus | span gaps are by-design concat plans; 902 plan ends past its recording |
| Sermon audio (MP3) content | decode sample (59); duration census and tail transcripts 2026-09-14 | corpus (length) / sample (loudness, clipping) | **defect**: 12 MP3s lose closing words; loudness and clipping still unchecked |
| Sermon video content | decode sample (59 files); header probe of 1,334 paths; format census against the source fingerprint 2026-09-14 (all 438 equal) | corpus (headers, format) / sample (decode) | A/V sync at start, middle, end; `video_quality_status` reason |
| Sermon `transcript_file_path` text | saved-vs-derived census (29 held); loop screen; cadence census | corpus | fresh re-transcription of a random minute; word rate; unobservable windows |
| Sermon `thumbnail_file_path`, `thumbnail_metadata` | coverage census 2026-09-14 | generation deferred for remaining membership; 31 existing thumbnails unchecked | check existing and subsequently generated thumbnails: frame inside the span, not black or a slide |
| Sermon `video_quality_status`, `video_quality_reason`, `video_visibility_override` | rejection adjudication and 2026-09-16 approvals screen (§4.3a) | 48 rejections reassessed; 429/432 approvals measured; independent detector-negative source review pending | 28 approved, 19 rejected, 1 to review in the rejection set. Approvals screen raised 8 cases: 7 adjudicated defects now set to review, 1 false flag. Three unavailable outputs and two never assessed (897/1005) remain explicit; agreement of both detectors is not independent acceptance |
| Children's talk span, speaker | sample (span, 17 services) | sample | OoS item; `CHILDRENS-TALK-SPEAKER-DECISIONS` shortlist; duration band |
| ChurchService `occasion`, `occasion_confirmed_at` | — | none | OoS email; special-service strata; calendar |
| ChurchService `summary`, `notices` | — | none | empty census; transcript |
| ChurchService `chapter_markers` | — | none | section starts; monotonic and inside source duration |
| ChurchService items: `position`, `section_type`, `song_id`, `title`, `source = livestream` | song identity census (items written from wrong song) | corpus (song id) / sample (count, order) | detected sections, count and order, all 442 runs; OpenLP/email items where present |
| ChurchService `review_state`, canonical revision/hash | gate rechecks | corpus | — |
| Sections: type, bounds, `song_title_hint`, match state, flags, `needs_manual_review` | sample; policy reassessment; hint and lyric censuses; section coverage census 2026-09-14 (every span over 60 s, fresh audio) | corpus | **defects**: closing prayer left out of 7 sermons; 8 song sections cut short and 10 songs with no section (singing invisible to the transcript); 6 spans need a listen |
| Sections: sung material typed non-song | census (2 found; floor) | corpus (floor) | lyric scorer over `other`/`prayer`/`bible_reading` sections with singing-like RMS |
| Sections: song transcript loops | song-loop census 2026-09-14 (P8-Q14 screen over every song section; fresh audio on the 65 half-loop sections) | corpus | **defects**: 229 song sections loop unnoticed by boundary and identity checks; 1287 §3597 wrong song; loops over speech let song sections swallow prayers (967, 1268, 1348); 13 unverifiable by audio |
| SongVideo `song_id` | hint + lyric censuses | corpus (floor) | slides frame OCR/hash against the song's lyrics for the `confirmed`-and-looping set |
| SongVideo `video_file_path` content | decode sample (50 files); five frames; format census and song-edge lyric census 2026-09-14 | sample (decode) / corpus (format, edges from transcript) | **defects**: 7 clips lose their own verses to a neighbouring section, 206 carries the next song, 410 is the wrong song; 245 clips upsampled to 96 kHz; still: full decode all 464, A/V sync, first/last frame not a speaker |
| SongVideo `duration` | duration census 2026-09-14 | corpus | **defect**: copied from the span, never probed; 89 frozen openings, 23 carry the preceding item's audio |
| SongVideo `recorded_date`, `is_featured` | owning-service date census 2026-09-14 (0 differ); date census 2026-09-14 against the source (0 differ); `is_featured` not produced | corpus | funeral hymns (211, 231, 232) excluded with their runs (ruling 2026-09-14) |
| Scripture passage enrichment (`EnrichHistoricScripturePassages`) | Scripture census 2026-09-14: 426 passages' api range and HTML verses equal the reference | corpus | orphan/duplicate passages still unchecked; 7 sermons never linked |
| Hymn usage apply artifact | §4.5 regeneration | pending | song identity census results |
| Search index / embeddings for historic sermons | — | none | index membership equals exact release membership; stale text after transcript repair |
| Public pages, sitemap, podcast feed, structured data | consumer-side rendering 2026-09-14 (all 442 sermons, 225 song pages, 441 service pages, listings, 2,150 sitemap URLs, feeds, internal links, as if released) | corpus | clean; **latent**: public sermon query omits `asset_disk`; sermon 857's MP3 missing; service links built while the archive is disabled |
| Quarantine visibility and notifications | §4.5 audit | pending | — |
| Expected outputs absent from the graph | song-gap and missing-enrichment findings only | partial; cross-output reconciliation pending | blind source inventory; applicable pipeline contracts; explicit absence or deferral |
| Transcript and analysis meaning | loop/cadence screens; summary samples | partial; blind semantic review pending | source audio, speaker attribution and time-bound support for each reviewed claim |
| Source → spans → extracted → stored → delivered content | duration census; RMS alignment on three runs | sample (alignment); handoff verification pending | content alignment at boundaries, interiors and every join; byte hashes where no transformation occurs |
| Cross-corpus duplicate content | date/reference-constrained pair screen | partial; unrestricted candidate search pending | audio fingerprints and transcript similarity without date/reference prefilters |
| Audio channel preservation and continuity | format census and limited loudness samples | channel/downmix and drift checks pending | each source channel versus final audio; speech retention, clipping, levels and join continuity |
| Retry/replacement generation consistency | archived canary/idempotence; equal-duration replacement regression and hold-transfer tests implemented | complete repaired-run interruption/replacement canary pending (§4.0a) | clean versus resumed processing; source-content identity, media/text/verdict generation bindings and hold survival |
| Actual browser playback and delivery | HTTP-kernel render census only | pending | real URLs, seeking/ranges, headers, access controls and replacement cache freshness |

- [x] Verify the matrix against the writers: grep every `Sermon`, `ChurchService`,
  `ChurchServiceItem`, `ServiceSection` and `SongVideo` write in the pipeline and
  add any field the schema draft above missed. Record fields the historic path
  never writes as "not produced" rather than "none".
  **Done 2026-09-14, read-only.** Grep alone cannot say what is produced, because
  writers pass arrays and variables. A populated-column census over every column
  and JSON metadata key of the five tables and the run row therefore decided it
  (445 runs, 438 sermons, 443 services, 7,301 items, 4,190 sections, 460 song
  videos), with writers traced by grep and the step ledger.
  - **Not produced:** `sermons.meta_description` (derived from the summary at
    render), `download_count`, section `matched_item_id`/`expected_item_id`
    (scrubbed legacy) and `published_sermon_id` (approval path only), SongVideo
    `is_featured`, and the run's visual-analysis columns.
  - **Rows the draft missed:** run `ai_analysis` (the seed of the sermon fields),
    section `sermon_reference`/`reading_reference` (a stored second view of the
    published reference), `song_ocr_text` on 73 sections (a stored third identity
    view), service `summary`/`notices`/`chapter_markers` (AI-written, not rendered
    on the public service page), item metadata, run `trim`/`audio_compression`/
    `service_transcript_suspect_blocks`, and the 579 pending-approval sections.
  - **Quarantined sermons get no thumbnail — accepted (operator ruling
    2026-09-14).** `GenerateThumbnail` asks
    `SermonExposurePolicy::shouldGenerateVideoThumbnail()`, which requires
    `publication_state = published`, so all 409 historic runs from 2026-09-01
    skipped it (step message: "Video quality verdict does not allow a public
    thumbnail"). Only 31 of 438 have one. This is wanted: thumbnails cost money to
    generate and should wait until the sermon details are settled. Nothing in the
    release path generates them, though, so §4.5 carries the obligation.
  - **Default preacher — accepted (operator ruling 2026-09-14).** 422/438 sermons
    carry "Visiting Speaker" because speaker identification was deliberately
    disabled (393 runs; 38 more below threshold): the model was not working, and
    the plan is to retrain it once the video corpus is larger. The field is
    therefore deferred for those default assignments; §4.5 carries the retraining.
    This does not exempt the 16 non-default assignments from source verification.
  - **Findings needing a census or ruling (now matrix rows):** 47 videos are
    auto-`rejected` (26 frozen frames, 21 mostly black), which hides them
    publicly, and nobody has looked at them;
    `occasion` is set on 1 of 443 services; no historic children's talk has a
    Sermon row (145 are pending approval, 16 held); references missing on sermons
    888, 957, 961, 1069 and 1090, and passage ids missing on 908–910 and 912–915.
  - **Checked clean:** sermon and SongVideo dates agree with their owning service
    (0 disagree); slugs are unique within the historic rows.
  - **Baseline caveat:** the 15 non-historic runs predate current detection code
    (13 of their 17 weekly-only section metadata keys no longer exist in `app/`).
    They are not a current-pipeline comparison; §4.5's regression set must re-run
    weekly services.
- [x] Give the matrix a home the next review can update
  (`storage/scratch/coverage-matrix-YYYYMMDD.json` beside the registers), with
  the instrument path and run date per row. **`storage/scratch/coverage-matrix-20260914.json`**
  (44 rows, each with its writer, historic population, check, status and second
  view), built from `coverage-20260914-field-census.{php,json}`. The table above
  is the summary; the JSON is the underlying measurement snapshot. The strengthened
  requirements above govern subsequent checks; older JSON statuses cannot satisfy
  them by themselves. Update the matrix with new evidence when those checks run,
  without rewriting the preserved baseline as though that work already happened.

#### Blind source review and evidence independence

- [x] Select a bounded discovery set before opening the generated answers, across
  eras, recording formats, ordinary and special services, partial/composite sources
  and availability of external evidence. Include held and apparently clean runs.
  Record membership and the selection method. Start with five varied services;
  this is discovery, not a statistically sufficient acceptance sample. The same
  operator can do source review and later comparison; no second reviewer is required.
  **Selected 2026-09-14** (`storage/scratch/blind-20260914-{select.php,selection.json}`,
  seed phrase `historic-blind-source-review-2026-09-14`; inventory form
  `blind-20260914-inventory-template.md`). The selection reads only date, slot,
  weekday, concatenation, OoS availability and held state, never a generated value.
  One seeded pick per stratum from 438 completed runs minus the four ruled exclusions,
  preferring runs not named in this plan:
  - 2020–21 without an independent OoS, not held: **run 1336** (2021-02-14 morning).
  - 2022–23 with an OoS, single source, not held: **run 1221** (2023-07-02 morning).
  - 2024 onwards, composite source, not held: **run 936** (2024-01-14 morning, five
    joined `.mkv` files). Only 3 runs were eligible and all are named in this plan,
    so this pick is not untouched. Its windows are on the joined timeline; with the
    files in name order they fall at 10-40 13:04 and 15:46, and 11-09 6:39.
  - Special or non-standard: **run 1097** (2025-04-13 evening, 21 minutes).
  - Held: **run 1066** (2025-08-31, sermon-only recording).
  - **Widened the same day, before any listening.** All five picks above were h264/AAC
    stereo, 1080p at 30 fps, and on a Sunday. A host ffprobe of every census source
    (`blind-20260914-source-formats.tsv`) found real variation: 33 VP9/WebM sources,
    14 mono, and 720p/480p, 29.97 fps or 25 fps H.264. It also found 11 eligible
    non-Sunday runs. A second seeded draw (seed phrase `…-widening`) added one pick per
    group. The first five were left exactly as drawn, which is verified by re-running.
    - Mono audio: **run 1314** (2021-07-18 morning).
    - VP9/WebM source: **run 1358** (2020-09-20 morning, 720p, held).
    - H.264 below 1080p or not 30 fps: **run 950** (2020-05-31, a sermon joined from
      two 29.97 fps parts, held).
    - Not a Sunday: **run 1034** (Monday 2025-12-22, Carols by Candlelight, held).
      All 11 eligible runs are named in this plan, so this pick is not untouched. It
      owns sermon 969 of a deferred duplicate pair (§4.4).
    - *Selection trap:* the script skips runs named in the plan, so recording the picks
      in the plan would redraw them on any re-run. It now reads the plan as it stood at
      `0436e3d37` (`blind-20260914-plan-at-selection.md`).
    - *Still not covered:* 25 fps (one source), 480p, 44.1 kHz versus 48 kHz as a
      deliberate contrast, and the audio-only or unprobeable sources (runs 955, 1375).
- [x] Listen to the original recordings first and record the actual sequence,
  identities, boundaries, interruptions and absent content. Do not show the reviewer
  generated sections, transcripts, labels or warnings until this source inventory
  is saved. Keep uncertainty explicit. Then compare all expected and actual outputs,
  including material for which the pipeline created no section or file.
  **Inventories saved 2026-09-16 20:59–21:54 UTC; compared 23:17–23:25 UTC**
  (`storage/scratch/blind-20260916-comparison/`: `00-prior-exposure.json` written before
  unblinding, `01-sections-raw.json` generated snapshot, `02-strips-*.txt` source-only
  sound measurements from `sound_strip.py`, register
  `blind-20260916-comparison-register.json`). A disagreement counts as confirmed only when
  the listened inventory and a source-only sound measurement agree against the pipeline;
  transcript text was a comparison only. Inventory 110 sections, generated 102.
  - **Identity is sound; bounds and type are not.** 23 of 23 sectioned performed songs
    carry the song named by ear (three differ only because the catalogue files them under
    a first line). Only 9 of 23 are within ~12 s at both ends. 8 of 8 sermon passages
    agree. Every planned-but-unperformed song has no section.
  - **Spoken lead-ins and tails are common, not rare (BC-03).** Seven unheld song
    sections carry 19–60 s of speech before or after the singing: 1336 §4264 (46 s + 23 s,
    published section, SongVideo 408), 1221 §2718 (24), §2719 (27 s of prayer, SongVideo
    506), §2722 (36), 1066 §1686 (23), §1688 (60), 1358 §4682 (19). Three more at 11–16 s.
    This widens the §4.3 "continuous spoken lead-in or tail" row from four named cases to
    roughly a third of the songs in a blind draw.
  - **New: a quoted hymn typed as a sung song (BC-01).** 1314 §3994 "You that do your
    master's will" (lyric-matched, `confirmed`) is spoken. The preacher says he will
    "quote it as I finish", and the audio stays speech until 3436. The sermon media stops at
    3381, before the quotation. This is the inverse of the mistyped-sung check.
  - **New: one-word-sentence transcripts (BC-08).** From 1314's sermon onwards every word
    ends a sentence ("One. Of. The. Amazing. Things."). A census of 438 sermon sections
    finds three (1314 §3992, 1258 §3227, 1343 §4334, ratio 0.85–0.89; next 0.47; 419 below
    0.1), all local large-v3-turbo; 980 is a partial fourth (sermon 0.31).
    **Cause established 2026-09-17** (`blind-20260916-comparison/bc08-20260917/`): the raw
    whisper.cpp output already drifts into one-word "Word." segments (1314 from 2028 s,
    1343 from 2251 s, 1258 from 2400 s), carried forward by unlimited text context
    (`whisper-server` default `--max-context -1`); normalization only copies it. A 180 s
    slice of 1314's broken span transcribes normally on its own, and a full rerun with
    production settings drifts again. **Sending `max_context=0` with
    `carry_initial_prompt=true`** (both per-request fields) removed the drift on all four
    runs (one-word segments 68–82% → 5–8%). It also **removed the decode loops on all seven
    runs tested** (loop words 10–2,142 → 0–21), including 1358's, where production loops had
    replaced a reading and a prayer. Timing held (median offset 0.1–0.4 s, p90 ≤ 1.7 s)
    and the prompt was not echoed. Healthy-looking 1221 and 1336 show 19–23% one-word
    segments in production, a partial drift that the section-text census cannot see. This
    bears directly on §4.2.
    **Adopted 2026-09-17 (operator), test-first.** `LocalWhisperDecoding::OPTIONS` is sent
    by both local whisper requests (service and sermon-only; recovery and repetition
    replays share the service path) and is recorded as
    `transcription.local_whisper_decoding` in the processing fingerprint. The fingerprint
    is recorded and compared per bundle, not enforced as a gate, so existing runs are not
    blocked. Rechecked on production's 32 kbps mono MP3 form: run 1314 had 57 one-word
    segments of 884, full stops per word 0.06, and 0.978 agreement with the WAV run. Gates:
    Pint and PHPStan clean, full suite 8,235 passing; Dusk not run (session permission).
    **Due:** restart the whisper, general and historic workers before any transcription;
    re-transcribe 1314, 1343, 1258 and 980 through the pipeline in the canary or a bounded
    batch. A corpus-wide re-transcription for loops is a §4.2 sizing question, measured
    first by one-word-segment and loop-word rates in the raw service transcripts.
  - Also: 1034 §1461 loses ~81 s of its carol to reading §1460 (BC-02, unheld; same class
    as §1459). 1221 §2722 is bound to a livestream item with no song id while OpenLP
    carries song 64 (BC-06). Two of four children's talks are typed `other` (1221 §2721,
    1358 §4684; needs a ruling, BC-07). 1336's sermon may end inside §4264 (replay
    3320–3380, BC-05). The inventory, not the pipeline, was wrong on 936 §619 and 950 §727
    (song announcements) and on 1034's 742 song start (digital silence 740–767).
  - Blind re-confirmation of existing holds: 1034 §1457, §1459, the unsectioned "O little
    town", and 1097's video rejection (no picture on the source). 8 of 9 sermons carry the
    default preacher (accepted deferral).
  - **Holds proposed, not yet run** (the dry runs were blocked by a session permission
    rule): §3994+§3992 (BC-01), §1461 (BC-02), the seven BC-03 sections, and §3992/§3227/§4334
    (BC-08). Dry run first, operator approval before `--execute`.
    **Status reconciliation due 2026-09-17:** this is the comparison session's
    historical state. The later unsung-song measurement says §3994 is held; no
    dated gate result here reconciles that claim with this list. Read the exact
    sections before the canary, record existing reasons/refusals and apply any
    necessary containment through the tested path under the canary authorisation.
- [ ] Draw and save random interior source windows before consulting detector
  results, alongside complete-source review. Check fluent but incorrect words,
  missing negation, names and numbers, missing sentences, quotation attribution and
  speaker changes. After unblinding, check reviewed titles, summaries and points for
  unsupported claims or a changed meaning, citing source timestamps for support or
  contradiction. Keyword overlap and normal word density are insufficient.
- [ ] For each corroborating signal, record its original evidence and every
  transformation that could have inherited another signal's answer. The structure
  model receives both transcript and OoS: its title hint is not automatically
  independent of either. Published fields versus `ai_analysis`, linked passage text
  versus a rewritten reference, or two copies of one transcript count as consistency
  checks, not independent confirmation.
- [ ] Separate intended from performed content. An OoS entry and a matching slide
  can both describe a song that was changed. Require performance evidence for a
  performed identity; a fresh ASR pass, even using another model, is a comparison
  whose disagreements need adjudication, not ground truth by default. Preserve
  unassessable cases rather than resolving them by majority vote among derived fields.
- [ ] Give the 152 runs without an independent OoS in the recorded census their
  own coverage and result breakdown. Source listening and channel/content checks
  must cover them; no missing corroboration may become a clean agreement. Apply
  the same distinction to runs lacking usable lyrics, intelligible audio or slides.
  *Blind set, 2026-09-16:* five of the nine have no independent OoS (1336, 1314, 1358,
  950, 1034). Song identity was right on all 16 of their sectioned songs, so the
  missing plan did not cost identity. Their confirmed defects (BC-01/02/03/05/07/08) are
  bounds, type and text, and the OoS would not have caught those anyway, because it
  carries no timing.

#### Content alignment across processing handoffs

- [ ] Census content alignment across every eligible sermon MP3/video pair and
  generated clip versus its source spans. Use audio fingerprints or waveform
  alignment tolerant of the actual encoding/enhancement, checking the beginning,
  interior, end and both sides of every concatenation join. Measure offsets, drift,
  missing, duplicated and reordered material. Low-information or ambiguous matches
  are unassessable and require another view. Codec fingerprints and equal durations
  establish format/length only; alignments against a plan establish extraction
  fidelity, while blind source review establishes whether the plan was right.
- [ ] Bind source identity/hash, ordered extraction spans, final artifact hashes,
  stored object identity and applicable policy/version in the evidence for a run.
  Compare byte hashes at copy-only handoffs; compare decoded content where encoding
  changes. Verify that delivered media correspond to the stored artifact through
  the actual-delivery checks below. A new plan or new bytes must not retain a verdict
  certifying an earlier generation without revalidation.
- [ ] Compare each source audio channel with final MP3 and video audio. Look for
  speech confined to one channel, cancellation during mono downmix, processing-added
  dropouts, clipping, abrupt level changes and progressive A/V drift, especially
  after joins. Distinguish source faults from introduced loss; calibrate thresholds
  on good outputs and confirm candidates by listening. Full decode is still needed
  but cannot establish intelligibility or synchronisation.
- [ ] Search the whole source/output corpus for exact and near-duplicate content
  using hashes, audio fingerprints and transcript similarity without first
  requiring equal dates, references or titles. Adjudicate reused Bible readings,
  repeated hymns, rehearsals, alternate encodes and repeated performances separately.
  Similarity is a candidate signal, never automatic deletion or source substitution.

#### Controlled variations and interruption tests

Use small, local fixtures derived from representative recordings and existing
test conventions. Record the transformation, expected relationship, tolerance and
input/output hashes. These tests expose assumptions without requiring an exact
answer to every field; they complement tests against source-reviewed truth. This
applies behavioural testing principles described in
[CheckList (ACL 2020)](https://aclanthology.org/2020.acl-main.442/); no new dependency
or separate harness is required.

- [ ] Implement and evaluate the following relations on the affected pipeline
  stages, then exercise representative cases through the weekly path. Where a model
  call can vary, judge semantic and timing tolerances fixed before the run, rather
  than byte-for-byte wording. Preserve captured responses for deterministic
  regression tests and report live-model evaluation separately from mocked tests.

| Variation or interruption | Expected relationship / failure to detect |
|---|---|
| Add ten seconds of leading silence | Content identities and order stay the same; source-relative boundaries shift by ten seconds within the declared tolerance. |
| Split and losslessly rejoin at a safe boundary | Same content inventory and identities; no duplicated/missing join material or blanket suppression of songs because a source is concatenated. |
| Remove the OoS | Uncertainty may increase; the pipeline must not invent content or replace the observed performance with a familiar service pattern. |
| Supply a deliberately wrong planned song | Actual performance evidence must trigger disagreement or uncertainty, rather than two descendants of the wrong plan confirming each other. |
| Shift a cut while preserving duration | The stored video, MP3, transcript and review evidence follow the new ordered spans; equal duration must not preserve the old file. |
| Put speech in only one stereo channel; use a cancellation-prone fixture | Final audio preserves intelligible speech or explicitly flags inability to do so; a successful mono encode alone does not pass. |
| Interrupt after extraction, during replacement upload, after row linking, or before verdict/publication preparation | Resume reaches a consistent generation with correct assets, identities and effective holds; no duplicates, dangling paths or old verdict certifying new bytes. |
| Replace media served at an existing URL | The real delivery path returns the new artifact and seeks correctly; stale caches cannot silently serve the earlier cut. |

- [x] Start the equal-duration test at `ExtractSermon::authoriseReplacementIfCutChanged`
  and `StoreSermonVideo::prepareHistoricNestedJob`: replacement detection compares
  duration, and completed historic storage may skip without its flag. This is a
  code-supported test target, not a newly confirmed corpus defect. Test an ordinary
  extraction retry as well as explicitly requested re-extraction.
  **Fixed test-first 2026-09-14 (`62591d5e7`).** A span moved by one minute at an
  unchanged 1,800 s left the run unmarked, so storage would have skipped. The test
  failed, confirming the bug. `StoreSermonVideo` now records the stored video's spans
  (`stored_video.segments`). Extraction marks a replacement when the spans *or* the
  duration differ by more than 0.5 s. The duration and moved-span cases run
  through an ordinary extraction without a requested re-extraction. Explicit
  `reExtract()` already sets the flag.
  - *Remaining limit:* no existing stored video has recorded spans. They fall back to
    `trim.segments`, which cannot reveal an **earlier** equal-length retry whose
    storage was skipped. The corpus-wide content alignment census above is the check
    for those.
  - Interruption and replacement-failure tests remain open below.
- [ ] Exercise failure around old-file removal and replacement upload in
  `SermonMetadataIntegrationService::organizeVideoFile`, plus downstream row linking
  and review recomputation. Compare clean processing with interrupted/resumed
  processing by content, graph membership and evidence validity. Retries need not
  reproduce model wording, but must never combine incompatible generations.
- [ ] Include a held-then-reprocessed run. The current re-detection path can remove
  section metadata and its hold (§4.1); prove an effective hold is preserved or
  re-established against the new sections before acceptance can proceed. Clearing
  a hold requires current evidence for its reason and the other required dimensions.
- [ ] Confirm each new regression fixture fails for its intended reason before
  fixing a reported bug, then passes through the standard pipeline. Keep paid
  evaluations bounded and explicitly report actual calls separately from local
  fixture tests; another paid corpus replay is not the default discovery method.

#### Disagreement censuses to run (all 442 active runs unless stated)

Each is cheap, read-only, and produces a candidate list to adjudicate by the
§4.1a method (frames, fresh audio, slides). Every confirmed disagreement becomes a
class in the §4.3a detector table and a hold through the §4.1 path.

- [x] Preacher against the OoS email preacher and, where the paused speaker model
  has a stored verdict, against that. Census `preacher_source` values first; a
  corpus of `default` is itself a finding. **Closed as not produced (2026-09-14):**
  422/438 are `default` because speaker identification is deliberately disabled
  until it is retrained on this corpus (operator ruling). §4.5 carries the retraining;
  census the retrained output then.
- [x] Sermon `duration` against decoded MP3 length, decoded video length and
  `segment_end_time − segment_start_time`. Resolve the six short MP3s here.
  **Done 2026-09-14, read-only, all 438 sermons**
  (`storage/scratch/duration-20260914-{census,register}.json`).
  - Video length equals `duration` for all 438, to within 1 s.
  - The span exceeds `duration` by over 30 s on 192 sermons (up to 887.5 s). Every
    one is a `concat_spans` plan whose segments sum to the duration: the hymn cut
    between reading and sermon. Not a defect.
  - Sermon 902's `sermon_only` plan ends at 1,349 s but its recording is 1,320.3 s.
    The media end at the recording; no section ends past its source corpus-wide.
  - **The short MP3s are a real defect: the MP3 loses the closing words.**
    Stream-copy cuts start the video's picture at the keyframe before the cut,
    after its audio, while the MP3 is cut to the plan. So `duration − picture
    delay − MP3 length` estimates speech missing from the MP3's end; it matched the
    measured offset within 0.1 s. 424 of 436 sit at or under 1.5 s (1102, at a 2 s
    raw gap, ends on identical words). The 12 over 3 s were all confirmed by
    transcribing both files' last 20 s. 11 lose closing-prayer or sermon words
    (1266 ends "it's another thing to put,"; 1270 ends "will guard your heart"),
    and 1096 loses only a hymn announcement. All decode without error, so the loss
    is in the cut. Sermon sections 4321 and 4495 were already held. 2310, 2358,
    2446, 2479, 2558, 2572, 3804, 4215, 4386 and 4508 were **held 2026-09-14**
    through `service:hold-section-content` (operator approval). Before (20:23 UTC)
    the gate refused none of their ten sermons for this defect; after, it refuses
    all ten. All remain quarantined
    (`storage/scratch/duration-20260914-gate-{before,after}.json`).
  - **Cause (traced 2026-09-14): the MP3 is cut to the plan's length from a video
    that is longer than the plan.** For `concat_spans`, `ExtractSermon` stream-copies
    each span, and each piece starts at the keyframe before its cut, so the joined
    file carries every piece's lead-in. It then cuts the MP3 from that joined file
    as `clip(0, plannedDuration)`, so the tail beyond the planned length is dropped:
    the sermon's final seconds. 1257 is the one `single_span` case; its MP3 is cut
    from the source, so its 4.8 s loss has a second, untraced cause.
    Not a repair artefact: video and MP3 were written in the same minute for all 12.
    Its time clustering (09-07 and 09-09) follows when most runs were processed.
  - **Why the picture starts after the sound (ruling 3a context).** A stream copy
    cannot start a video mid-GOP: the first picture it can show is a keyframe, while
    audio frames can start almost anywhere. The joined file therefore opens with up
    to one GOP (1–5 s here) of sound before its first picture. Nothing about the
    content differs; it is an artefact of cutting without re-encoding. The same
    cut causes the MP3 tail loss, so one extraction fix can remove both.
  - The six short MP3s from §4.1a's samples are the lower end of the same
    distribution (2–8 s raw gap), not a separate class.
- [x] SongVideo `duration` against decoded length and section span.
  **Done 2026-09-14, read-only, all 464 song videos**
  (`storage/scratch/songdur-20260914-{census,register}.json`).
  - **Stored `duration` never measures the file.** `SongVideoService` copies it from
    the section span, so it equals the span on all 464 by construction. 25 files
    actually run over 1 s longer.
  - **The same keyframe effect as the sermon videos, in two shapes** (fast-copied
    h264 clips, 452 of 464):
    - *Frozen opening picture* (89 over 1 s; 9 at 3–6 s; 1 at 7.7 s). The audio
      starts exactly at the section start, but the picture waits for the next
      keyframe, so the first frame shows frozen. Verified on 174, 233 and 451
      against the service transcript (451's first words are the 510.4 s cue for a
      510.0 s start).
    - *Audio from the preceding item* (23 over 1 s; 12 at 3–6 s). The picture
      covers the span, but the audio starts about 4–5 s early with the end of the
      previous item. 464 opens on the 501 s cue for a 506.9 s start; 399 on 536.9 s
      for 541.7 s.
  - **No song clip loses its ending.** Every audio stream is at least the span.
    Unlike the sermon MP3, no second cut is taken to a planned length.
  - The 12 re-encoded (vp9-source) clips are exact to 0.12 s, but their picture
    still starts 0.2–1.0 s after the sound.
  - Spoken song introductions at the start of all six sampled clips lie inside the
    section bounds: that is §4.3's spoken-framing class, not an extraction effect.
  - Before this census, the two over-long song videos from the §4.1a samples were
    unexplained notes; both are part of this class.
- [x] OoS item count and order against detected section count and order, per
  service. A song item with no section is the 944 "My Hope Is Built" class.
  **Done 2026-09-14, read-only, all 442 completed runs**
  (`storage/scratch/oosorder-20260914-{census,gaps,gap-lyrics,register}.*`).
  Listed order is each service's own `.osz`, re-parsed with `OpenLpServiceParser`
  (259 exact, 28 partly paired, 3 email/manual lists in id order). Pairing is the
  stored item ↔ section link; unlinked songs were then matched by song id, title or
  hint, and interior gaps were lyric-scored against the service transcript.
  - **No second view for 152 runs (34%).** They have no email, OpenLP or manual
    item: 43/43 in 2020, 43/55 in 2021. This census cannot cover them.
  - **Not defects:** 88 runs under 45 minutes list songs but have no song section.
    These are sermon-only recordings (2026-09-01 finding). Listed songs before the
    first anchor or after the last are where the recording starts late or ends
    early. The song/song inversions in 946, 961 and 1237 are lyric-verified real
    reorderings; 1288 lists "Your Word" twice.
  - **The 944 class is not a missing song.** 944 is a concatenated recording, and
    `dropSongsTheRecordingCannotContain` retypes every song in one. The rule covers
    11 song-titled `other` sections in 940, 942, 944, 973 and 1014 (5–134 s). The
    942 and 973 stubs are join fragments, but 944's §666 (134 s) was heard sung in
    §4.1a, so "concatenated means songless" is wrong at least once. **Ruled
    2026-09-14: a concatenated recording can contain a song** (below).
  - **Wrong song under the listed title:** 35 sections, of which 27 are already
    known (round-2 register, lyric flag or hold). The OoS view adds §1814 (1096,
    *published*: lyrics favour the listed #462 over the bound #907, on only 10 words)
    and §3658 (1292: announced #360, bound #370). Both **held 2026-09-14** through
    `service:hold-section-content` (operator approval). Before (09:23:23 UTC) the gate
    refused nothing; after, it refuses song video 229 and both sections await review
    (`storage/scratch/oosorder-20260914-gate-{before,after}.json`).
  - **Duplicate catalogue rows:** §529, §2402, §2783, §2875, §3073 and §3249 bind the
    listed hymn's twin song row (He Is Exalted 339/340, Here Is Love 358/359, All
    Creatures, Amazing Grace, This Earth Belongs To God). Right song, wrong row, so
    usage counts and song pages split.
  - **Listed song not anchored, so the service lists it twice (new class).** 14
    sections share the listed item's song id but are not linked to it. In 977 the
    livestream song items were projected with `song_id` null before transcript
    matching, and later syncs never anchored the OpenLP items. Corpus-wide: 20
    services, 28 duplicate pairs (21 with a null livestream song id), 6 on
    published sections.
  - **Interior listed songs with no section (38, plus 7 readings).** Lyric scoring
    of each gap found 3 sung there, all already lyric-flagged wrong bindings (§4735,
    §3363, §3627); 9 where another song was heard; 8 weak; 18 not heard. Whisper
    drops singing, so "not heard" is not proof of absence: 26 need audio.
  - **The validator orders by merged position (candidate).** `ValidationContext::for()`
    and `SectionStructureFlagRederiver` read `church_service_items.position` as the
    planned order. Merges rewrite it: it differs from export order in 73 services,
    for songs or readings in 50. The OoS inversion flags (214 cross-type, 9
    same-type) may be computed on a rewritten order. Not yet shown to change a flag.
  - **Unadjudicated:** reading inversions in 1075, 1254, 1286 and 1299; 963's "While
    Shepherds Watched" item links §863, but the lyrics place it at §859; 1234 §2897;
    37 services whose `.osz` upload and embedded names disagree.
- [x] Reference against passage id, against the OoS reading item and against the
  reference spoken in the sermon's first two minutes.
  **Done 2026-09-14, read-only, all 438 sermons**
  (`storage/scratch/scripture-20260914-{census.php,census.json,register.json,page-reading.txt,passages.json}`).
  - **Passage rows are sound.** 426 are linked; each passage's api.bible range and
    the verse numbers in its HTML cover exactly the verses its reference names. That
    the published reference equals the passage's display text proves nothing:
    `SermonIdentitySyncService` writes it so.
  - **Listed reading:** only 143 sermons have one (146 listed services list no Bible
    item). 134 agree. The 8 disagreements are the wrong references below, plus four
    lists that omit a text read or told inside the sermon (1047, 1174, 1264, 1267).
  - **Spoken:** the preacher names a reference in the first 120 s in only 183 of 438
    sermons, so this view is thin. Every disagreement is benign: cross-references,
    last week's chapter, a second reading, an anecdote, one ASR slip (1078) and one
    parser artefact (1157).
  - **Wrong published reference, one more:** sermon 899 (963) is published as
    Matthew 2:1-12, the carol reading before it; at 2,296 s the preacher gives his text
    as 2 Corinthians 9:15, which the title and heard reference match. §4.1a's
    candidate is confirmed. 881, 954 and 844/845/850 disagree on every view again.
  - **Multi-passage references lose all but the first passage (new, code).**
    `ScriptureOperatorService::enrichSermon` keys the passage on `normalize()`, which
    keeps the first passage only; linking then makes `SermonIdentitySyncService`
    rewrite `reference` to that passage. 1105 (Hosea 5:8-15 of 5:8–6:3), 1240, 1271
    and 1306. The validator upstream deliberately keeps every passage.
  - **A whole single-chapter letter cannot be a reference (new, code).**
    `SermonAnalysisValidator::validateBibleReference` rejects a bare book, and
    "2 John 1-13" or "Philemon 1-25" normalise to the bare name. 957 (2 John read
    whole) and 1090 (Philemon read whole) have no reference. 888, 961 and 1069 are
    topical and rightly have none.
  - **Seven references were never linked (new).** 908–910 and 912–915, all created
    09-02 16:32 to 09-03 06:42, have no enrichment log line; neighbours from the same
    window were linked only by a later pass on 09-07. 915's passage row existed since
    08-30, so it is not an API failure, and nothing reconciles. Bundle A would refuse
    them (`HistoricScripturePassageRequirements::keyFor` throws), but nothing earlier
    notices.
  - **An order flag drops the preached reading from the sermon media (new).** In
    1075, 1254, 1286 and 1299 the sermon's own reading carries
    `structure_oos_same_type_inversion`, and `selectBibleReading()` filters held
    readings before ranking, so the one reading matching the sermon never competes.
    1075 and 1299 join a different reading instead (Philippians 2, Psalm 46); 1254
    and 1286 get none. The inversions are real (listed first, read second, just
    before the sermon), which settles the four reading inversions the OoS census left
    open. Excluded by `structure_low_confidence` instead: 1198, 1235, 1319, 1322 (the
    readings score 0.66–0.72; conservative, not counted as defects).
  - **The sermon page names the wrong reading on 157 of 438 (new, consumer-side).**
    `SermonPageContextService` shows the run's first reading by section order under
    "Reading", beneath "Passage": 936 shows Passage 1 Peter 4:7-11, Reading Psalm
    100. In 131 of the 157 the plan holds a matching reading the page ignores.
  - **Non-matching reading joined to the sermon: not a defect (operator ruling
    2026-09-14, below).** When no reading matches the sermon, the selector joins the
    nearest one (930, 1120, 967, 1044, 1059, 1174, 1264, 1267, 1311); all published
    references are correct. **Except 1043, a defect:** its "reading" (§1528, 40 s,
    Colossians 3:1-2) is a verse quoted inside the prayer before the sermon, typed
    `bible_reading` by the structure detector.
  - **Prayer-verse census (2026-09-14, all 587 reading sections in 386 runs):** 1043 is
    the only case. By hand I read the 23 short, unannounced readings beside a prayer and
    the 26 with prayer language inside. The rest are genuine readings, mostly announced
    call-to-worship psalms; 1340, 1359 and 1274 are listed items in their order of
    service. Screen: `scripture-20260914-prayer-verse.{php,json}`.
  - **The structure model writes a boundary one minute late (new, code).** 1203 §2535
    holds only the last 20 s of Colossians 1:9-14: the prayer's "Amen" and the reading's
    announcement are at 25:17 (1,517 s), but the model returned 1,577 s (26:17) for both
    the prayer's end and the reading's start, so sermon 1122's media carry only the
    reading's tail. The error is exactly 60.0 s with the seconds digits unchanged, and the
    model's own recorded output carries it (snap moved it 0.01 s; no reading recheck ran).
    Cause: `ChurchServiceTranscript::toPromptText()` renders cue times as `m:ss`, while
    `OpenAiServiceStructureService` demands `start_time`/`end_time` in seconds, so the
    model converts every boundary; a one-minute slip lands on another real cue a second
    apart, and the "times must come from cues" rule cannot catch it. Present since the
    pipeline was built (371bb3a60, 2026-07-01), so the weekly path is exposed.
    Ruled out: prayer-end judgement (the chosen line is right), short cues (27 of 60
    sampled runs have them), the reading being a Pauline prayer (6 other such readings
    are correct), post-processing.
    **Corpus screen:** of 3,729 section starts, 35 have a strong boundary cue ("Amen", an
    announcement, "let's pray/sing") 60 ± 1.5 s earlier and none at the start itself; by
    hand:
    - confirmed: 1203 §2535 (above); 1183 §2391, whose sermon starts mid-sentence at 5:11
      after a 60.2 s unsectioned gap following the prayer's 4:11 "Amen", so sermon 1102's
      media lose the opening minute (not held); 1305 §3894, whose prayer starts
      mid-sentence at 62:21 while "Let's pray together" is at 61:22 inside the song
      section §3893 (already held; no song video);
    - probable: 962 §924, "Your Word" starting 58 s after the prayer with only a looping
      "Amen" between, so song video 122 may miss its opening (section already held);
      1141 §2137, the Daniel 6 reading starting at 1:00 with its first words in the gap;
    - not slips (30): a hymn's sung "Amen" before the next item, starts a few seconds late
      (1260, 1300, 1379), or a reader's own introduction left unsectioned (1250).
    The earlier reading-start screen (`scripture-20260914-reading-start.{php,json}`, 88
    readings, 42 read by hand) found only 1203 and 1141; 1359's one sentence in the song is
    a separate, minor case.
  - **Held 2026-09-14** through `service:hold-section-content` (operator approval):
    sermon sections 1732 (1075) and 4753 (1299), whose media join a different reading in
    place of the sermon's own. Before (10:00:28 UTC) the gate refused neither sermon;
    after (10:01:44 UTC) it refuses sermons 1005 and 1305 for `content_defect_hold`
    (`storage/scratch/scripture-20260914-gate-{before,after}.json`). 1254 and 1286 (no
    reading at all), the wrong reference 899 and the code-defect rows are not held: the
    reference goes to §4.5 editorial QA as in §4.1a, and the rest are re-run through the
    pipeline once fixed.
- [x] Title against summary (the 944 class) by keyword overlap; adjudicate the
  bottom decile.
  **Done 2026-09-14, read-only, all 438 sermons**
  (`storage/scratch/title-20260914-{census.php,census.json,register.json}`).
  - **No new wrong title.** Each title's content words were scored against its summary
    and points, and against two views that do not share the AI analysis: the structure
    detector's sermon section title and the sermon transcript. The known wrong titles
    calibrate it: 845, 850, 881 and 844 rank 4th, 5th, 7th and 15th of 438, all with no
    title word in the summary or the section title.
  - **Bottom decile (44), by hand:** the four known rows; three all-stop-word titles
    ("God is just") that sort first by construction; 37 paraphrases where summary and
    title name the same idea in different words ("Known, held and led by God" against
    "God knows… guides and holds").
  - **Independent view:** the summary comes from the same call as the title, so a pair
    wrong together would pass. 56 more sermons share no word with their section title;
    all are two phrasings of the same sermon that fit its reference ("Let your yes be
    yes" / "Truthfulness and Keeping Promises"). The transcript view cannot separate
    anything: title words occur in the transcript for 90% of sermons.
  - **78 published titles differ from the run's `ai_analysis.title`,** all rewordings
    of the same meaning; none is an adopted title.
  - **Null `title_provenance` on 34 is not the adopted-row class.** 844, 845 and 850
    (created 2026-05-28) are; 868 (07-18) agrees on every view; the other 30, created
    08-26 to 08-30, predate provenance tracking (`b6e8dec71`, 2026-08-31). The §4.3a rule
    that null-provenance adopted rows refuse publication must therefore tell adopted
    rows from pre-tracking ones, or it refuses 31 correct sermons.
- [x] SongVideo `recorded_date` and sermon `date` against the owning service and
  the source file's own date.
  **Done 2026-09-14, read-only, all 442 runs (438 sermons, 460 song videos, 481 source
  files)** (`storage/scratch/date-20260914-{census.php,census.json,register.json,occasion.php}`).
  - **Sermons and song videos agree with their service: 0 differ** on date, slot or
    service id. The run's `extracted_date`, manifest key and archive directory also
    equal the service date on all 441 runs with a service (955 has none).
  - **What "the file's own date" can be.** Container tags carry no date (ffmpeg remuxes,
    YouTube exports). The archive directory is where the importer took the date, so
    agreeing with it is circular. The independent views are a dated filename (154 with a
    year, 81 day and month, 2 year only), an mtime on the service day (237 of 481; 230
    carry later copy dates), the weekday, and a service row from the OpenLP or email
    stream that predates the import (290 runs). Only 7 runs rest on the directory
    alone: 930, 932 and 936 have pre-import service rows, 1374 and 1375 are named
    "Easter Sunday", 955 produced nothing, and 928 ("10-31.mkv" on a Sunday) is
    checked by weekday and slot only.
  - **No new mis-dated row.** The only source dates that contradict a service are the
    P8-Q7 rows: 1375's Easter master in a Saturday directory (the file has since moved
    to 2020-04-12, so its manifest path no longer resolves), and the Monday carol
    services 1034 and 1120, whose only tell here is the weekday. 1248's "Sunday 10th
    December 2022" is a typo (the 10th was a Saturday); 983, 1075 and 1100 are exports
    written one to seven days later.
  - **Slot agrees**, by filename time and by mtime minus duration, except the 2 pm main
    service of 2022-02-27 to 2022-04-24 (968, 1278–1285), stored as `morning`. In those
    weeks the OpenLP stream has an evening service and no morning one, so the 2 pm
    service is the main slot: not a defect. OBS filenames run one hour ahead of the
    mtime-derived start in 40 of 202 comparable recordings (a recorder clock left on
    summer time) and agree within 5 minutes in the rest; no recording is near
    midnight, so no date moves.
  - **Non-Sunday services (14):** Christmas Day (1033, 1119, 1195, 1293, 1344), Good
    Friday (1233) and the three P8-Q7 rows. The other five are occasions, and none has
    `occasion` set:
    - **Two funerals are pending publication as sermons.** 1051 (Friday 2025-10-31,
      sermon 985, song video 211) and 1098 (Wednesday 2025-04-09, sermon 1025, song
      videos 231 and 232). 1098's welcome gives thanks for the life of a church member,
      and a family eulogy follows. The dates are right; the question is whether a
      private funeral belongs in the public sermon archive. §4.1a called 1051 a genuine
      short sermon but ruled nothing on exposure. **Excluded (operator ruling
      2026-09-14, below).**
    - A holiday-club presentation evening, 1144 (Thursday 2024-08-08): no sermon, no
      song video.
    - **Saturday rehearsals of Sunday's sermon (new class).** 1089 (Saturday 2025-05-24,
      20:16, sermon 1016) and 1043 (Saturday 2025-11-29, 14:02, sermon 977) are
      sermon-only recordings of the talk preached the next morning, which has its own
      run: 1088 (sermon 1015, same reference and title; its transcript repeats the
      Saturday opening on the assisted dying bill) and 1042 (sermon 976, same reference;
      it repeats "a bit weird" and "Stephen saw him"). 1089 opens with a sound check.
      Each talk therefore has two Sermon rows, and each rehearsal was given a manufactured
      Saturday service. Two earlier findings are in these recordings: 1089's source
      dropout and 1043 §1528's prayer verse typed as a reading. **Excluded (operator
      ruling 2026-09-14, below).**
  - **Screen for more of the class:** every pair of sermons sharing a reference within
    7 days (17). Beside the 2 rehearsals and 2 P8-Q7 pairs (1296/1297, 969/1307), 9 are
    a series preached over consecutive weeks (about 5% of 6-word phrases shared) and 4
    are Christmas services (1114/1115/1116, 967/968; 11–18%) whose shared stretches are
    all the Bible reading. The rehearsals share 21% and 31%, in the sermon itself.
- [x] Sum of sections against source duration; list every unsectioned span over
  60 s with its transcript density, corpus-wide (the dossiers compute this for the
  sampled runs only).
  **Done 2026-09-14, read-only, all 442 runs**
  (`storage/scratch/sections-20260914-{census,screen}.{php,json}`, `sections-20260914-register.json`;
  fresh audio `sections-20260914-sung-probe.sh` with its candidate and probe TSVs).
  - **Coverage is high and bounded.** Sections cover a median 99% of the source (10th
    percentile 94%); no section ends past its source; only 955 (silent) has none. 106
    unsectioned spans exceed 60 s: 52 lead-ins, 42 interior, 12 tails. Each was scored
    for transcript words and for loudness (share of RMS samples above the run's own
    threshold; the mean is useless because digital silence logs −999 dB). Every quiet
    or wordless span over 60 s with sound in it (29 interior or tail, 28 lead-ins) was
    re-transcribed from 30 s of its source with local Whisper.
  - **The closing prayer is left out of 7 sermons (new, code).** In 1100, 1047, 1276,
    1300, 1254, 1052 and 1057 (sermons 1027, 981, 1193, 1299, 1172, 986, 990) a closing
    prayer of 61–185 s has no section, and the published sermon stops before it. The
    operator's rule is that a sermon runs to the next song, closing prayer included, but
    `SermonExtractionPlanResolver::resolveSermonEnd()` absorbs only following *sections*
    and stops at the first song, so unsectioned speech before that song is dropped at
    any length. Sermon sections 1562, 1606, 1634, 1849, 3173, 3463 and 4648 were **held
    2026-09-14** through `service:hold-section-content` (operator approval). Before
    (14:53:48 UTC) the gate refused none of the seven sermons; after (14:53:51 UTC) it
    refuses all seven for `content_defect_hold`. All remain quarantined
    (`storage/scratch/sections-20260914-gate-{before,after}.json`). A screen of every sermon's plan
    end found 59 tails of 5 s or more; the other 52 are hymn announcements, "Amen",
    musicians setting up or silence.
  - **Songs the transcript cannot see (new, detection).** Whisper leaves singing as
    unobservable windows or "Thank you"/"Amen" loops, and the structure model works from
    the transcript alone, so it omits a song or times it across the transcribed lines only.
    1241's recorded model output places "And Can It Be" at 4,498 s; the singing is heard
    from 4,388 s. Fresh audio sorted the loud, wordless spans into:
    - *song section cut short (8):* 965 §889 Behold Our God (78 s of a 231 s song), 1109
      §1909, 1196 §2456 O Come All You Faithful (starts 130 s late), 1241 §3003, 1269 §3368
      Your Word (59 of 246 s), 1341 §4310 Amazing Grace (16 of 231 s), 1379 §4629; and 1287,
      where the song-loop census showed the songs shifted one slot rather than cut:
      §3596 Praise the Lord You Heavens ends 52 s in, §3597 "There Is A Higher Throne"
      holds that hymn's later verses, and Higher Throne is the unsectioned 299–463 s. None
      has a song video, so no clip is cut; item timing and song usage are wrong, and a
      later video would be.
    - *song with no section (10):* carols in 963 (O Little Town of Bethlehem, O Come All
      You Faithful, As With Gladness, all listed), 1034 (a P8-Q7 row) and 1195 (opening
      carol); 1244's listed In Christ Alone (the OoS census scored it "not heard");
      closing songs in 1001 (His Mercy Is More), 1135 (listed Who Is There Like You), 1231
      and 1311.
    - *spoken:* a Bible reading (1138), notices (1245), a prayer's start (1324), a welcome
      (1352), and welcome or notices before the first section in six lead-ins; the other
      lead-ins are pre-service music.
    - *unclear from 30 s:* 1129, 1233, 1240, 1266, 1276 and 1316 need a listen.
  - **Limits.** Only spans of 60 s or more with sound in them were probed. A song cut by
    less, or sung inside another section's span, is not covered; the §4.1b lyric-scorer
    row (sung material typed non-song) remains the instrument for the second.
  - **Two earlier candidates resolved.** 985 §1121: the 181 s after it sing "Come People
    Of The Risen King" while its own transcript announces hymn 968, which supports its
    existing `song_identity_contradicted_by_transcript` hold. 1174 158–240 s is not the
    first verse of §2330 (that section opens with its own announcement); it is
    unadjudicated.
- [ ] Complete sermon audio/video content alignment corpus-wide, including source
  spans and joins, under the handoff checks above.
  **Partial evidence, 2026-09-14:** duration and the tail-loss estimate for all 436 with an MP3
  (above). RMS alignment at five points on 1266, 1128 and 1226 found a constant
  offset equal to each video's picture delay (3.9, 4.5, 4.6 s), so nothing is lost
  or shifted at the join and picture stays in sync after the opening. The picture
  starts after the audio by under 0.5 s in 186 videos, 0.5–1 s in 55, 1–3 s in
  185, 3–6 s in 11, and 8.3 s in 1206 (a camera-disconnected card).
  **Scope correction:** alignment on those three runs does not establish corpus-wide
  content identity or sync. Equal-length shifted cuts, wrong files and untested joins
  remain open even where duration agrees; this task is therefore not complete.
- [x] Adjudicate every automatic video-quality rejection (added 2026-09-14; the
  public page hides a rejected video). **Done, read-only, all 48 rows**
  (`storage/scratch/vq-20260914-register.json`, contact sheets under
  `vq-20260914-frames/`). Of the 47 in the active scope:
  - **19 correct**: 12 are black for the whole recording; 7 are cards for the
    whole recording (OBS end and start cards, "EOS Webcam Utility", "Sorry,
    there's a problem with the camera").
  - **1 partial**: 1225 shows about 8 minutes of preaching, then the camera-problem
    card, then a second camera.
  - **27 wrong**, normal preaching hidden from the public:
    - 24 `frozen_frames` on static cameras. The detector shrinks frames 1.5 s apart
      to 16×16 luminance and calls a pair frozen at ≤ 1% difference. Replayed on
      978, 1070 and 1241 it scored 0.004–0.008 at 1.5 s but 0.013–0.066 at 60–300 s;
      black 984 scored exactly 0.
    - 3 `mostly_black` on dim lighting (903, 969/1307): brightness 0.07 against a
      0.08 threshold.
  - ffmpeg `freezedetect` over a 4-minute window separates the two populations
    completely: every correct rejection is still frozen at the window's end, and
    no wrong one freezes at all.
  - 13 verdicts (978–1038) were re-assessed on 2026-09-08 by
    `sermons:assess-video-quality`, which writes no processing log. Their runs
    still say `unassessed / missing_video_file`, and the evidence exists only in
    `laravel.log`.
- [x] Thumbnail frame timestamp inside the sermon span, and the frame not black or
  a slide. **Closed as not produced (2026-09-14):** thumbnails are deliberately
  generated only after editorial QA, for the exact release membership (operator
  ruling). §4.5 carries generation and this check.
- [x] Apply the repetition screen to song sections and census looping song
  transcripts (the §1216 class).
  **Done 2026-09-14, read-only, all 1,177 song sections of the 442 runs**
  (`storage/scratch/songloop-20260914-{census.php,census.json,candidates.tsv,probe.tsv,score.php,score.json,register.json}`).
  - **The class is large and invisible to the song checks.** Re-running the P8-Q14 screen on
    each run's current transcript reproduced the stored blocks exactly (442 of 442). 229
    song sections (19%) overlap a loop: 65 are at least half loop, 88 a fifth to a half.
    All 358 blocks are repeated-phrase loops; §1216 (1001, "who never is and the risen lamb"
    ×64, 42% of the section) is caught. Only 6 blocks repeat a phrase within twice its
    count in the bound lyrics, which is genuine chorus repetition; the rest repeat it up to
    465 times against one lyric line.
  - **Nothing downstream knows.** `SongPublicationBoundaryEvidenceService` discounts transcript
    gaps inside unobservable windows but never reads the repetition blocks, so looped cues
    count as timed evidence at a clip edge. The `song_identity_contradicted_by_transcript`
    flag has no pipeline writer (only `storage/scratch/p8q10-hold.php` set it), and the
    §4.1a lyric-identity census scored just 16 of the 229. Identity rests on title hints for
    191, OCR for 9 and nothing recorded for 29, so a loop does not change a match, but it
    leaves the match unverified while it reads as `confirmed`.
  - **Fresh audio on the 65 half-loop sections** (30 s from inside the largest loop, scored
    against every song's lyrics):
    - 39 are the bound song, including all 23 checkable song videos among them;
    - 13 yield no words (Whisper again returns "Thank you" or nothing; videos 259, 400,
      455 and 529 remain unverified);
    - **1287 §3597 is the wrong song** (18 lyric bigrams for the listed, unlinked "Praise the
      Lord You Heavens" #797, 0 for the bound #934; no video): the section census's 1287
      entry is corrected above. **Held 2026-09-14** through `service:hold-section-content`
      (operator approval). With no song video the release gate has nothing to refuse, so the
      section state is the record: before, its flags were `structure_low_confidence` only;
      after (15:08:25 UTC) they include `content_defect_hold` with the reason in
      `content_holds` (`storage/scratch/songloop-20260914-gate-after.json`). 939 §631 is already held; 1194 §2439 and 1223 §2755 are the
      right song with no catalogue link;
    - **a loop can manufacture sung text over speech**, so a song section swallows the
      neighbouring prayer or talk: 1268 §4731 loops "we honour and adore you" ×63 over the
      prayer that §4732 then continues; 967 §1082 and 1348 §4390 likewise, all three
      unflagged and without videos. 1035 §4816, 1353 §4449 and 1361 §4525 are the same
      shape but already flagged for review; 1098 §1827 is the excluded funeral; 1264 §3316
      and 1279 §3496 are unintelligible singing.
  - **Loops also cross section edges:** 8 run 30 s or more past a song section into the item
    before it, mostly prayers, which neither screen holds.

#### Tail inspections (by hand, corpus-wide)

**Done 2026-09-14, read-only, all 442 runs** (4,170 sections, 438 sermons, 464 song
videos). Every value is in `storage/scratch/tails-20260914-census.json`; tails were cut
at 2% each side (minimum 5) and adjudicated in `tails-20260914-register.json`.

- [x] Shortest and longest 2% of sermons, children's talks and song clips.
  - *Sermons* (median 1,790 s). Short tail: 954 is held for its reference, but its
    source also opens on 534 s of digital silence and the sermon starts mid-sentence
    (truncated at source); 966 is held as cut off; 1003 publishes only the second half
    of a talk interrupted by a hymn (the first 999 s is typed `other`), which
    `sermon_parts_not_extracted` already flags and the gate refuses; 985 and 1025 are
    the excluded funerals; 1117, 903, 899 and 969 are short Christmas talks. The long
    tail (2,449–2,676 s) holds single talks only.
  - *Children's talks* (179 sections, median 355 s; there are no historic children's-talk
    Sermon rows): 1182 §2386 is a 14 s dismissal typed as a talk; 947's talk is split into
    §693 (97 s) and §694 typed `other` (the "Heroes of Faith" series that 1309 §3924 types
    correctly). The long tail is genuine family talks.
  - *Song clips* (median 189 s): **74** (20 s) is an announcement with no song in the
    lockdown recording; **83** (23.5 s) is the doxology cut as a second "All People That On
    Earth Do Dwell" clip (duplicate-item class). Both sit above the 15 s micro floor and are
    held only by generic review; 100 (57 s) is a genuine short chorus. Every clip over six
    minutes carries `structure_macro_section` and is refused.
- [x] Largest gaps between consecutive sections; lowest transcript word rate per
  section; highest silence fraction per section.
  - *Gaps.* The top 2% (75 of 3,729) run down to 32 s. The 42 over 60 s were all
    adjudicated by the section coverage census. The 33 of 32–60 s, read by hand
    (`tails-20260914-midgaps.json`), are song introductions and Amen/Thank-you filler, and
    eight hold sung lyrics just outside a song section, which led to the song-edge census
    below.
  - *Overlaps.* Four single-span sermon plans end 14–20 s into the following song (942,
    1135, 1304 held for other reasons; **1224 unheld**). The extra seconds are the hymn
    announcement and introduction, with no singing: put to the operator below.
  - *Word rate.* The lowest sermon, 1112 §3739 (65 wpm against 118), is already in the
    §4.1a cadence-loss census. The lowest readings, welcomes and `other` sections are
    Thank-you loops or unobservable windows; 930 §534 is 12 minutes of pre-service silence
    then a spoken welcome transcribed as loops. Prayers 1102 §1854 and 978 §1033 were not
    probed.
  - *Silence.* The fraction is measured against each run's own RMS threshold, which varies
    by era, so a high value is not quiet output: the three readings that open sermon videos
    1248, 1252 and 1275 (0.85–0.91) measure −17 to −22 LUFS, and song clips 449 and 454
    (0.85–0.89) measure −14 and −16 LUFS. No published output is silent.
  - **Song-edge census** (added, `songedge-20260914-*`). For all 1,079 song sections with a
    bound song, cues within 90 s of each edge were scored against the song's lyrics: 248
    edges on 234 sections, 105 with a clip. After removing announcements and title words, 63
    edges were read by hand and every plausible one probed with fresh audio from the source.
    It is a floor: clip 192 was missed because Whisper misheard "heaven-born".
    - **Seven unheld clips lose their own verses:** 114 (final verse, unsectioned after),
      192 (final verse, unsectioned after), 543 (first verse, unsectioned before), 240 (final
      verse and chorus inside the following prayer), 539 (opening verses inside the
      preceding reading), 360 and 207 (first verse inside the preceding song, whose boundary
      is 30–60 s late). **206** therefore ends with a minute of the next song.
    - **410 is the wrong song:** the OoS lists #699 twice, and §4275's own hint "Shine Your
      Light" was matched `confirmed` to the second copy; the audio is a song not in the
      catalogue.
    - About 35 of the 63 are spoken quotations (Psalm readings, prayers, a leader reading
      the verse before singing it: 253, whose sermon 1049 ends on that framing like 1224).
    - Before any hold, the gate refused none of the nine: all quarantined, no flags, no
      manual review (`songedge-20260914-gate-before.json`). **Held 2026-09-14 (operator
      approval)** through `service:hold-section-content`: sections 909, 1459, 1571, 1896,
      2458, 3640 and 4813 (verses lost), 1570 (carries the next song) and 4275 (wrong song).
      After (16:25 UTC) every section carries `content_defect_hold` and the gate refuses all
      nine clips (`songedge-20260914-gate-after.json`). Clear only after re-detection and
      re-extraction through the pipeline.
- [x] Largest audio-to-video duration delta; smallest bytes-per-second; any
  resolution, frame rate or codec that differs from the modal value.
  - *Sermon videos* equal their run's source `codec_fingerprint` in all 438: the 27 at 720p
    or 480p, the 18 at 29.97 or 25 fps, the 44.1 kHz audio and the 14 mono files (runs
    1311–1324, mono at source) are all source properties. Four files report an unknown
    profile and pixel format (1206, 1290, 1296, song clip 174); all decode cleanly from an
    I-frame, and ffprobe's header window simply ends before their late picture. The lowest
    bitrates (1233, 1206) are cards already in the vq register; the highest (875) follows its
    source.
  - *MP3s*: 436 at 48 kbps mono. **Sermons 844 and 845 have no MP3** and no recorded reason
    (both rows predate the import); open.
  - **Song clip audio is re-encoded and 245 of 464 are upsampled to 96 kHz.**
    `SongPublicationHandler` (line 204) sends every clip through
    `AudioEnhancementService::enhanceVideo()`, which encodes `-c:a aac -b:a 128k` with no
    `-ar`; when the chain includes `loudnorm` (only when loudness is outside tolerance) the
    filter outputs 192 kHz and AAC falls back to 96 kHz, which is why rates mix within a run
    (945). Playback is correct (clip 79 decodes to its container length and transcribes at
    normal pace), but the clips lose up to half their source bitrate and spend it above
    20 kHz.
  - The largest audio-to-video deltas are the duration censuses' tail-loss and
    picture-delay cases; nothing new.
- [x] Earliest section start and latest section end relative to source duration.
  No section ends past its source. The latest first starts (1195, 1019, 1051, 1035, 1196,
  1367, 1199, 1098) and earliest last ends (962, 1311, 1135, 1001, 1341, 1231, 985) are all
  leads and tails already adjudicated by the section coverage census; 955 has no sections.

#### Changes of examiner modality

- [ ] Watch and listen to five complete services' outputs end to end on the
  rendered local pages (sermon page with video and MP3, each song page), drawn
  across eras and including at least one held-then-repaired run. Record A/V sync,
  level, thumbnail, slug, series, title and page layout. This is a human check and
  is not delegable to frames. These may be the blind-review services after their
  source inventories have been saved and compared; consuming generated outputs
  does not replace first reviewing the original source.
- [x] Exercise the public route rendering of every quarantined sermon and song
  video in-process, plus sitemap, podcast feed and structured data. Check 404s,
  missing thumbnails, duplicate slugs, broken song links, empty summaries rendered
  as content and exceptions; record the scoped exceptions below.
  **Done 2026-09-14, read-only** (`storage/scratch/render-20260914-{pass,feeds}.php`,
  `render-20260914-register.json`). Not Dusk, which swaps `.env` and repoints the database:
  one rolled-back transaction released all 442 quarantined sermons and 464 song videos the
  way release does (published, same paths, a URL-capable disk over the quarantine bytes),
  and 5,281 requests went through the HTTP kernel in-process with caches in memory and
  rate limits passed through. Song pages rendered as a verified member; the service
  archive with its start date at the earliest historic service. Rollback was verified.
  - **Clean.** No exception or 5xx anywhere. All 442 sermon pages, 225 song pages, 441
    service pages, every listing and archive year, and all 2,150 sitemap URLs return 200.
    Canonical links match, JSON-LD parses, and no page renders an empty title or
    description, placeholder text or an empty summary. There are no duplicate slugs, and
    every slug route redirects to its dated URL. Every historic podcast enclosure matches
    its file's length, with no duplicate GUIDs. The only 404s are assets that are meant to
    be hidden: missing thumbnails (the accepted deferral), the 48 rejected videos, and
    audio for 844, 845 and 857. The only broken internal links were artefacts of the
    render disk and one static PDF that nginx serves.
  - **Latent: the public sermon query never selects `asset_disk`.** Listings, browse,
    service lists and the feed resolve media through `SERMON_STORAGE_DISK`, while the
    sermon page uses the row's own disk. Release writes that same disk, so they agree
    today; any divergence gives wrong enclosure URLs and zero-length enclosures, which
    podcast clients reject.
  - **Sermon 857** (quarantined, non-historic) records an MP3 that does not exist on the
    quarantine disk.
  - **Service links with the archive disabled:** `publicUrlFor()` treats a null
    `public_from` as no bound, but the archive treats null as disabled, so members-only
    song pages link to service pages that 404.
  - Services 1093, 1029 and 551 render with no sermon: the no-sermon class, correct.
    Excluded runs (1043, 1089, 1051, 1098) still render service pages under simulation,
    which the pending exclusion path must prevent.
  - *For release planning:* the historic sermons fill 90 of 100 morning and 99 of 100
    evening feed items, so release publishes about 190 episodes to subscribers at once.
  - *Harness trap:* `GET /sitemap.xml` regenerates the real file when it is missing, so the
    simulated state leaked into `public/sitemap.xml` (gitignored); it was deleted.

- [ ] Verify playback through an actual server and browser on a representative
  set spanning source codecs, frame rates, mono/stereo, concatenation joins and
  repaired artifacts. Use isolated fixture data for Dusk behavioural tests; retain
  Playwright only for pixel-level checks. Do not repoint a browser-test environment
  at the historic working database. Keep human whole-output listening above.
- [ ] Through real media URLs, test starting playback, seeking near the middle,
  across joins and near the end, and resuming playback. Check range requests and
  partial responses, correct MIME and length headers, and CORS where cross-origin
  delivery requires it. Exercise the applicable member/public access contexts and
  confirm quarantine remains inaccessible through unauthorised routes.
- [ ] Verify replacement at an existing URL returns the current artifact through
  storage/server/cache delivery, including a browser that has loaded the old one.
  Compare returned bytes where unchanged and decoded content otherwise. Distinguish
  a successful local origin check from an untested CDN/storage environment.
- [ ] Carry these journeys into the separately authorised release's observation
  window on the actual destination, with exact asset membership checks before
  release and representative delivery checks after it. Local simulations do not
  certify remote transport; use the existing rollback process if delivery fails.

#### Rulings and stopping rule

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
    pipeline.
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
- [ ] Sample the held population on the other dimensions: draw 15 held runs and
  run the full checklist plus the matrix rows on them, so that clearing a hold for
  its recorded reason does not release an unexamined row.

### 4.1c BC-06 and BC-07 rulings — 2026-09-18

**BC-06 — closed for the deterministic class (`ecfa94d31`).** A song section
takes its identity *through its order-of-service item*; `service_sections` has no
`song_id` column. §2722's item (11909) held the title "All of Us in Sin Were
Dying" with `song_id` NULL while catalogue song 64 carries exactly that title, so
the section read `song_match_type = confirmed` with nothing behind it. Census:
**66 confirmed song sections linked to an unbound item, plus 12 with no item at
all; every one `not_applicable`**, so containment was never at risk.

Operator ruling: bind the deterministic matches, adjudicate the rest.
`service-tracking:bind-confirmed-song-identities` walks only the items a confirmed
section depends on and binds only `exact`, `praise_number`, `stripped_number`,
`loose_title` and `alternate_title`. Deliberately **not**
`service-tracking:link-songs`: its dry run over all 2,193 song items reports
**389 links updated and 3 cleared**, far wider than this authorises.

Applied: **55 bound, 66 → 11**. §2722 now resolves to song 64 and keeps its
content hold (that hold is about the song's bounds, not its identity). The
residue, held for adjudication and *not* bound, because a resemblance is not an
identity and the corpus already holds sections `confirmed` against the wrong song:

- **Inferred (7):** items 7092, 11564, 11912 (`first_line`); 11380, 11525, 11567,
  11873 (`fuzzy`, 0.956–0.98).
- **No catalogue match (4):** 11520 "Psalm 11 hymn", and — more surprisingly —
  11720 "How Great Thou Art", 11753 "The Servant King", 11920 "Jesus Is Lord".
  Those three look like **catalogue gaps**, not title problems, so they need a
  catalogue decision rather than a match.

**BC-07 — the census overturns the premise of the chosen option; the ruling needs
re-asking.** The operator chose "rule that a catechism or named-figure
presentation in the children's-talk slot IS a `childrens_talk`, retype §4684,
census the rest". The census then showed that ruling cannot be applied by title
**or** by item type:

- **191 `other` sections over 120 s**; 107 runs have a long `other` and no
  `childrens_talk`. All unpublished.
- A title screen puts 162 in a "presentation" bucket, but that bucket mixes
  genuine children's teaching ("Joseph's reunion", "Tyndale and the Bible in
  English", "Thomas Bilney") with **adult mission presentations** ("Release
  International", "Operation Forgiveness presentation", "Persecution of
  Christians in Nigeria"), an **ordinance** ("Baptism of Roy"), a **mis-typed
  reading** (§38 "Bible Reading") and **adult exposition** ("Christ in 1 and 2
  Samuel", "Nehemiah: pointing to Jesus"). Retyping the bucket would publish
  those as children's talks — and children's talks are stored as sermons.
- The OoS item is no better a signal: of the 61 that have one, **41 are
  `presentations` (a `.pptx`), 9 `images`, 6 `media`, 4 `custom`, 1 `songs`**.
  `other` on a long section means *"a projected item was showing"*, which says
  nothing about whether the content is for children. §2721 only resolved because
  its item was a `custom` entry literally titled "Heidelberg Catechism: the fall".

**§4684 read by hand.** Its transcript is unmistakably a children's talk —
"on a Sunday morning, just for a little while, we're looking at the heroes of
faith", a joke that Augustine "didn't have anything to do with hippopotamuses",
"he was a very naughty boy… he belonged to a gang" — and it sits at order 4,
between the opening prayer and a song, well before the sermon at 1486: the
children's-talk slot. **But the detector was right under its own rule.** The
prompt requires a *structural* cue — children addressed, called forward or
dismissed, or parents addressed about them — and §4684 has none; no dismissal
follows it either. Register alone is what would sweep in the 162-strong bucket.

So the open question is narrower than BC-07 was framed: **should a slide-backed
series talk pitched at children, with no explicit address to or dismissal of
children, be a `childrens_talk`?** Nothing was retyped, and §4684 stays `other`
pending that ruling. Two consequences to weigh: keeping the rule means the
detector is already correct and BC-07 closes with no retyping; relaxing it needs
a cue narrow enough to exclude adult mission presentations, and the repair is then
a prompt change plus a re-detection of run 1358, not a hand edit.

### 4.2 Close the transcript-loop blind spot

- [x] Implement the BC-08 context-drift prevention: both local Whisper requests
  send `max_context=0` and `carry_initial_prompt=true`, recorded in the processing
  fingerprint (`7bed913ac`). Request tests prove propagation, not transcription
  accuracy; the seven-run experiment in §4.1b is separate measured evidence.
- [ ] After readiness verification, and **on workers running `7d6bbde95` or
  later**, re-transcribe 1343, 1258 and 980 through
  the pipeline in the canary/bounded batches. **1314 is done (2026-09-17, §4.0a):**
  868 cues against 3712, its sermon text's period-per-word ratio 0.89 → 0.07, and
  its structure and media re-cut from the repaired transcript. Check full-service and saved sermon
  text against source speech, including missing/changed words, not punctuation alone.
  Re-run dependent structure, song resolution and analysis before extraction.
- [ ] Size wider recovery from raw service-transcript one-word-segment and loop-word
  rates, including partial drift in 1221/1336. Do not infer that all old fingerprints
  require a rerun, or that a normal section-text ratio proves clean transcription.
  Compare fresh reserved healthy and defective cases before broadening the batch.
- [ ] Validate the remaining short-loop candidates against their audio; distinguish
  genuine rhetoric or singing from corrupt transcription.
- [ ] Add regression coverage for four repetitions, fewer than 40 repeated words,
  and number-varying loops.
- [ ] Verify recovered full-service evidence and saved sermon text independently.
  Agreement between two copies of the same corrupt text is not proof.
- [ ] Apply §4.1b's blind interior-window review to fluent transcription errors and
  omitted or meaning-changing speech, as well as loops. Re-transcription is a
  candidate comparison; validate against source audio and record unassessable
  speech. Check dependent analysis claims again after transcript recovery.
- [ ] Detect sparse 30-second-cadence loss (§4.1a): consecutive short cues exactly
  30 s apart inside dense speech. Regression cases §3739 (1112) and §3490 (1278);
  negative cases are "Amen"/"Thank you" cadences over music and 1089's silent source.
- [ ] Add 1347's reading loop ("the Lord" ×16, 32 words) to the short-loop candidates.
- [x] Feed recorded repetition blocks into song publication review. Implemented
  and banked through v5 on 2026-09-16 (§4.3a); looped gaps no longer invent
  boundary evidence. This is a publication risk, not independent identity proof.
- [ ] Complete the identity consequence: unusable looped text cannot provide lyric
  confirmation. Add independent performance evidence or demote the match; evaluate
  both repeated catalogue phrases and non-matching phrases against audio, under
  §4.3a's evidence correction.

### 4.3 Refresh song policy for existing outputs

- [ ] Reassess all already-generated songs within each owning run's recorded
  staging context and bind the verdict to current inputs and policy.
- [ ] Reconcile the 27 boundary-policy candidates in addition to confirmed mixed
  section 3869.
- [ ] Add a boundary check for continuous spoken material that does not depend
  solely on finding a wordless gap, using section 988 as the regression case.
  **Real-source evidence 2026-09-17:** re-detection pulled continuous spoken tails
  of 101 s (948 §709), 135 s (1274 §3437) and 225 s (1303 §3861, which split into
  song + reading + prayer) off their songs, and split two macro songs outright
  (1060, 1009). So the trim does reach long tails in the cases measured; §988,
  §1457 and §2897 themselves are still not re-run, and §2897's speech over organ
  remains the known gap.
- [x] Write current-policy objections into `needs_manual_review` (§3.1 item 3), and
  make sure no recompute or backfill clears a hold because an older banked verdict
  said `release_eligible`. The gate itself already reads only the stored column.
  Implemented by `SongBoundaryEvidenceBackfill`; named passes applied 15–16
  September. Coverage and independent adjudication remain separate above.
- [x] Add a stale-verdict selection mode to `service:backfill-song-boundary-evidence`
  so existing evidence is re-assessed against current policy and holds are raised
  through its tested write path (§3.2). Measured: the 3-second floor accounts for
  none of the 27 candidates, so it is not a substitute.
  Implemented for version, bounds and input fingerprints. The 16 September
  reconciliation dry run selects nothing; its default scope excludes superseded
  runs and `not_applicable` sections, so this is not all-row acceptance.
- [x] Implement the general speech-edge trim at structure detection, with synthetic
  RMS unit coverage and corpus replay. This does not close the original §988,
  §1457 and §2897 real-source regression requirement.
  **Built test-first 2026-09-17 as `SongSpeechEdges`**, run in `DetectServiceStructure` and
  `structure:evaluate` straight after the sustained-sound widening. **It is a trim, not a hold,
  because the class is large.** Replayed over 1,177 corpus songs, a 25 s spoken lead-in or 20 s
  spoken tail appears on 396 of them (349 starts, 95 ends; 159 with a song video). That matches
  the blind set's 7 of 23, and holding each one would swamp the queue. A seeded sample of 20
  corpus lead-ins (`songspeech-20260917-adjudication-lead.json`) was **20 of 20 spoken
  announcements**, each followed by continuous singing from the measured onset.
  - *Instrument.* `SustainedSound` gained a 10 s window (`EDGE_WINDOW_BINS`). The 30 s window
    blurs an edge by up to 15 s and invented a 37 s lead-in on clean 1221 §2729. The onset lands
    1–9 s late, so starts keep 10 s of margin and ends 5 s. With 5 s at the start, 4682 lost 4 s
    of its first line. Against the blind onsets, every trimmed start now keeps 1–26 s of speech
    and cuts no singing, and none of the 12 clean reference songs is touched.
  - *Where it acts.* Only on runs whose other songs read as sung (median long-window share ≥ 0.6),
    only on sections that are at least half sustained, and never on a single-song run or a
    recording whose songs were cut out. 1267, 1235 and 1309 have published songs that read as
    speech against their run's threshold.
  - *Checked side effects.* No new `SongLyricsOutsideSection` risk on any of the 396 (6 before, 6
    after). 135 trims follow a sermon directly, and that sermon's media now runs on through the
    announcement to the trimmed song start, as the next-song rule intends.
  - *Not reached.* §988 (93 s lead-in, 0.48 sustained) and §1475 (104 s lead-in, 41 s tail, 0.42)
    fall under the half-sustained floor. These sections swallowed a neighbouring item, and both
    stay content-held. Lowering the floor to 0.4 would add 17 trims of up to 240 s; three of them
    are unheld (1011 §1284 and 1280 §3508, both published; 1246 §3079) and are listening
    candidates. §2897's spoken benediction is over organ, so it reads as sustained and its tail is
    missed; the replay instead trims its start by 19 s (unverified).
  - *Unsung song rule not built.* Long-window share below 0.3 marks 21 songs in runs where the
    measure works. Fresh audio heard 14 of them sung, and all 6 unsung ones (long sections
    swallowing talks, prayers and readings) plus 1314 §3994 are already held. The rule would
    add false holds and no containment.
  - **Due:** re-detection through the pipeline. Existing sections change only when a run is
    re-run; the replay lists the 396 (`songspeech-20260917-replay.json`).
- [ ] Independently verify repaired starts and tails, including clean negatives,
  no lost singing, and the adjoining sermon ending (135 replay trims follow sermons).
  Record acceptable remaining speech explicitly; the replay retained 1–26 seconds.
- [ ] Keep §988/§1475 (below the half-sustained floor), §2897 (speech over organ;
  proposed start trim unverified), and §1457's actual repaired output as explicit
  real-source checks. Adjudicate §1284/§3508/§3079 before any floor change.
  Synthetic unit patterns and an unchanged lyric-risk count do not close these cases.
- [ ] Preserve the rejected broad unsung-song rule as a measured decision: 14 of
  21 candidates were sung. Separately resolve BC-01's spoken hymn quotation and
  restore run 1314's complete sermon through the pipeline; low sustained sound
  alone is not a reliable speech-versus-song classifier.
- [x] Give deterministic catalogue titles priority over lyrics containment in
  `title_hint_fuzzy` resolution. Built 16 September, with measured wrong-binding
  and preserved alternate-title/first-line regressions (§4.3a).
- [ ] Re-resolve the 72 deterministic cases through the pipeline, adjudicate the
  three unchanged-fallback suggestions separately, and correct livestream-sourced
  order-of-service items after identity settles. Recheck loop evidence against the
  resulting binding. §3 records the seven currently unheld rows; §4156 is the
  remaining published section in that group, with its clip still quarantined.
- [ ] Settle the 31 pending-approval sections with the same hint disagreement before
  anyone approves them.
- [ ] Add a lyric-coverage check (calibrated in §4.1a) before a song match is recorded
  as `confirmed`.
- [ ] Redefine `confirmed` as two agreeing evidence sources whose lineage is
  demonstrably independent, with at least one supporting the actual performed
  song. Candidate signals are exact/alternate title hints, usable sung lyric
  coverage and independently sourced OoS items, but do not count them by field
  name alone: the structure model reads the OoS and transcript, so its hint may
  inherit either answer. Slides and the OoS can both retain an unperformed plan.
  One underlying source or unassessable performance yields `inferred` and a review
  flag. A looping transcript cannot supply lyric confirmation; alternative usable
  performance evidence must be recorded before confirmation. Apply §4.1b's lineage
  rules and test the intentionally wrong-OoS case.

### 4.3a Put a detector in the pipeline for every class found

Added 2026-09-14. The programme's aim is that future services process correctly,
so each class found needs a tested prevention/detection response on the weekly
path, or an explicit recorded decision not to detect it. Flagging for review can
contain a defect before automated repair exists, but promotion also requires the
false-positive and review-burden evaluation below. Most classes already have a
working prototype under `storage/scratch/residue-2026091*`; promote them into tested application code
rather than leaving them as one-off scripts. Every class found by §4.1a, §4.1b or
§4.5 gets a row here before its holds are cleared.

The response column includes the 17 September implementation/evidence review;
application counts retain their individual measurement dates. The final
column preserves specifications and execution history, including superseded
intermediate results. In particular, its song-loop “defect” labels describe a
catalogue-lyrics comparison, not independent listening; the evidence correction
immediately below the table governs their interpretation.

| Class | Found by | Current response — reviewed 2026-09-17 | Prototype | Specification and dated execution evidence |
|---|---|---|---|---|
| Full pipeline re-run cuts a content-held sermon from the RMS baseline without error (1314 §3992, 17:04) | 17 September canary | Fixed `283a6cd90`: extraction parks held sermons; 1314 repaired with `--held-section` | `canary-20260917-dispatch-1314-recut.txt` | Refuse or park extraction when a sermon section exists but is held and no authority names it; failing test first |
| Content-held sermon cannot reach its intended repair; re-extraction dry run conceals fallback refusal (1209 §2572) | 17 September canary | Fixed `6c7d33539` (`--held-section`); 1209 repaired, hold retained | `canary-20260917-failure-1209-plan.json` | Separate bounded repair authority from release acceptance; preserve hold and validate the actual execution plan in dry run. Do not globally exempt content holds from boundary checks |
| Overlong song section swallows the sermon's opening; sermon text and (where cut from sections) media start late — 8 runs, verified on 1007, 1217, 1060 | 17 September blocker sizing | Measured, unrepaired | `structure_macro_section` song sections over 360 s adjacent to a sermon | **Executed**: 1060, 1009, 948, 1274, 1303 repaired; 1340 regressed 19 s at the sermon start and is held. 1007/1217 not re-run — their media came from the RMS fallback and is already right |
| Sermon cut from a dominant RMS block while a disqualified (not held) sermon section exists (1007, 1041, 1217) | 17 September blocker sizing | **Closed**: all three cuts are right; the sections are wrong | `media_processing_logs.processing_metadata.sermon_extraction_plan`; Whisper on the three MP3s | No change: parking stays scoped to content holds |
| Unplaced-hold refusal is retried by the detection job and the refused detection is not kept (1314 §3994) | 17 September canary | Run stopped as designed; operator decision on §3994 pending | `storage/logs/laravel.log` 16:45–16:54 UTC | Fail without retry on `UnplacedContentHoldException`; keep the refused section list for operator inspection |
| Held section candidates remain on the staging volume only (1221 §2718/§2719/§2721) | 17 September canary | Recorded; re-cut clips verified to new bounds | `section-publications/27{18,19,21}-*` on staging | Decide whether held candidates promote to quarantine before staging is retired |
| One-word sentence drift and context-carried loops (1314, 1343, 1258; partial 980) | Blind BC-08 | Prevention implemented; pipeline recovery and wider sizing pending | `blind-20260916-comparison/bc08-20260917/` | §4.2: context options, four-run recovery, raw-transcript census and independent source checks |
| Spoken hymn quotation typed as singing, cutting off sermon ending (1314 §3994/§3992) | Blind BC-01 | Specific response/decision and repaired ending pending; hold status to reconcile | `blind-20260916-comparison/blind-20260916-comparison-register.json` | Re-detect after transcript recovery; verify quotation retained in sermon; do not substitute the rejected broad unsung-song rule |
| Performed song lacks linked song identity (1221 §2722) | Blind BC-06 | **Closed for the deterministic class 09-18** (`ecfa94d31`); 11 adjudications remain | Same blind register | See the BC-06 ruling below |
| Children's talk typed `other` (1221 §2721, 1358 §4684) | Blind BC-07 | §2721 fixed by re-detection; **census done 09-18 and it overturns a bulk retype**; §4684 still open | Same blind register | See the BC-07 census below — the ruling needs re-asking |
| Sermon ending possibly absorbed by song (1336 §4263/§4264) | Blind BC-05 | Unresolved; source replay required | Same blind register | Listen at 3320–3380; distinguish preaching from announcement and verify the repaired sermon ending |
| Sermon transcript loops (≥5 repeats, ≥40 words) | P8-Q14 | Implemented: standard screen; shorter/varying cases remain §4.2 | — | extend per §4.2 |
| Short, few-word and number-varying loops | 09-10 review | Unbuilt extension | `correctness-20260910-unheld-short-loops` | §4.2 |
| Sparse 30 s-cadence transcript loss | §4.1a r1 | Unbuilt | `residue-20260913-cadence.py` | §4.2 |
| Reading loop inside sermon text (1347) | §4.1a r1 | Unbuilt short-loop extension | — | §4.2 |
| Looping song transcript (§1216; 226 song sections, 63 half loop on current membership) | §4.1b song-loop census; **re-anchored and contained 2026-09-16** | Implemented/applied: v5 publication risk and loop-gap discount; independent identity confirmation and speech-under-loop detection pending | `songloop-20260914-census.php`, `songloop-20260914-score.php`, `sungpass-20260916-measure.php`, `songloop-20260916-adjudicate.php` | **Demotion built test-first 2026-09-16.** `SongLoopedTranscript` reads the screen's blocks from `MediaProcessingLog::recordedTranscriptSuspectBlocks()` — the run's own metadata, not the transcript artifact, so a screen still speaks for a section whose staging file can no longer be reached — clips them to the section, and reports the share they cover. At or above `section_publishing.song_boundary.looped_transcript_minimum_share` (0.5) it raises `song_looped_transcript` through `SongPublicationBoundaryEvidenceService`'s risks into the review policy's reasons, and `SongPublicationHandler::requiresApproval()` returns true on any reason. That is a **demotion, not a content-defect hold**, as P8-Q14 requires for songs: the clip reaches a reviewer instead of publishing itself. Null blocks are *unknown* and are recorded without demoting — every run completed before the screen existed carries none, and reading that as clearance would pass exactly the corpus the screen was built for; an empty list is the positive claim that the transcript was read and found clear. **`VERSION` 2 → 3**, which is the half that reaches the corpus: banked evidence is now stale, so `service:backfill-song-boundary-evidence` re-selects and re-assesses every song section rather than the rule applying only to sections assessed from now on. Deliberately *not* built: a risk on a loop crossing the section edge — `crosses_section` is measured and recorded, but nothing has measured that class and a rule ahead of its measurement is the error this programme keeps finding. Gates: 121 targeted tests (demotion end-to-end, the new service, the handler, and the backfill under the version bump), PHPStan clean over 968 files, Pint clean. **Gap discount built test-first 2026-09-16**, the second half of the same row. A wordless gap is evidence about a song's edges only because the cues around it record what was said; inside a loop they do not, so `gapIsLooped()` now discounts such a gap exactly where `gapIsUnobservable()` already does, at both the leading and trailing edge. The blocks travel in `loadInputs()` — which is defined as everything an assessment reads — so no observation signature changed, and they come from run metadata, so they are still available when the transcript artifact cannot be reached. A discount is the *absence* of a risk, so it is recorded in the evidence a reviewer reads (`start_evidence`/`end_evidence` basis `looped_gap`) rather than among the reasons that withhold a clip, matching `unobservable_gap`. Each edge has a **control test** proving the fixture genuinely raises `song_boundary_spoken_framing` and `song_boundary_trailing_content` first, so neither discount test can pass because nothing was there to discount. **`VERSION` 3 → 4**: this changes what banked evidence *says* without changing what it was read *from*, which the inputs fingerprint cannot see, so the version is the only thing that makes those rows stale — and **a second backfill pass was therefore run** (operator instruction, 2026-09-16; `backfill2-20260916-gate-before.json` / `-after.json`), because the earlier pass banked version 3 under the pre-discount rule and some of its sections were held for framing a loop invented. It re-banked all 796 at version 4 (version 3 now 0) and removed **33 boundary risks** — `spoken_framing` 204 → 201, `..._exceeds_limit` 182 → 167, `trailing_content` 98 → 83. **It released nothing, and could not have**: `SongBoundaryEvidenceBackfill::writeSection()` sets `needs_manual_review = true` when reasons exist and has no else branch, so a hold is only ever raised, never lowered. Flagged stayed at 462 and publicly published song videos at 37, exactly as before. The visible consequence is that **7 sections now carry evidence reading `decision: release_eligible` with no risks, their `song_publication_review` reasons unset, and `needs_manual_review` still true** — held with nothing recorded saying why: §1392 (1028), §1924 (1111), §2069 (1130), §2483 (1198), §3097 (1248), §3310 (1264), §3486 (1278), all `pending_approval`, so none is publicly visible. These are precisely the clips the loop invented a framing risk for, and releasing them is an operator act: `ConfirmServiceSection` is the only writer of `needs_manual_review = false` in the codebase, which is the review queue working as designed rather than a gap. Six further in-scope sections stay at version 1 and are **correctly** excluded — `ServiceSection::scopeNotSuperseded()` drops any section whose run is superseded (1189 → 893, 1249 → 1248, 1378 → 890), and refreshing evidence for a withdrawn run's clips would bank evidence for clips that no longer stand for anything. Note in passing that §3111, §3119, §4608 and §4618 are `published` on superseded runs; that is a pre-existing question this row does not answer. Gates: 94 section-publication tests, PHPStan clean over 968 files, Pint clean. **The 20–50% band was then measured, and the line replaced rather than moved** (operator asked whether to lower it, 2026-09-16; `songloop-20260916-band.php` → `-band.json`, read-only). Adjudicating all 85 sections between a fifth and a half by the same lyrics test: **59 `loop_defect` (69%)**, 10 legitimate repetition, 5 mixed, 11 unjudgeable for want of catalogue lyrics, 0 read errors. Against 80% above the line, **the share barely discriminates** — a threshold that hardly changes the defect rate as it is crossed is a number drawn across a continuum, and it is wrong in both directions: §1862 loops at 0.51 repeating a phrase nowhere in its song, while §3750 loops well past the line singing its own chorus and was named a genuine chorus by the 09-14 census. Lowering the line to 0.2 would have held all 85, including those 10 choruses and 11 unjudgeable, which is the "increasing holds alone is not an automation improvement" trap this plan already warns against. **So the phrase decides and the share became the fallback** (`VERSION` 4 → 5, built test-first): `SongLoopedTranscript` resolves the bound song's lyrics, normalises phrase and lyrics alike to words, and raises the risk when any repetition block repeats text the song does not contain; where the song carries no catalogue lyrics — 11 of the 85, a blind spot the share does not have — the share still decides, so a thin catalogue cannot make a section read as clean. The observation records which `basis` decided it, and `inputs()` now fingerprints the lyrics, so editing a song's words makes banked evidence stale rather than leaving a cleared clip cleared. **14 of the band's defects were unheld and in scope, and were held** (operator instruction; shares 0.22–0.48; `songloop-20260916-band-gate-before.json` / `-after.json`): gate before 0 refusals, after **14 refusals**, with **0 publicly visible throughout** — every clip was already quarantined, so nothing changed in public exposure. Their recorded reason names the phrase rather than the share, because that is what condemns them. **The 14 were then demoted** (operator instruction, 2026-09-16; `songloop-20260916-band-gate-demote-before.json` / `-demote-after.json`), because a row claiming `published` while held with a quarantined clip is a contradiction every "published sections" census walks into — the demote pass alone assesses 353 such rows. The objection worth testing first was whether demotion hides a section from review, and it does not: `ServiceReviewDashboardQuery`'s base predicate is `notSuperseded()` AND (`needs_manual_review` OR `pending_approval` OR structural uncertainty), and demotion leaves the first disjunct set, so the section stays in the queue exactly as before. It is also the established practice here — 38 held published sections were demoted on 09-15 with only 2 of them visible, and a further 43 the same day with none visible or refused. Gate before and after are identical where it matters: 14 sections, 14 content holds, 14 refusals, **0 publicly visible throughout**; all 14 now read `not_applicable` with their clips quarantined, publicly published song clips stayed at 36, and no publicly visible section anywhere carries a loop reason. The caveat stands that these holds rest on a lyrics comparison rather than on anyone listening. **§2738 (run 1222) and §2916 (run 1235)** — the two `mixed` sections the hand-holds missed — were held by the third pass and now sit in the same `published`-with-quarantined-clip state; they were **demoted the same day** on a later instruction (`songloop-20260916-mixed-gate-demote-before.json` / `-demote-after.json`): both now `not_applicable`, still held with `song_looped_transcript` recorded, clips 283 and 509 untouched at `quarantined`, the gate refusing both throughout, publicly published song clips unchanged at 36 and no publicly visible section carrying a loop reason. **A larger count came out of that check and is not yet addressed: 45 published song sections remain held**, which is the same contradiction these 16 were cleared of — a row recording itself as published while held. They were never part of this row's membership, so nothing here has adjudicated them; a `--all` dry run of `service:demote-held-publications` is the cheap way to see what they are before deciding, and the count is recorded now so it is not mistaken later for something this work left behind. **Characterised and then demoted the same day** (operator instruction; `demote45-20260916-gate-before.json` / `-after.json`, ids in `demote45-20260916-ids.json`). They were not a separate population after all: **all 45 are historic, every clip was already quarantined, none was publicly visible, and 44 are held for `song_looped_transcript`** — the sections this row's own rule caught across the three passes, left in the same published-while-held contradiction. The 45th is **§3750**, the genuine chorus held by operator ruling on 2026-09-16 rather than by measurement; every automated test now clears it (v5 evidence reads `release_eligible` with no risks), so it was included to make the row consistent while leaving the ruling's hold untouched, which demotion does not disturb. Gate before: 45 `published`, 45 held, 45 clips, 0 publicly visible, 45 refusals. After: **45 `not_applicable`, all 45 still held, all 45 clips still quarantined**, 0 publicly visible, 45 refusals. Corpus-wide the contradictory state is now **45 → 0**, and **no published section of any type remains held**; publicly published song clips stayed at 36 throughout and no publicly visible section carries a loop reason. The 440 song sections still held in scope are `pending_approval`, which is the review queue doing its job rather than a contradiction. Every hold demoted here rests on comparing a looped phrase against catalogue lyrics; nobody has listened to any of them. Note that the rule change releases nothing by itself: the backfill only ever raises a hold, so sections held above the line for repeating their own chorus stay held until someone confirms them in the review queue. **The band under a fifth was then measured too** (`songloop-20260916-lowband.php` → `-lowband.json`, read-only), because removing the share as the gate made a population relevant that had never been looked at: while the demotion depended on coverage, a section looping under 0.2 could not withhold a clip. All 78, 0 errors: **54 `loop_defect` (69%)**, 13 legitimate repetition, 10 unjudgeable, 1 mixed. **Three bands now read 80% / 69% / 69%** — near-identical defect rates across the whole range, which is the measurement that retires the share as a signal rather than merely demoting it. The extreme case is §1577, whose loop covers **0.7%** of the section and whose phrase appears nowhere in its bound song: no threshold could have found it. **A correction to the hand-holds above**: the 14 should have been 16. That band's exposure filter counted only pure `loop_defect`, but the rule raises the risk on any absent phrase, so the two unheld in-scope `mixed` sections (§2738 run 1222, §2916 run 1235) belonged in it — a filter written for the old rule and reused after the rule changed. **Third pass run 2026-09-16** (operator instruction; `backfill3-20260916-gate-before.json` / `-after.json`): all 796 re-banked at version 5, flagged **476 → 502**, and this pass *did* move the queue, unlike the second. Six of the seven sections the second pass left held with no recorded reason regained one; only §2069 still carries a hold with nothing explaining it. **It also caught what no measurement here could.** §351 (run 911, **weekly**, 2025-04-13) was `published` with **video 559 publicly visible**, bound `confirmed` to a song whose lyrics do not contain the phrase its transcript repeats 34 times. This plan's 437-run register is historic-only by construction, so §351 was invisible to every census run today, exactly as §339 and §348 were this morning — the rule found it because it no longer depends on a measurement anyone had taken. Held by the pass and **demoted on operator instruction** (`songloop-20260916-s351-gate-before.json` / `-after.json`): `published` and visible before, `not_applicable` with the clip quarantined after; publicly published song clips fell **37 → 36**, and **no publicly visible section anywhere now carries a loop reason**. Three further weekly sections name it (§366, §381, §409), all awaiting approval with no clip generated. **Still to build**: a song half loop or more reading as identity-unverified until independent performance evidence meets §4.3's confirmation rule, OCR or another ASR pass not sufficing; the flag for a loop whose audio is speech under looped sung text (967 §1082, 1268 §4731, 1348 §4390); and re-detecting 1287 through the pipeline. **Re-anchored 2026-09-16** to the 437-run denominator (the five excluded runs removed): 226 song sections carry blocks, 63 at half loop or more, 89 with a generated clip — the 09-14 figures of 229/65 differ only by the excluded runs. **25 of the 63 were unheld**, 22 with a clip, every one bound `confirmed`. **Adjudicated before holding** (operator ruling 2026-09-16, `songloop-20260916-adjudication.json`) by the 09-14 census's own test — whether the looped phrase occurs in the *bound song's* lyrics, since a chorus legitimately repeats text the song contains: **20 `loop_defect`** (no block's phrase appears in the bound lyrics; repeats run to ×96 on "for the lord i will st" in §1862, ×89 in §2309, ×81 in §3321), **1 `legitimate_repetition`** (§3750, run 1154 — independently reproducing one of the six genuine choruses the 09-14 census named, so the test returns a known-good answer rather than flagging everything), **2 `mixed`** (§1308, §4501: one block in the lyrics, the rest not) and **2 `unadjudicable`** (§2439, §2755: the bound song holds no `lyrics_plain`). **The 20 were held** through `service:hold-section-content --execute` with the reason and evidence recorded; the other 5 were deliberately left alone and still need a decision. Gate before (`songloop-20260916-gate-before.json`): 20 sections, **0 content holds, 0 refusals** — all 19 clips releasable. Gate after (`-gate-after.json`): **20 content holds, 19 refusals**, each "song video N is held for review: section M awaits manual review"; §3321 has no clip and is held on its section alone. **The other five were held too, by operator ruling 2026-09-16**, in three classes with separate recorded reasons so the register does not later read as one measured verdict: §3750 is held **despite adjudicating as legitimate repetition** — its looped phrase occurs in the bound song's own lyrics, so this is a ruling, not a detection, and it should not be cited as a defect the screen found; §1308 and §4501 are `mixed`, where one block's phrase is in the lyrics and the rest are not, so the song repeating does not account for the loop; §2439 and §2755 are unjudgeable, their bound songs carrying no catalogue lyrics to compare against. Residue gate before (`songloop-20260916-residue-gate-before.json`): 5 sections, 0 content holds, 0 refusals. After (`-after.json`): 5 content holds and **3 refusals** (song videos 176, 271, 431); §2439 and §2755 have no clip and are held on their sections alone. All 25 of the unheld half-loop sections are therefore now contained. The holds are containment and the detector is the rule: these 25 sections are its first regression cases, and the backfill under `VERSION` 3 is what applies it to the rest of the corpus. **The backfill dry run then found the class on the weekly lane** (`service:backfill-song-boundary-evidence`, read-only, 2026-09-16). It selects 798 sections as missing or stale under version 3 and reports "would newly hold 464" — which is *not* a delta: the count is `$reasons !== []`, and 462 of the 804 in-scope song sections were already flagged, so the rule's own contribution is two sections. `song_looped_transcript` is named on 51, of which 48 are the registered half-loop sections in scope and **all 48 were already held**. The other two were invisible to this plan by construction: **§339 (run 910) and §348 (run 911) are `livestream` runs**, so neither sits in the 437-run historic denominator. Both were `published` with no review flag and both song videos (557, 558) were publicly visible. Adjudicated by the same lyrics test (`songloop-20260916-weekly-gate-before.json`): §339 repeats "we look forward to the lord" 65 times plus two further blocks, §348 repeats "come and out of glory god of heaven we h…" 26 times, and **not one phrase occurs in its bound song's lyrics** — decode loops, not choruses. **Held and demoted 2026-09-16** (operator ruling), by `service:hold-section-content --execute` then `service:demote-held-publications --section=339 --section=348 --apply`, scoped by section so no unruled row could be caught. Gate before: 2 sections, 0 refusals, both visible. After (`-weekly-gate-after.json`): both `not_applicable`, both held, **both videos quarantined, 2 refusals**; publicly published song videos fell from 39 to 37. Demotion is not deletion — every file and row is intact behind the hold. **The exposure figure is a measurement, not a floor**: of the 342 unheld in-scope song sections, none is unscreened (145 screened clean, 197 carrying blocks), so the rule has genuinely spoken for all of them. The finding that matters beyond these two rows is that **this class is not historic-only** — a detector built for the corpus found its first live defects on the weekly path, which is the argument for building the remaining rows as prevention rather than containment |
| Mixed-song clip (§3869) | P8-Q10/16 | Implemented/applied: policy plus backfill raises stored hold; current coverage checked in §3 | `correctness-20260910-song-policy` | §4.3 |
| Song clip with continuous spoken lead-in or tail (§988, §1457, §2897, §1475; blind set BC-03: 1336 §4264, 1221 §2718/§2719/§2722, 1066 §1686/§1688, 1358 §4682) | 09-10 review; §4.1a; §4.1b blind comparison | Implemented 2026-09-17: `SongSpeechEdges` trims at detection; re-detections pending | `songspeech-20260917-{measure,corpus,replay,lyricedge}.php`, `songspeech_adjudicate.py` | §4.3 continuous-speech item (09-17): trims 396 of 1,177; 20/20 sampled lead-ins spoken |
| Wrong song from `title_hint_fuzzy` (25 clips) | §4.1a r2 | Implemented: catalogue-title precedence; re-resolution and independent identity checks pending | `residue-20260914-hint-census.php`, `residue-20260914-lyric-identity.py` | §4.3 |
| OoS items written from the wrong song | §4.1a r2 | Repair pending after owning song identities settle | same | §4.3 (re-resolve and rewrite) |
| Sung item typed reading/prayer/other (~~1253, 1349, 944 §666~~; **1014 §1301 only**) | §4.1a r2, **corrected 2026-09-16** | Implemented: MistypedSungSections plus absorbed-section risk; re-detect 1014 | `residue-20260914-sung-other.py`, `sungpass-20260916-measure.php` | **Measured 2026-09-16 over all 437 runs** (`sungpass-20260916-measure.json`, read-only). **Four of the five named sections are not sung items.** The prototype scored lyrics alone; adding the sound instrument (`SustainedSound` at the 09-15 thresholds) and word rate separates them, and each of the four is a different thing: §4717 (1253) is a *spoken* congregational reading of Psalm 46 colliding with the metrical psalm "God Is Our Refuge And Our Strength #046A" (0.578 active, 21.4 pauses/min, 107 wpm; its order-of-service item is typed `bibles`); §4406 (1349) is two hymn verses **read aloud as a prayer** — the speaker says "let's make these verses our prayer" (0.640/21.8, 102 wpm; OoS item typed `custom`); §666 (944) is an **ASR loop** ("we're going to sing again" ×22), which the repetition screen already blocks at 950–974, so it belongs to the song-loop row; §981 (973) is the hymn's **announcement**, and the hymn was never sung on the recording — the audio ends at 3132 s with no sustained span after 3000 s. Only **§1301 (1014) "Lo He Comes With Clouds Descending"** is genuinely sung (0.929 active, 1.2 pauses/min, 44 wpm), and the sermon media span 0–1501 includes it. Corpus-wide only 10 non-song sections carry a catalogue hymn title, all typed `other` and none held; nine run at 52–398 wpm and are announcements or readings. **So the class has one member in 437 runs.** Design consequence: a lyric scorer over non-song sections is not the instrument — spoken hymn and psalm text is normal here by design — and an AND of lyric and sound thresholds matched *nothing* (`both_count` 0). Sound-led with a lyric floor finds §1301 and excludes the nine wordless music/pre-service-audio sections; record that the lyric floor only works where ASR heard the singing, which is exactly what the "singing invisible to the transcript" row says often fails. **Built test-first 2026-09-16, in two stages, because neither stage alone holds what the rule needs.** `MistypedSungSections` runs at structure detection, after the sustained-sound stage has settled the song sections — so a span still typed as something else is one no song claimed — and flags a welcome, prayer, notices, reading or `other` section of 45 s or more that is at least 0.8 sustained and runs under 60 words a minute, as `structure_section_reads_as_sung`. It needed the transcript, which the sound path did not carry; `DetectServiceStructure` and `StructureEvaluateCommand` both already had one in scope at the call, so it is passed in rather than re-loaded. **The lyric floor this row originally proposed was not built, and should not be**: it only works where ASR heard the singing, which is exactly what fails for invisible singing. The absorption test does that filtering better and for free — measured 2026-09-16, 13 sections read as sung across 437 services and **exactly one is absorbed into a sermon's span**, §1301; the other twelve are pre-service music, opening audio, closing blessings, welcomes and a promotional video, none of them absorbed. So `SermonExtractionPlanResolver::resolveSermonEnd()` raises `sermon_absorbed_sung_item` when an absorbed section carries the flag, placed with the other risk checks and *before* the ceiling block that can clear `$absorbed`, or it would never fire on a ceiling-capped span. **The flag never reaches a sermon, song or children's talk**: `SermonAutoExtractionPolicy` permits automatic extraction only when every flag on the chosen section is registered as non-disqualifying, so an unregistered flag on a sermon would quietly stop it extracting — there is a test pinning that, painting the sermon's own span as unbroken sound so only the type exclusion stands in the way. Gates: 6 unit tests, 41 resolver tests, 27 job tests, PHPStan clean. **Due**: the flag is written at detection, so no existing run carries it — §1301 gains it only when run 1014 is re-detected through the pipeline |
| OoS song with no detected section (944) | §4.1a r1 | Unbuilt generic count/order response | — | **Specification**: OoS-vs-sections count/order flag (§4.1b census) |
| Talk cut by end of its only source (§1684, §1793, §3943) or starting mid-thought (§1421, 980) | §4.1a r2 | Partial: offline source-end screen; weekly/start/mid-thought checks pending | `residue-20260913-text-signals.json` | **Specification**: section within N s of source start/end plus mid-sentence transcript edge → `source_truncates_talk` flag |
| Source audio dropout inside a talk (≥15 s at ≤ −80 dB) | §4.1a r1 | Unbuilt; containment, not reconstruction | `residue-20260913-dropouts.py` | **Specification**: RMS dropout flag on the section; not repairable, so the flag is the outcome |
| Published title/reference contradicts summary or transcript (881, 954, 844/845/850, 899) | §4.1a r1; §4.1b Scripture census | Unbuilt source-informed contradiction/provenance response | `residue-20260913-references.php`, `scripture-20260914-census.php` | **Specification**: published-vs-heard reference check and title↔section-title overlap at analysis time (summary overlap alone shares the title's source); adopted rows with null provenance refuse publication, distinguished from the 31 rows created before provenance tracking (2026-08-31), which must not be refused |
| Multi-passage reference cut to its first passage on link (1031, 1159, 1188, 1233) | §4.1b Scripture census | Unfixed: resolver still takes the first passage | `scripture-20260914-register.json` | **Specification**: link a passage per part (or the envelope) without rewriting `reference` to the first part; flag a linked reference that no longer covers the analysis reference; re-link the four |
| Whole single-chapter letter rejected as a reference (957, 1090) | §4.1b Scripture census | Implemented: whole-book validation; reanalyse 957/1090 | same | **Specification**: accept a single-chapter book as its whole chapter in `validateBibleReference`; re-run analysis for the two |
| Sermon reference never linked to a passage (908–910, 912–915) | §4.1b Scripture census | Unbuilt reconciliation; release bundle already refuses; seven enrichments pending | same | **Specification**: a reconciliation check that every sermon with a parseable reference has a passage or a recorded absence; re-dispatch enrichment for the seven |
| Preached reading dropped from sermon media by an order flag (1075, 1254, 1286, 1299) | §4.1b Scripture census | Implemented: matching reading retained despite order-only flag; four replans pending | same | **Specification**: in `selectBibleReading()`, do not exclude a reading held only for an order-of-service flag when its reference matches the sermon, or record the omission as a plan risk; re-plan the four through the pipeline |
| Verse quoted inside a prayer typed as a Bible reading (1043 §1528) | §4.1b Scripture census; operator 2026-09-14 | Unbuilt response/decision; historic case 1043 excluded | `scripture-20260914-register.json` | **Specification**: the structure detector keeps a verse quoted within a prayer inside the prayer section, not a `bible_reading` (census: 1043 is the only case of 587 readings); no re-detection is due, because 1043 is excluded as a Saturday rehearsal (date census; ruling 2026-09-14) |
| Structure boundary written one minute late: `m:ss` prompt times converted to seconds (confirmed 1203 §2535, 1183 §2391, 1305 §3894; probable 962 §924, 1141 §2137) | §4.1b Scripture census | Partial: prompt seconds implemented; semantic offset screens and five re-detections pending | `scripture-20260914-register.json` | **Specification**: render prompt cue times in the unit the model returns (seconds), or have it return cue indices; flag a section that starts mid-sentence after an unsectioned or foreign-content minute; screen every run for a strong boundary cue exactly 60 s before a section start; re-detect the five through the pipeline and check media that crossed the slipped minute (sermon 1102, song video 122) |
| Sermon page names the service's first reading, not the sermon's (157 of 438) | §4.1b Scripture census | Implemented: extraction-span/matching reading selection; no media rerun | `scripture-20260914-page-reading.txt` | **Specification**: `SermonPageContextService` shows the plan's reading, or the reading matching the reference, else none |
| Saved sermon text predates evidence (P8-Q1) | P8-Q1 | Implemented: sermon_text_predates_evidence; refresh after transcript recovery | — | keep |
| Sermon MP3 loses closing words (12; stream-copied video vs plan-cut audio) | §4.1b duration census | Implemented: whole-video audio and length mismatch hold; 12 repairs pending | `duration-20260914-census.json` (`duration − picture delay − MP3 length`) | **Specification**: in `ExtractSermon`, produce the MP3 from the final sermon video's audio track: the whole track, with no second cut from the source and no length taken from the plan, for both `single_span` and `concat_spans` (this also removes the independent single-span cut behind 1257's loss); flag an MP3 whose length differs from its video's audio track; then re-run the 12 through the pipeline and clear their holds only on a clean re-measure |
| Video picture starts after its audio, or audio carries the preceding item (stream-copy keyframe lead-in): sermons 12 over 3 s; song clips 89 frozen openings and 23 with lead-in audio over 1 s | §4.1b duration censuses | Implemented: smart cut plus fda414eb2 source-frame correction; canary and corpus reruns pending | `duration-20260914-census.json`, `songdur-20260914-census.json` | **Specification** (ruling 3a: smart cut): in the shared `VideoExtractionService`, which cuts both sermon pieces and song clips, re-encode only from each cut point to the next keyframe and stream-copy the rest, so every piece starts exactly on its planned time with picture and sound together; check picture delay and length after every extraction (0 within one frame; length equals the span); make the re-encode path meet the same check (its clips still lag 0.2–1.0 s); write `SongVideo.duration` from the probed file, not the section; re-run affected sermons and song clips through the pipeline |
| Hymn inside the sermon section (#885) | 09-10 review | Unbuilt: wholly inside sermon differs from absorbed non-sermon section | `sungpass-20260916-measure.php` | **Measured 2026-09-16 over all 437 runs** (`sungpass-20260916-measure.json`). #885's hymn is located: sermon 885 belongs to **run 949**, and its closing hymn sits at **4635–4795 s** inside sermon §723 (2744–4827) — wholly within the unobservable window 4615.16–4797.16 (`retranscription_failed`), so the transcript holds **no** lyrics for it and a lyric scorer sees nothing. The sound instrument finds it exactly: 1.000 active, 0.0 pauses/min, against 0.952/3.4 for the same run's song §718 and 0.683/17.9 for its own sermon body. **Sustained sound alone is not the detector**: sustained spans of 30 s or more inside a sermon and held by no song section number **272 across 150 runs**, nearly all ordinary preaching at 104–181 wpm. Adding word rate collapses it to **2 spans**, and the collapse is stable from <20 to <60 wpm rather than balanced on a tuned edge. The two survivors are run 949's hymn (0 cues, 100% unobservable, at the sermon's end) and run 1014 §1300's "Thank you ×4" ASR artefact, **already held**. **new**: flag a sustained-sound span of 30 s or more inside a sermon section, held by no song section, whose word rate is under 40 wpm; two candidates per 437 runs is the whole review burden. Regression cases: run 949 §723 (positive), run 1014 §1300 (positive, ASR artefact), and any preaching span at 104–181 wpm as negatives |
| Duplicate/date pair identity | P8-Q7 | Uncontained identity dispute: five sermons gate-clear; explicit holds/adoption pending | — | §4.4 |
| Automatic video-quality rejection hides a good video (27 of 47: static camera, dim lighting) | §4.1b matrix | Implemented/applied: rebuilt dead-picture detector; 48 prior rejections reconciled; independent negatives pending | `vq-20260914-register.json`; ffmpeg `freezedetect` over 4 min separated all 47 | **Built 2026-09-16.** `VideoDeadPictureProbe` measures freeze and black time with ffmpeg's own detectors over 6 windows of 30 s spread from the recording's first second to its last; `SermonVideoQualityAssessmentService` reads the *coverage* of that dead time and nothing else — the 16×16 fingerprint burst, the brightness floor and the GD frame scoring are deleted. **Coverage decides how far a verdict may go:** dead in three quarters of the windows or more rejects (and hides); any lesser dead time is `partially_frozen`/`partially_black` for review, because a recording that carries real preaching for part of its length is not the detector's to withhold. Calibration over all 48 rejections, running the application service (`vq-20260916-detector-check.json`, `vq-20260916-windows.json`): **48 of 48 agree with the 2026-09-14 adjudication** — the 19 correct rejections stay rejected at 6/6 dead windows, the 28 wrong ones approve at 0/6, and 1225 reads 2/6 → needs review (operator: SHOW it). **Re-assessed through the pipeline 2026-09-16** (operator approval), by `sermons:assess-video-quality --all --reason=frozen_frames` then `--reason=mostly_black`, sequential and in-process — never `--queue`, whose workers hold stale code — and never by hand. All 48 stored verdicts now agree with the adjudication (`vq-20260916-reassessment.json`): 28 approved, 19 rejected, 1225 to review; no sermon outside the register was touched and all 48 remain quarantined, so nothing changed in public exposure. The 19 rejections also split 12 `mostly_black` / 7 `frozen_frames`, matching the 09-14 count of 12 black recordings and 7 holding cards that the old detector labelled `mostly_black` alike. **Approvals censused 2026-09-16** (`vq-20260916-approvals-census.json`, read-only): 432 approved videos, 429 measured — the 3 unmeasurable are published rows whose local clips do not exist, their sources intact on `/mnt/cbc-services`. 421 agree; **8 flagged for review, 0 newly rejected** (1.86% review burden). Adjudicated against contact sheets, black extents and audio levels (`vq-20260916-approvals-adjudication.json`): **7 are real defects the old detector missed** — four black openings of 52–177 s (926, 930, 941, 975), a 106 s loss of picture mid-sermon under continuing speech (1276), and the camera-fault card in 1189 and 1230 — and **one is a false positive** (867, a projected Philippians 2 slide held 21 s; the only flag on a published row), a rate of 1 in 429. The seven remain stored as `approved` until re-assessed through the pipeline. Thresholds must not be tuned against this set. Note the census cannot see a video both detectors approve wrongly; that stays with §4.5's reserved human sample. **The seven defects were re-assessed through the pipeline 2026-09-16** and now read `needs_review` — `partially_black` for 926, 930, 975 and 1276, `partially_frozen` for 941, 1189 and 1230. 867 was deliberately left `approved`: a held slide is not a broken recording, and flipping it by hand would bury the one measured false positive. Two rows with video (897, 1005) have never been assessed at all and sit outside this census, which covered approvals only |
| A discredited verdict survives where the evidence cannot be re-read (862, **published**) | §4.3a approvals census 2026-09-16 | Source evidence established; reassessment where output lives pending | `laravel.log` 2026-09-16 07:40:57 | **Specification**: sermon 862 (published, 2023-09-03) is hidden from its public page by the old detector's `frozen_frames` verdict of 2026-07-09 — the class that proved 25 of 27 wrong. It was matched by the re-assessment pass, but this machine holds no clip for it, so `AssessSermonVideoQuality`'s settled-verdict guard held rather than overwrite a verdict with `missing_video_file`, and the row kept the discredited answer. The guard is right in itself; the gap is that nothing distinguishes a verdict worth keeping from one whose detector has since been replaced. Re-assess 862 where its bytes live (production), or re-derive the clip from the surviving source (`/mnt/cbc-services/2023-09-03/Morning/Sunday 3rd September 2023 [YouTube backup].mp4`, 2.96 GB, 4356 s). **Measured 2026-09-16: the rejection is demonstrably false.** The sermon's span in that source (2066–4107 s) shows no freeze and no black in any of 6 windows of 30 s, and frames at 2100, 3000 and 4000 s show the preacher at the lectern in three different postures — the static-camera class the old detector misread 25 times in 27 (`vq-20260916-candidates/862-source.jpg`). What is missing is not evidence but a machine that can write the corrected verdict where the bytes live. Consider recording the detector's identity alongside a verdict, so a superseded verdict can be found rather than inferred |
| Sermon video opens with no picture (926: 85 s, 930: 85 s, 975: 52 s, 941: 177 s), or loses it mid-sermon (1276: 106 s of black from 1682 s while speech continues at −2.6 dB) | §4.3a approvals census 2026-09-16 | Partial: review detection applied; source-versus-extraction diagnosis pending | `vq-20260916-approvals-adjudication.json`, contact sheets in `vq-20260916-candidates/` | **Specification**: the clip begins before there is any picture, so the cause sits upstream of the detector and is not yet established — determine for each whether the black is in the source or introduced by the planned span, by comparing the section's start against the source's first picture. If the span is at fault, check picture start against audio start at extraction (the measurement ruling 3a's smart cut already added) and re-plan or refuse a sermon whose opening carries no picture; if the source is black, it is a recording dropout like the RMS dropout class and the flag is the outcome. 1276's loss is mid-recording, so it is a dropout regardless of the cut. Re-extract or re-plan the five through the pipeline once the cause is known; the camera-fault cards in the same census (1189, 1230) need no new response, being the partial form of the whole-recording cards the detector already rejects |
| Quality verdict written without run evidence (13, `sermons:assess-video-quality`) | §4.1b matrix | Implemented/applied/verified: owning-run evidence; closed | — | **Closed 2026-09-16.** The job already resolves the run that published the sermon when it is dispatched with a sermon id alone, and writes the verdict there. Verified on live data rather than from the test: each of the seven command-path assessments run on 2026-09-16 wrote status, reason and window counts to its owning run, and all 13 verdicts that originally left evidence only in `laravel.log` (runs 1044–1113) now carry current evidence on the run. No assessed row is left without it |
| Rehearsal recording imported as its own service (1043, 1089: Saturday sermon-only takes of Sunday's sermon) | §4.1b date census | Partial: exclusions applied; automatic duplicate detector unbuilt | `date-20260914-register.json` (same reference within 7 days, 6-word phrase overlap) | **Specification**: after analysis, flag a sermon whose reference matches another sermon's within 7 days with transcript overlap over 20% (rehearsals 21–31%, Christmas readings 11–18%, series about 5%), and flag a sermon-only source dated the day before a Sunday service; add an operator exclusion reason for a rehearsal (`HistoricRunExclusion` accepts only `no_sermon_in_source`) that also withdraws the run's Sermon and SongVideo rows; exclude 1043 and 1089 (ruling 2026-09-14) |
| Non-Sunday occasion with no `occasion` (funerals 1051, 1098; holiday club 1144) | §4.1b date census | Partial: private-occasion exclusions applied; missing-occasion detector unbuilt | same | **Specification**: flag a non-Sunday service without `occasion` for review before publication; add an operator exclusion reason for a private occasion, with the same withdrawal of Sermon and SongVideo rows; exclude 1051 and 1098 (ruling 2026-09-14) |
| Closing prayer left out of the sermon when it has no section (7: sermons 1027, 981, 1193, 1299, 1172, 986, 990) | §4.1b section coverage census | Implemented: span extends to next song; seven repairs await settled boundaries | `sections-20260914-register.json` | **Specification**: `resolveSermonEnd()` runs the span through unsectioned time to the next song as well as through trailing sections, under the same ceiling; test a sermon → unsectioned prayer → song fixture; re-plan and re-extract the seven through the pipeline |
| Singing invisible to the transcript: song section cut to its transcribed lines (7: 965, 1109, 1196, 1241, 1269, 1341, 1379), songs shifted one slot (1287) or no section at all (10: 963 ×3, 1001, 1034, 1135, 1195, 1231, 1244, 1311) | §4.1b section coverage census | Implemented: sustained-sound recovery; re-detections/verification pending | `sections-20260914-screen.json` (RMS active ratio), `sections-20260914-sung-probe.sh` | **Specification**: before or after structure detection, flag any unsectioned span, or song section edge, where the RMS log shows sustained sound and the transcript shows an unobservable window or a "Thank you"/"Amen" loop; widen a song section across such sound up to the neighbouring speech, and propose a song section for a listed song with no section when the span fits; re-detect the 17 runs through the pipeline and listen to 1129, 1233, 1240, 1266, 1276 and 1316 |
| Song clip loses its own verses to a neighbouring section (114, 192, 543 unsectioned; 240 prayer; 539 reading; 360, 207 preceding song, so 206 carries the next song) | §4.1b tail inspections (song-edge census) | Implemented/applied: lyric-edge risks; boundary repairs and independent evaluation pending | `songedge-20260914-census.php`, `songedge-20260914-probe.php`, `tails-20260914-register.json` | **Specification**: at publication, score transcript cues within 90 s outside each song section against the bound song's lyrics (ignoring announcement lines and title words) and flag a match for review; since the transcript misses much singing, also flag an edge where RMS shows sustained sound running into an unsectioned span or a non-song section; widen the section to the singing and re-extract through the pipeline; re-detect 960, 1034, 1035, 1049, 1108, 1196 and 1291 |
| Duplicate OoS item binds a clip to the previous song (1337 §4275, video 410: #699 listed twice, hint "Shine Your Light" matched `confirmed`) | §4.1b tail inspections | Partial: adjacent same-song flag; hint/binding contradiction rule and 1337 repair pending | same | **Specification**: a section whose hint does not match its bound song's title or lyrics cannot be `confirmed` to that song; flag consecutive items with the same `song_id` (joins the OoS census duplicate-item class); re-resolve 1337 |
| Song clip audio upsampled to 96 kHz and re-encoded at 128 kbps (245 of 464) | §4.1b tail inspections (format census) | Partial: source-rate/bitrate preservation; post-publication comparison and regeneration pending | `tails-20260914-fingerprint.php` | **Specification**: in `AudioEnhancementService::enhanceVideo()` pass `-ar` equal to the input's sample rate (the `loudnorm` 192 kHz output), and a bitrate no lower than the source's; probe every clip after publication for sample rate equal to the source fingerprint; re-publish clips through the pipeline once the song-edge and smart-cut changes land, so each is re-encoded once |
| Song section with no song in it, above the 15 s micro floor (song videos 74 → §622, announcement only; 83 → §678, doxology as a second copy of the hymn) | §4.1b tail inspections | Unbuilt specific response; short-section adjudication and doxology decision pending | `tails-20260914-register.json`, `sungpass-20260916-measure.php` | **Measured 2026-09-16** (`sungpass-20260916-measure.json`). 42 song sections run under 60 s across the 437 runs, of which **39 are already held** — only §625 (938), §2851 (1231) and §3368 (1269) are not, so the practical exposure is three sections, not the class. 25 match their bound song's lyrics, 17 match nothing. **The doxology rule cannot work from lyrics: `matches_previous` is 0 corpus-wide, because no catalogue song contains "creatures here below" at all.** So the question this row asked is answered — the doxology is *absent* from the catalogue, not mis-attributed — and the choice is to add it as its own item or to detect the case without lyrics (a short song section following another song section, whose text matches no catalogue song). §678's best lyric match was a spurious "For All the Saints" at 3 bigrams, so any lyric rule here also needs a floor. §622 separates cleanly as announcement-only: 8 cues, coverage 0.08, sustained 0.00, while its announcement names the bound title (overlap 1.00). **new**: flag a song section under 60 s whose text matches no catalogue song, distinguishing an announcement (names the bound title, speech-rate) from a fragment; decide the doxology's catalogue status |
| Public sermon query resolves media through config, not the row's `asset_disk` (listings, browse, service lists, podcast feed) | §4.1b consumer-side rendering | Implemented: asset_disk selected and missing column refused; no media rerun | `render-20260914-feeds.php` | **Specification**: add `asset_disk` to `SermonRepository::basePublicSermonQuery()` and fail loudly when it is not loaded, as `isWholeContentPublic()` does for `publication_state`; test a feed item whose `asset_disk` differs from `SERMON_STORAGE_DISK` |
| Quarantined sermon records a media file that does not exist (857 MP3) | §4.1b consumer-side rendering | Unbuilt release existence response; trace 857 and verify exact assets | `render-20260914-register.json` | **Specification**: before release, check every recorded asset path exists on its `asset_disk` and refuse the row otherwise; trace 857 |
| Service URL offered while the service archive is disabled (null `public_from`) | §4.1b consumer-side rendering | Implemented: no URL while archive disabled; no media rerun | same | **Specification**: `publicUrlFor()` returns null when `publicFrom()` is null, matching `applyDateEligibility()` |
| Silent source producing nothing (955) | §4.1a r2 | Implemented/applied: silent-source exclusion; keep | — | keep |
**Status reconciliation, 2026-09-16.** The current-response column above replaces
all earlier `new`/`no` labels and the earlier “14 built / two partial / nine unbuilt”
summary. That count conflated an implemented fix with the whole response: occasion
exclusions do not detect future rehearsals, prompt seconds do not detect every
semantic boundary error, and preserving source sample rate does not implement the
post-publication comparison. The specification/evidence column retains the dated
history; later results supersede earlier “due” statements. §3 and §4's execution
order are the current status and queue.

**Evidence correction for song loops.** The v5 rule and the 09-16 adjudication
scripts both compare repeated phrases with the bound song's catalogue lyrics.
Their 80%/69%/69% figures describe that comparison, **not independently measured
rates of audible decode defects**. A mismatch can be a wrong binding, lyric variant,
spoken material or transcription error; a matching phrase can itself be a decode
loop. The 72 deterministic binding changes make this dependency material. Retain
containment, but do not use agreement with this same lyric rule to certify its
accuracy, to clear a hold or to establish independent performed-song identity.
Audio-adjudicate positives and negatives, record unassessable cases, and revisit
loop evidence after the bound identity changes. Missing lyrics/blocks remain a
coverage limitation; the fallback threshold is not proof that the rest is clean.
Correct the implementation's categorical explanatory wording as part of that
response, with behavioural changes driven by the independent evaluation.

**Priority after reconciliation:** independent review and the repair canary, then
per-run dependency batches. Reuse the measured transcript/RMS pass below for the
remaining hymn-inside-sermon and announcement-only work; do not repeat a full
measurement merely because a row was still labelled `new`. Keep the standard
pipeline as the sole repair path.

**That pass was run on 2026-09-16** (`sungpass-20260916-measure.php` over all 437
non-excluded runs, read-only; register `sungpass-20260916-measure.json`), and each of
the four rows above now carries its result. Three findings bear on how the remaining
rows should be approached. *The instrument is two-dimensional, not one.* Sustained
sound alone is thresholded against each run's own noise floor, so it reads 1.00 on
Welcome and Notices sections at 100–170 wpm; only pairing it with word rate separates
singing from speech. Judged that way the hymn-in-sermon population falls from 272 spans
to 2, and the fall is stable across a wide band rather than balanced on a tuned edge.
*A lyric scorer over non-song sections mostly finds spoken text*, because reading psalms
and hymn verses aloud is normal here by design — which is why four of the five sections
the sung-item row named are not sung items at all. *And most of these classes were
already contained*: 39 of the 42 short song sections and all but 25 of the 63 half-loop
sections were held before this pass began. The unbuilt rows are worth building for the
weekly path, but their historic exposure is smaller than the table implied.

**Qualified 2026-09-16, the same day.** "Already contained" holds for the *historic* corpus
and was never a statement about the weekly lane, which this plan's denominator excludes by
construction. The first detector built from this pass — the song-loop demotion — immediately
found two publicly visible weekly song clips resting on decode loops (§339 run 910, §348 run
911), which the census could not have seen. Both are now held and demoted. So the second
half of that sentence is the load-bearing one: the historic exposure is smaller than the
table implied, and the case for the remaining rows is prevention on the weekly path rather
than containment of the corpus. A class measured as contained here may be live there.

**Small fixes built test-first 2026-09-15** (operator agreed the eight). Code only: every
re-run named below is still due, and each waits for that run's required fixes and
the §4.0a canary, not every unrelated detector. No hold was cleared.

- *Public sermon query and `asset_disk`* (`bc582626b`). Added to
  `basePublicSermonQuery()` and to the sermon API's select, which had the same gap.
  `Sermon::assetDisk()` now throws `MissingAttributeException` for a persisted row loaded
  without the column. No re-run.
- *Service URL with the archive disabled* (`41abb7445`). `publicUrlFor()` returns null when
  `publicFrom()` is null. No re-run.
- *Whole single-chapter letter* (`235f18595`). The parser collapses "2 John 1-13" to
  "2 John"; a bare book with one chapter now validates and is stored in that form. Due:
  re-run analysis for 957 and 1090.
- *Sermon page reading* (`07be2c070`, fixture `d3214ddb4`). The page names the reading
  inside the run's recorded extraction spans, else the one whose reference overlaps the
  sermon's, else none. It reads the recorded spans because the stored plan audit does not
  keep `bible_section_id`. No re-run.
- *Closing prayer outside the sermon* (`643e5de1f`). When a song is the next separate item,
  `resolveSermonEnd()` runs to its start through unsectioned time and trailing sections,
  under the same ceiling. With no song ahead the 60 s adjacency rule is unchanged. Four
  existing fixtures now end at the song's start. Due: re-plan and re-extract the seven. A
  song section that starts late (the singing-invisible class above) would now carry its
  first sung lines into the sermon, so re-extract those runs after that detection change.
- *Preached reading dropped by an order flag* (`643e5de1f`). Chosen: keep the reading, not
  record a plan risk. A reading held only for a same- or cross-type inversion stays a
  candidate when its reference overlaps the sermon's. Due: re-plan the four.
- *Quality verdict without run evidence* (`85867b2c2`). A command run records the verdict on
  the run that published the sermon, else its livestream or latest run. The thumbnail
  handoff still keys on the pipeline's own run. The 13 past verdicts are not backfilled;
  they are re-assessed under the video-quality row.
- *Song clip audio upsampled* (`5cc597cbf`). `enhanceVideo()` probes the source and passes
  its sample rate, and its bitrate when above 128 kbps; this was checked by a real ffmpeg
  encode. Not built: the post-publication sample-rate probe. Re-publish waits for the
  song-edge and smart-cut changes, as the row says.

**Next pair built test-first 2026-09-15** (operator: these two, committed to master). Code
only, as above.

- *Structure boundary one minute late, cause only* (`6b7696356`). `toPromptText()` shows cue
  times as whole seconds, the unit `start_time` and `end_time` use, and the prompt header
  says so. Not built: the mid-sentence start flag and the screen for a strong cue exactly
  60 s before a section start. Due: re-detect the five through the pipeline, then check
  sermon 1102 and song video 122.
- *Sermon MP3 loses closing words* (`4cc3fb02e`). `ExtractSermon` probes the final video,
  then makes the MP3 from its whole audio track, from 0 to that probed length, for both
  `single_span` and `concat_spans`. No MP3 length comes from the plan any more, and no
  single span is cut twice. The job measures the MP3 and records `trim.audio_duration`.
  When the MP3 and video differ by more than 1 s, every sermon section on the run is held
  with `sermon_audio_length_mismatch`. A clean re-extraction withdraws the hold, and the
  flag does not block auto-extraction. An MP3 the probe cannot read makes no claim either
  way. A zero-length video is now refused before any audio is cut. Due: re-run the 12 and
  clear their holds only on a clean re-measure; decide smart cut first, so each sermon is
  re-extracted once.

**Smart cut built test-first 2026-09-15** (ruling 3a; operator: "build it"). Code only, as
above.

- *Video picture starts after its audio, or audio carries the preceding item*
  (`2fc0931ef`). Every span now goes through a smart cut in `VideoExtractionService`, which
  both sermons and song candidates use.
  - It reads the source's video packets over the span plus 30 s either side, about 1 s
    for a 40-minute sermon.
  - It re-encodes from the start to the first keyframe, and from the last keyframe to the
    end. It copies the exact frame count between them: a copy's `-t` overshoots by the
    B-frames it has reordered.
  - The pieces are joined through MPEG-TS, so each carries its own H.264 parameter sets.
  - The sound is cut on its own and muxed beside the picture: an output-seek copy for AAC,
    otherwise encoded. The re-encode path cuts its sound the same way; a copied audio
    stream under an input seek is what started the sound at the previous keyframe.
  - These are re-encoded whole instead: sources with leading pictures (open GOP), which
    produced 226 undecodable frames in a synthetic join; spans holding no whole GOP; and
    unreadable packets. Every H.264 source probed was closed-GOP: weekly run 919 and the
    historic `.mkv` sources.
  - Where stream measurements are available, cuts are checked the way the censuses
    measured. Picture and sound must start
    within one frame plus one AAC frame, and each run within 0.1 s of the span. That is
    wider than the row's "one frame": a 40-minute weekly cut ran 60 ms long. A smart cut
    that fails falls back to a re-encode; a re-encode that fails throws. Joined sermon
    spans are checked against their parts' measured lengths.
    **Qualification, 16 September:** `cutIsAligned()` permits unavailable video
    measurements and absent audio; `FlagSermonAudioLengthMismatch` makes no new
    claim on an unreadable MP3. These paths are not measured successes. Record
    them as unassessable in canary and acceptance results and resolve or contain
    them before release.
  - Measured on real sources: a 297 s song span from a historic `.mkv` came out 8,923
    frames (8,923.2 expected), started together, with no decode errors, in 5 s. A 40-minute
    weekly sermon came out 2 frames long with no decode errors, in 13 s.
- *`SongVideo.duration`* (`2a0ae93bc`). `SongPublicationHandler` probes the clip it
  publishes and records that length. The section span stands in only when the file cannot
  be measured.
- *Source-frame correction* (`fda414eb2`, later on 15 September). The first smart
  cut could copy its middle from the following GOP while retaining the expected
  length. The fix starts the copy at its own keyframe and counts each piece in
  frames. Regression tests now check actual source-frame numbers, including
  nonzero timestamps/coarse seeking. Earlier duration/decode measurements alone
  did not prove content alignment; reverify the corrected revision in §4.0a.
- Not verified: playback of a TS-joined cut on Safari and iOS, which decode the in-band
  parameter set change. Check one sermon and one song clip on a device before re-running
  the corpus.
- Due: restart the queue workers onto this code, then re-run the affected sermons and song
  clips through the pipeline after the canary. The lyric-edge change is now built;
  affected clips wait for their own identity/boundary repair, not for that code to
  be written again. Each settled clip should be re-encoded once.

**Songs recovered from sustained sound, built test-first 2026-09-15** (rows "Singing invisible
to the transcript" and, for its RMS half, "Song clip loses its own verses"; operator chose each
option below). Code only, as above.

- *Measured first* (`storage/scratch/songwiden-20260915-{measure,features,simulate,probe,replay}`).
  "Loud and wordless" fails: ASR loops and unobservable windows also fall on sermons, prayers
  and notices. Sound level separates the two without the transcript. Across all sections,
  songs are at least 0.84 active and pause at most 6.8 times a minute (10th and 90th
  percentiles). Sermons, prayers, readings and notices are at most 0.81 active and pause at
  least 8.3 times a minute. Every one of 438 sermon sections reads as speech.
- *`SustainedSoundSongSections`*, run after silence snapping in `DetectServiceStructure` and
  `structure:evaluate`. A 5 s bin is sustained when the 30 s around it is at least 0.8 active
  with at most 8 pauses (≥ 0.3 s) a minute. Nothing changes on a concatenated recording.
  - A song section widens across sustained sound that no section holds, when the widening is
    30 s or more. Every widening under 30 s that fresh audio heard as speech was 25 s or less.
  - A widening over 90 s is kept but flagged `structure_song_widened_to_sustained_sound`.
    A short silence between two songs does not survive the window, so a long widening can take
    the next song (985 §1121, 1244 §3037). The real truncations run 40–150 s, so length alone
    cannot tell them apart.
  - When the sound runs from one song into the next, neither widens (1215, 1337).
  - Sustained sound of 45 s or more, after the first section and beside no song, becomes a
    song section "Unidentified singing". It has confidence 0.5, no OoS item, and the flag
    `structure_unidentified_singing`, so it is held for review. Before the first section is
    excluded, because music before a service sounds like an opening song.
- *Decision not to detect from sound: a song running on into a neighbouring section.* Fresh
  audio heard 21 of 38 such corpus cases as speech, and 12 as singing. A leader at a
  microphone is as loud and unbroken as singing. Those clips (240 into prayer §1897, 539 into
  reading §2457) stay with the row's lyric check, which is not built. Song-into-song edges
  (360, 207) and singing before the first section (1195) are also beyond the sound level.
- *Corpus replay of the built service* (stored sections, read-only). 23 widenings, 8 of them
  flagged, and 17 proposals. All 18 in-scope known cases are recovered, including 1341 and the
  four closing songs. Of the 12 widenings not already known, fresh audio heard 10 as singing
  and 1 as music. The last is 985, a second song, which is flagged. Of the 6 proposals not
  already known, 2 are newly found carols (1196 at 1925 s, 1276 at 640 s), 3 are unclear and
  1 is speech (1352, held). Review burden: 25 held sections across 442 runs.
- Not built: real RMS-log fixtures (the logs run to megabytes). The unit tests reproduce each
  corpus shape synthetically and name its case.
- Due: re-detect the named 17-run membership with the later 1196/1276 findings
  deduplicated into it, then re-extract the
  seven closing-prayer sermons and the widened song clips. Clips still wait for the lyric
  boundary corrections; the lyric-half detector below is already built. Do not
  sum overlapping findings or re-encode before a run's boundary work settles.

**Song lyrics outside the clip, built 2026-09-15** (row "Song clip loses its own verses", lyric
half; operator chose to hold all three holder groups). Code only, as above.

- *Measured first* (`storage/scratch/lyricedge-20260915-{features,score,probe,replay}`).
  - The 09-14 bigram screen flagged 248 edges.
  - Sound under the lyric lines separates the labelled cases. All 6 confirmed sung edges are at
    least half sustained; 0 of 11 spoken quotations are. The two doxologies really are sung.
  - What remained were stock phrases ("praise him", "adore him") and announcements. Comparing
    each line with the neighbouring song's own lyrics removes the stock phrases; a narrow
    announcement pattern removes the announcements. Plain counts selected the same 41 edges as
    catalogue-rarity weights, so no catalogue scan is needed.
- *Adjudicated by fresh audio* (41 edges). Inside another song's section: 12 real of 16.
  Unsectioned: 6 of 7. Inside a prayer, reading, welcome or other section: 5 of 14. The misses
  there are announcements and readings over music. Two edges were unclear (1109, 1287).
  - New song-into-song class: ten clips open with their first line inside the previous song's
    section (1154, 1221, 1222, 1224, 1236, 1250, 1270, 1281, 1288, 1199, beside 360 and 207).
  - New edges into prayers or readings: 1195, 1308, 1342. Unsectioned: 960, 985, 1200, 1367.
- *`SongLyricsOutsideSection`*, called from `SongPublicationBoundaryEvidenceService` (evidence
  `version` 2, with observations under `lyric_edges`). It never moves a boundary.
  - It reads transcript lines within 90 s outside the section. A line counts as this song's
    when it shares word pairs with the song's lyrics and is not an announcement.
  - Inside another song's section, the line must share more pairs with this song than with
    that song. A line repeated more than 3 times in the window is a transcription loop and
    counts as no evidence.
  - An edge carrying at least 2 pairs, with at least half its bins sustained, raises risk
    `song_lyrics_outside_section`, so the clip is held. Otherwise the edge is recorded as a
    quotation.
  - `SustainedSound` (`App\Services\Media\Audio`) now holds the one definition of sustained sound
    for both this check and `SustainedSoundSongSections`. `ServiceSection::resolvedSongId()`
    replaces the review policy's private song lookup.
- *Corpus replay of the built check.* It raises 40 holds and records 181 edges as quotations. The
  replay matches the adjudicated set except for two changes made by the loop rule:
  - 1266 §3332 no longer raises. Fresh audio heard a different song under a 37-fold loop.
  - 1287 §3596 is dropped. It was unclear, and that run is already held and due for re-detection.
  - Of the 40: 23 real, 1 unclear, 16 wrong.
- Test-first except the loop rule. Its test was written with the fix, after the replay found
  3332, and was not seen failing.
- Not covered: song-edge lines Whisper never transcribed (clip 192), and verses lost to a
  neighbour without sustained sound under them.
- Due: re-detect the song-into-song runs through the pipeline before re-publishing clips, once
  the widening re-detections above are batched with them.

**Evidence backfill at version 2, applied 2026-09-15** (operator: bank all 881). This also
closes §4.3's stale-verdict item for boundary evidence.

- *Command change* (test-first): `service:backfill-song-boundary-evidence` now also selects
  evidence banked under an earlier `SongPublicationBoundaryEvidenceService::VERSION`. Absent,
  drifted and stale evidence are one membership rule, so the next version bump heals itself.
- *Blast radius before executing* (`storage/scratch/lyricedge-20260915-backfill-delta.json`).
  The default selection held 881 published or pending song sections, all at version 1. The
  command's "would newly hold 488" counts every section with a reason.
  - 38 published clips gain their first hold. 15 are lyric edges; 23 are spoken-framing or
    trailing-content reasons their 09-07 evidence lacked. Their inputs changed after banking,
    most likely in the 09-08 transcript recovery; not traced.
  - 22 pending sections change reasons: 33 lyric kinds added, plus older boundary kinds added
    and removed.
  - 409 pending sections keep their banked reasons, and only `needs_manual_review` flips.
    Pending approval already stopped them publishing, so this adds them to the review queue.
  - 393 have no reasons; 19 were already held.
- *Applied* (`lyricedge-20260915-backfill-execute.txt`). Banked 881; held rose from 52 to 521.
  The check (`lyricedge-20260915-backfill-verify.php`) found every section at version 2, banked
  reasons equal to the dry run, no section released, and a second dry run selecting nothing.
- **Inputs fingerprinted 2026-09-15** (Codex review P3). Bounds and version cannot see the
  23 clips' inputs changing under a banked clearance. Evidence now banks `inputs_fingerprint`
  (transcript and RMS bytes, bounds, the song, the run's other sections, their lyrics, the
  `song_boundary` config), and the command also selects sections whose fingerprint is missing
  or differs, comparing inside each run's staging context. `VERSION` stays 2. Every section
  banked above has no fingerprint, so the **next dry run selects all of them once**; its
  reason delta against today's banking is the real measure of input drift.
  - Dry run the same evening (`storage/scratch/fingerprint-20260915-backfill-dryrun.txt`),
    after the full suite (8183 passed) and Dusk (55 passed): 798 selected, all for a missing
    fingerprint — the 881 less the 83 now `not_applicable`. Its "would newly hold 440" counts
    every section with a reason, not a change.
  - Delta (`fingerprint-20260915-drift-delta.php` → `.json`, read-only, through the backfill's
    own `inspect()`): **798 of 798 reasons and decisions unchanged, 0 would newly need review.**
    No input drift since this morning's banking, as expected within a day. Executing would
    only add fingerprints; no review state or publication would change.
  - *Executed 2026-09-16* (`storage/scratch/fingerprint-20260916-backfill-execute.txt`,
    operator: execute). Banked 798. Its "440 newly hold" counts every section holding a
    reason, not a change: the check after it found **0 published sections held** (so
    `service:demote-held-publications` was not needed), the 440 held are all
    `pending_approval` and were already held this morning, and a second dry run selects
    nothing. 798 sections now carry `inputs_fingerprint` (447 pending, 351 published); the
    452 `not_applicable` sections are outside the default pass and carry none.
- **Demoted 2026-09-15** (operator: run `service:demote-held-publications` for the 38).
  - Dry runs: the 38 named by `--section` were all demotable, none refused.
    `--all` would have reached 83 published sections, 45 outside the 38 (43 held before
    today, and funerals §1588 and §1832, ineligible). They were left alone.
  - Applied (`storage/scratch/demote-20260915-apply-38.txt`): 38 sections moved to
    `not_applicable`, still held. 2 song videos were quarantined; the other 36 were already
    in the historic quarantine. All 38 video rows remain.
  - Two of the 38 were visible on this machine (not production): §320 (run 909) and §434
    (run 917). Their weekly runs have no source on the mounts, so the stored transcripts
    stand in for fresh audio. Both holds are real but small. §320's opening line starts 1.5 s
    before the section (319.4 s). §434's own lines run about 5 s past its end (1728.3 s) into
    §435.
  - **The 43 held from before today were demoted too** (operator, same day). A fresh `--all`
    dry run listed 45: those 43 and the two ineligible funeral sections, which were excluded.
    None was visible or refused.
    - Applied by `--section` (`storage/scratch/demote-20260915-apply-43.json`): 43 moved to
      `not_applicable`, still held. No video needed quarantining; all were already in the
      historic quarantine.
    - Their holds: 39 `content_defect_hold`, 3 `song_identity_contradicted_by_transcript`, and 1
      order-of-service cross-type inversion with a content hold.
  - **The two funeral song sections were demoted too** (operator, same day): §1588 (run 1051)
    and §1832 (run 1098), `handler_ineligible`, neither visible nor refused. They are now
    `not_applicable`; their song videos (211 for §1588, 232 for §1832) remain, and release
    already refuses them because their runs are excluded. A fresh `--all` dry run now finds nothing to demote
    across 355 published sections.

**Title hints resolved against the catalogue, built test-first 2026-09-16** (§4.3's
`title_hint_fuzzy` item; table row "Wrong song from `title_hint_fuzzy`"). Code only, as above.

- *Cause, measured rather than assumed.* `SongLyricsMatchingService::matchTitleHint()` scored a
  *title* against every song's lyrics body, and `bestWindowScore()` returns 1.0 on bare
  containment. A hymn quoted inside another song's verse therefore ties with the hymn the hint
  names, and the row scanned first wins. "Rock Of Ages" (§991, video 137) lost to "O Safe To The
  Rock That Is Higher Than I #887", whose fourth line is "O blessed Rock of ages, I'm hiding in
  you". The named hymn's own catalogue key carries its Praise! number ("rock of ages 705"), so the
  exact-key rung never reached it. A tie at 1.0 also clears the 0.75 write-back threshold, so the
  wrong song was stored `Confirmed` and its catalogue title replaced the heard text — which is how
  these passed review.
- *Fixed through the catalogue's own resolver* (`4c` below): `catalogueTitleMatch()` asks
  `SongTitleResolver`, which already strips the trailing number, indexes alternate titles, and
  drops any key two songs share rather than guessing between them. Only its deterministic rungs
  are taken (exact, praise number, stripped number, loose title, alternate title). `first_line` is
  deliberately excluded, so the existing first-line answer keeps its lower 0.95 confidence; `fuzzy`
  and `hymnbook_absent` are excluded as resemblance, which the lyrics comparison judges better for
  a heard line. A hint naming no catalogued title still falls through to that comparison, which is
  what resolves "The Servant King" (§1284) to "From Heaven You Came #396". The resolver is built
  once per instance.
- *Evidence label:* a catalogue-title resolution records `title_hint_catalogue_title`.
  `title_hint_canonical` (1.0) and `title_hint_first_line` (0.95) are unchanged, and nothing in the
  application branches on the value.
- *Regression cases taken from the corpus with verbatim catalogue lyrics:* "Rock Of Ages" (§991)
  and "God of Glory" (§519, §1288, §2782, §2929, §3024, §3191, §3769), where both the named hymn
  and "Almighty Lord Most High Draw Near #823" contain the phrase and the lower id won. Preserved
  cases: "The Servant King" (§1284), "It Is Well with My Soul" (§928) and "How Great Thou Art"
  (§1588, §3580) — each a lyric line rather than a catalogued title. Full suite 8192 passed,
  PHPStan clean.
- **Corrected 2026-09-16 by the measurement below.** This note first claimed the "Behold Our God"
  bindings could not have come from `matchTitleHint`, because a raw `LIKE '%behold our god%'` finds
  the phrase in song 1047 and not in "All glory be to Christ" (836). That test is
  punctuation-sensitive and the matcher is not: `normalize()` strips punctuation and collapses
  newlines, after which **both** songs' lyrics contain the phrase, and
  `matchFromLyrics('Behold Our God')` returns 836 at confidence 1.0. It is the same containment tie
  as "Rock Of Ages", won by the lower row id — one class, not two. Of the 15 sections carrying that
  hint, 11 were bound by the hint path, 3 by their order-of-service item and 1 is unbound; the
  repair resolves all 15 to 1047 through the alternate-title rung. The general caution still holds
  and is now measured: 12 of the 75 changed sections were bound by their item, not by the hint.

**Re-resolve membership measured 2026-09-16** (`storage/scratch/hintresolve-20260916-blast-radius.php`,
read-only; register `hintresolve-20260916-blast-radius.json`). Nothing was written.

- 1,160 active historic song sections carry a heard title hint. Under the repair they resolve as
  701 catalogue title, 314 canonical, 10 first line, 88 still through the lyrics fallback, and 47
  to nothing.
- **75 sections now name a different song from the one they are bound to.** 26 have a generated
  clip, 2 are published, 38 await approval, and 9 carry no hold. By the path that chose the current
  binding: 62 transcript-and-item agreeing, 12 the order-of-service item alone, 1 the transcript
  alone.
- **72 of the 75 are deterministic catalogue-title resolutions** — the repaired class, covering the
  named regression cases and the recurring wrong bindings (`Behold Our God` ×11, `God of Glory` ×7,
  `I Know That My Redeemer Lives` ×4, `Man of Sorrows` ×3, `Love Divine` ×3, `Bless the Lord, O My
  Soul` ×7, `O Lord My God` ×3).
- **The other 3 come from the unchanged lyrics fallback and must not be re-resolved automatically:**
  §2304 (`This Earth Belongs To God #024b` → `#24b`, a zero-padding duplicate), §2718 (`King of
  Kings` → `Listen! Wisdom Cries Aloud #669`, the very pairing §4.1a r2 recorded as wrong) and
  §2683 (`Jesus Is Lord` → `How Lovely On The Mountains`, where the bound
  `'Jesus Is Lord'—the cry that echoes through creation` is the better answer and two catalogue rows
  open with the hint, so the resolver rightly refuses to choose). These wait on §4.3's lyric
  coverage check and the redefinition of `confirmed`; the fix neither helps nor harms them.
- **Both published changes are unheld:** §4156 (`O Lord My God` → `#190`, a repair) and §2683
  (above, not a repair). Treat the published pair as the first to settle.
- Due: re-resolve the 72 through the pipeline and correct the livestream-sourced order-of-service
  items written from the wrong song; adjudicate the 3 fallback rows individually; settle the 31
  pending-approval sections before anyone approves them. No hold was cleared and no re-run was done.

- [ ] Fill the table's "pipeline item" column with a tested change or a recorded
  decision not to detect, for every row, before §4.5 acceptance.
- [ ] Each promoted detector ships with the corpus cases in this plan as regression
  fixtures (positive and negative), under `tests/Fixtures/StructureEval` or
  beside it, following the existing fixture conventions.
- [ ] Re-run every promoted detector over the current eligible historic membership
  (**437 completed non-excluded runs at this review**) and reconcile its output
  with existing holds; report weekly outputs separately. Preserve older 442-run
  results as dated baselines, not current denominators. Every still-present confirmed defect must be detected or have
  a recorded containment/decision; repaired positive cases remain regression
  fixtures and should not keep firing merely to reproduce a historical hold list.

#### Detector quality and automation benefit

- [ ] For each detector, record a source-adjudicated evaluation of true and false
  positives, false negatives and unassessable cases, with exact denominators and
  uncertainty. Report precision (how often a flag is right), recall (how many known
  defects it catches), and false-positive rate on verified correct cases. Recall
  over the known defect set is not recall over unknown classes.
- [ ] Draw detector-negative and approved examples independently of its alerts and
  review them against source evidence. Include video-quality approvals, correctly
  rejected black/card recordings, valid static-camera footage, legitimate repeated
  lyrics and short talks. A census of flags alone cannot measure missed defects or
  justify treating all non-flags as correct.
- [ ] Separate known-defect regression fixtures, development data used to choose
  thresholds/prompts, and a reserved evaluation set frozen before tuning. Keep the
  same service, alternate encodes, duplicate/rehearsal recordings and their derived
  clips in the same group. Record prior inspection and calibration use; a newly
  sampled old run is not untouched if its answers already influenced development.
  If no suitable untouched historic set remains, use prospectively reserved weekly
  services and describe historic results as retrospective validation. Once a test
  case informs a fix it becomes regression/development evidence for that fix.
- [ ] Before evaluating a candidate, record acceptance thresholds by defect
  severity, tolerances and allowed review burden. Require all applicable confirmed
  regression defects to be prevented or contained, then compare candidate versus
  incumbent on the same reserved cases. Report incorrect flags, missed defects,
  percentage safely processed without intervention, flags per service and operator
  review minutes. Increasing holds alone is not an automation improvement.
- [ ] Break results down by era, codec/channel setup, service kind and availability
  of independent evidence, explicitly including the no-OoS group. Use service or
  source groups for uncertainty; correlated clips from one service are not separate
  independent trials. Keep targeted challenge/repair samples separate from random
  prevalence estimates, or use the recorded sampling weights.
- [ ] Bind results to code, model/prompt, policy and evidence versions. Recheck
  affected metrics when these change, and retain periodic source-reviewed weekly
  spot checks for new recording conditions and new classes. Extend the existing
  evaluator and pipeline tests; do not turn this into a second processing system.

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

- [ ] Give runs 1004, 1143 and 1145 terminal dispositions (repair, exclude with
  reason, or an explicit accepted hold).
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
- [ ] Design how operation 4 reaches `Complete` (§3.1 item 5) now, before
  convergence work depends on it, without manufacturing checkpoint or closeout state.
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
  cases and does not replace §4.3a's reserved evaluation of generalisation; report
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
- [ ] Meet §4.3a's predeclared detector and automation criteria on the reserved
  evaluation set, with subgroup results, unassessable counts and review burden
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
| Processing | GO | Definitive passes drained; three failures remain explicit rather than hidden. |
| Queued repair readiness | **NO-GO** | Both canary blockers are now closed: the silent baseline re-cut (`283a6cd90`) and the detection job's retry of an unplaced-hold refusal (`690ae1d5d`). Merge-case hold persistence is verified (`8d98e45b8`), and 1340's regression is explained and fixed at source — a looping retry over a five-sixths-silent window (`c933889b9`) plus the superseded-transcript fallback (`7d6bbde95`). Still required: **workers restarted onto `7d6bbde95`** (they hold pre-fix code, so no re-run may be dispatched yet); the **device-playback leg**, which no check has covered; and a per-run check of the sermon opening after each re-run. |
| Containment | **NO-GO** | The six disputed sermons and their seven song videos were held on 2026-09-16 and the sections those holds left published were demoted the same hour (§4.4), so the identity gate-clear gap is closed and published-while-held is zero again. Remaining: the current-policy and unassessable residue. Containment is not adoption — the three pairs are still undecided, and the holds are what make deferring them safe. |
| Content acceptance | **NO-GO** | §4.1b's strengthened stopping rule passes: scoped coverage and limitations, omission reconciliation, independent source evidence, content handoffs, controlled variations/interruption tests, tail and whole-output reviews. Every §4.3a class has a tested response or recorded decision; detector errors and review burden meet predeclared criteria on reserved data. The fresh release-membership sample includes repaired/held runs, reports uncertainty and unassessable cases, and meets its separate predeclared limits. Evidence is bound to current artifacts; operator rulings are recorded. |
| Public release | **NO-GO** | Phase 9 convergence, QA and actual-server browser checks pass, then the operator signs an exact era-sized batch. Actual-destination delivery checks are scheduled within the authorised release's rollback window and must pass to close observation. |

<a id="correctness-review-2026-09-10-followup"></a>

### Follow-up correctness review — 2026-09-10/11: missing holds

**Findings recorded 2026-09-11; the corpus evidence below was collected on
2026-09-10, starting 20:43:57 UTC. The repairs materially improved containment,
but some incorrect or policy-restricted outputs still have no review hold.**
This review treats an effective review hold as an acceptable outcome. It does
not require every uncertain item to be repaired before the batch can be assessed.
No processing, publication, release or database changes have been made by this
review. Its findings are audit evidence, not newly applied application flags.

#### Missing holds: confirmed defects and current-policy failures

1. **P8-Q14 remains open for shorter and varying transcript loops.** Fresh local
   re-transcriptions of the actual delivered sermon audio contradict the stored
   repeated text in **#872 (run 929), #943 (1008), #1106 (1187), #1214 (1068) and
   #1242 (1317)**. All five have unheld sermon sections and pass the live historic
   release-review gate. Examples: #872 repeats the invitation to close eyes and
   bow heads four times where the audio continues into prayer; #943 repeats the
   resurrection sentence four times where the audio continues with the preacher's
   explanation; #1106 invents successive `6 Peter`, `7 Peter` and `10 Peter`
   references where the audio contains a reading, application and an introduction
   to Helen Rosevear; #1214 repeats “Let's pray together” eight times where the
   audio proceeds into prayer; #1242 repeats the statement about mourning a loved
   one where the audio proceeds to other examples. These are defects in the saved
   text, **not evidence that the media omits the speech**.

   Runtime screening requires at least five identical repeats **and** 40 repeated
   words; numbers remain distinct. The density fallback requires 400 words/minute
   over 30 seconds. Four repetitions, eight repetitions of four words, and
   number-varying loops can therefore escape. A broader diagnostic found **32
   unheld historic sermon transcripts with short repeated-phrase candidates**;
   five selected examples above were checked against audio and all five failed.
   **32 is a candidate count, not 32 proven defects.** Do not lower the global
   threshold indiscriminately: genuine rhetorical and sung repetition still needs
   distinguishing. Evidence: `correctness-20260910-unheld-short-loops.json` and
   `correctness-20260910-redecode-short-loop-results.json` under `storage/scratch/`,
   with individual WAV and verbose-JSON samples retained.

2. **P8-Q10/P8-Q16: §3869 / run 1304 / SongVideo 371 still combines two songs
   without a hold.** It is assigned to “Jesus Shall Take The Highest Honour”,
   while `additional_song_matches` names “Lord I Lift Your Name On High”. Fresh
   frames from the saved clip show the first song at clip seconds 35 and 135 and
   the second at 240. Current policy returns `unresolved_multiple_songs`, yet
   `needs_manual_review = false` and the historic release-review gate returns no
   objection. The video remains quarantined. Other known mixed clips checked are
   contained: §1276/video 172 is held; §§3627, 1258 and 3265 are held and have no
   generated SongVideo. Frame evidence:
   `storage/scratch/correctness-20260910-mixed-and-framing.png`.

3. **Current song policy finds 28 unheld generated historic clips requiring
   review.** Reassessment of **all 415 published song sections on active historic
   runs**, inside **each run's own historic staging context**, completed with
   **415/415 transcript inputs available, 415/415 RMS inputs available and zero
   errors**. This is not the ambient-disk false positive described earlier in
   P8-Q10. It is reproducible, and the earlier substitution of banked verdicts for
   current-policy reassessment missed real policy drift. One is §3869 above;
   **27 have current boundary-policy objections**. These are policy review
   candidates, not 27 independently proven bad cuts. Exact sections:
   **958, 1168, 1229, 1251, 1696, 1709, 1981, 2402, 2454, 2458, 2498, 2510,
   2834, 2971, 3145, 3300, 3416, 3472, 3519, 3609, 3620, 4745, 3869, 3915,
   3918, 3981, 4275, 4305**. Exact reasons and freshly derived boundary evidence:
   `storage/scratch/correctness-20260910-song-policy.json`.

4. **The boundary policy itself also misses substantial spoken material.**
   §988 / run 974 / SongVideo 135 starts at source 287.007 seconds, during a
   prayer that continues to 345.2 seconds. The song introduction then continues
   to about 362.78 seconds. That is **58 seconds of preceding prayer and roughly
   76 seconds before singing**, inside a 218-second song clip. Frames at clip
   seconds 15 and 50 show the speaker, and a fresh 45-second transcription of
   the saved clip confirms the prayer. The section has no hold, its banked
   boundary verdict is `release_eligible`, **current policy also returns no
   objection**, and the historic release-review gate passes it. This is additional
   to the 28 current-policy failures. A gap-based boundary heuristic cannot certify
   absence of speech when words continue across the prayer-to-song transition.

5. **Known duplicate/date disputes are deferred in prose, not held by identity.**
   Of the three deferred pairs, the release-review gate passes **#1045, #969,
   #1307, #1296 and #1297**. #873 is refused only because of an unrelated
   micro-section hold. This does not reverse the decision to preserve the better
   masters or authorise deletion: it means the pending identity/source-adoption
   decision needs an explicit exclusion or effective hold in Phase 9's exact
   membership. Documentary deferral alone cannot protect those five rows.

#### Repairs and containment confirmed by the fresh census

The active historic scope is **445 runs, 438 linked sermons and 4,190 sections**;
operation 4 is **412 active runs, 409 completed / three failed, 406 linked sermons**.
These counts exclude superseded runs, including the retired duplicate. They are
not directly interchangeable with all-quarantined-row counts: the separate live
inventory contains **442 quarantined sermons and 464 quarantined SongVideos**.
The fresh export includes 463 runs overall; routine/fixture records are excluded
from the historic conclusions.

- All **445 active historic full-service transcripts** were readable. All **1,334
  active historic media paths** probed were readable without probe errors. Header
  probing is not full decoding or full playback. The 19 missing paths in the wider
  1,365-path export belonged to non-historic runs and are outside this conclusion.
- All **29 historic saved-vs-derived sermon-text mismatches** found by the census
  have sermon-section review holds. They are contained, not missing-hold findings.
- The three surviving historic sermon repetition holds remain present. Existing
  continuation screening rediscovers the known missing 33.1 minutes in runs 1073
  and 1268; both are held. No new impossible section bounds were found against
  the recorded positive source durations.
- §§1121, 1254 and 3218 remain labelled published but are held, quarantined and
  refused by release. They are therefore acceptable containment under this review's
  scope, even though the demotion dry run still lists them.
- No quarantined sermon lacks a processing run or owning-run sections; no
  quarantined SongVideo lacks its service section. Potential code gaps for such
  missing relationships were not promoted into current-output defects.

#### Final verification, interpretation and next actions

**Live gate rechecked 2026-09-11 at 09:03:35 UTC after resuming the review.**
All **28 current-policy failures**, plus §988, still have no manual-review hold,
remain quarantined, and return **no objection from the release-review gate**.
The five audio-confirmed corrupt sermon transcripts still pass too, as do the
five deferred duplicate/date rows named above. This is a test of the **content
review gate**, not a claim that an unsigned or otherwise unauthorised release
batch would pass every other release requirement.

The [follow-up register](../../storage/scratch/correctness-20260911-followup-register.json)
retains exact membership, candidate-versus-confirmed classification, the fresh
gate verdicts, and SHA-256 hashes of 30 supporting evidence files. It intentionally
keeps the 32 short-loop candidates separate from the five audio-confirmed defects,
and the 27 boundary-policy signals separate from the frame-confirmed mixed clip.
These populations overlap and must not be added into a claimed defect rate.

Eight targeted local audio re-transcriptions were made in total: the five
short-loop checks, the prayer in §988, and two follow-ups on unobservable sermon
windows. Those latter two do **not** justify claiming new missing sermon audio:
#977's retry still loops and needs a different validation method; #885's sampled
window actually contains the closing hymn “Man of Sorrows”, which the delivered
sermon span includes. The latter warrants checking the sermon/song division,
not counting the window as lost preaching. Unobservable spans for #1248 and #1275
cover included Bible readings rather than their main preaching. All four are
below the deliberately configured 10% evidence-review threshold. That threshold
explains their current lack of holds; it does not certify their content.

**Prioritised follow-up — no automatic repair or flag changes were performed:**

- [ ] **Contain the five audio-proven corrupt transcripts and §3869/§988.**
  Record effective holds through the tested application path, then recover the
  text or correct the cuts. Reconcile the 27 additional current-boundary-policy
  candidates so their live objections cannot be bypassed by old banked verdicts.
- [ ] **Close the short-loop blind spot contextually.** Validate the remaining
  candidates against their audio, distinguish genuine repetition, and add
  regression coverage for four repetitions, fewer than 40 repeated words and
  number-varying loops. Verify both the full-service evidence and saved sermon
  text after recovery; matching two copies of the same corrupt text is not proof.
- [ ] **Make policy refresh reach already-generated songs.** Reassess within each
  owning staging context and bind the result to current inputs. Retaining old
  `release_eligible` metadata is not equivalent to passing current policy. The
  §988 example additionally needs a boundary check that can recognise continuous
  spoken material without relying on a wordless gap.
- [ ] **Bind the three deferred duplicate pairs into Phase 9's membership.**
  Preserve the better masters and the agreed identity decisions; prevent release
  of unsettled rows by an explicit hold or exclusion rather than a prose reminder.

**Conclusion: not all unheld outputs are safe to accept yet.** The earlier
repairs are real and many remaining defects are correctly contained, but the
specific missing holds above keep unattended content acceptance **NO-GO**.
All affected historic assets remain locally quarantined; production was not read.
This pass is not a complete playback or a measured semantic accuracy rate: it
combines a corpus census, every published historic song's current-policy
assessment, media header probes, targeted frames and audio re-transcription.
No application code changed, so code-quality suites were not rerun for this
documentation-only review. The raw census and outputs use the prefix
`storage/scratch/correctness-20260910-`; preserve them with the final register.
