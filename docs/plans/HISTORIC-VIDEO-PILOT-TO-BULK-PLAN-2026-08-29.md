# Historic Video Pilot-to-Bulk Plan

> **Status — 2026-09-12: bulk processing is complete; content acceptance and
> public release are NO-GO.** The 2026-09-10/11 follow-up review found incorrect
> or policy-restricted outputs that still pass the content review gate without an
> effective hold. The review is preserved verbatim below and supersedes every
> earlier implied closure.
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

The latest census in the preserved review reports:

- 445 active historic runs, 438 linked sermons and 4,190 sections;
- operation 4 at 412 active runs: 409 completed and three failed, with 406 linked
  sermons;
- 442 quarantined sermons and 464 quarantined SongVideos in the separate live
  inventory;
- all 445 active historic full-service transcripts readable;
- all 1,334 active historic media paths probed readable, with header probing not
  treated as full decoding or playback;
- all 29 saved-versus-derived sermon-text mismatches held;
- 28 generated song clips failing current policy without holds, plus section 988,
  whose substantial spoken lead-in current policy does not detect;
- five audio-confirmed corrupt sermon transcripts without holds; and
- five rows from three deferred duplicate/date pairs passing the content gate
  without an identity hold or exclusion; and
- three failed operation-4 runs with no terminal disposition: run 1004 (internal
  error at `detect_service_structure`), 1143 (multiple speech blocks met the
  20-minute sermon threshold) and 1145 (no speech block met it).

These figures describe different scopes and overlap. Do not add them together.

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
  almost all short song introductions (the intended rule). Only two are sung
  material typed `other`: §1301 “Lo He Comes With Clouds Descending” (290 s,
  run 1014) and §981 “O Church Arise” (31 s, run 973), and both sermons are held.
  A hymn *inside* the sermon section itself (#885's case) is invisible to section
  queries and belongs in 4.5's held-out validation.
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

### 4.0 Stabilise the plan's references

- [ ] Commit the 2026-09-12 plan condensation and archived execution log so the
  evidence anchors this plan links to are stable.

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
    hold. Re-apply the hold after any re-run.
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

  **No new class.** Both defects belong to classes round 1 already found, so the
  stopping rule is met. Across both rounds, 9 of 60 services had a defect outside the
  known list (95% upper bound 26%). Run 955 produced nothing because both source
  files are silent, although the video shows the service; that is correct.
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

### 4.2 Close the transcript-loop blind spot

- [ ] Validate the remaining short-loop candidates against their audio; distinguish
  genuine rhetoric or singing from corrupt transcription.
- [ ] Add regression coverage for four repetitions, fewer than 40 repeated words,
  and number-varying loops.
- [ ] Verify recovered full-service evidence and saved sermon text independently.
  Agreement between two copies of the same corrupt text is not proof.
- [ ] Detect sparse 30-second-cadence loss (§4.1a): consecutive short cues exactly
  30 s apart inside dense speech. Regression cases §3739 (1112) and §3490 (1278);
  negative cases are "Amen"/"Thank you" cadences over music and 1089's silent source.
- [ ] Add 1347's reading loop ("the Lord" ×16, 32 words) to the short-loop candidates.

### 4.3 Refresh song policy for existing outputs

- [ ] Reassess all already-generated songs within each owning run's recorded
  staging context and bind the verdict to current inputs and policy.
- [ ] Reconcile the 27 boundary-policy candidates in addition to confirmed mixed
  section 3869.
- [ ] Add a boundary check for continuous spoken material that does not depend
  solely on finding a wordless gap, using section 988 as the regression case.
- [ ] Write current-policy objections into `needs_manual_review` (§3.1 item 3), and
  make sure no recompute or backfill clears a hold because an older banked verdict
  said `release_eligible`. The gate itself already reads only the stored column.
- [ ] Add a stale-verdict selection mode to `service:backfill-song-boundary-evidence`
  so existing evidence is re-assessed against current policy and holds are raised
  through its tested write path (§3.2). Measured: the 3-second floor accounts for
  none of the 27 candidates, so it is not a substitute.
- [ ] Use §988 and §1457 as regression cases for the continuous-speech check.
  Add §2897 (spoken benediction tail) as a trailing-speech case.
- [ ] Fix `title_hint_fuzzy` song resolution (§4.1a round 2): a shared title or
  first-line word must not beat an exact or alternate title. Use the 25 held wrong
  clips as regression cases, and the alternate-title pairs as cases that must still
  resolve. Re-resolve affected sections, and correct livestream-sourced order-of-service
  items written from the wrong song.
- [ ] Settle the 31 pending-approval sections with the same hint disagreement before
  anyone approves them.
- [ ] Add a lyric-coverage check (calibrated in §4.1a) before a song match is recorded
  as `confirmed`.

### 4.4 Bind deferred identity disputes

- [ ] Choose the containment mechanism (§3.1 item 4): a hold on each sermon's own
  sermon section (§2009, §1464, §4824, §4598, §4599) through the 4.1 path, or a
  new, tested membership exclusion. `structure_low_confidence` holds already on
  those runs do not count.
- [ ] Put that explicit Phase 9 hold or exclusion on sermons 1045, 969, 1307, 1296
  and 1297, and on their generated song videos where the identity dispute reaches
  them; sermon 873's unrelated micro-section hold is not identity containment.
- [ ] Resolve source adoption for pairs 873/1045, 969/1307 and 1296/1297 without
  deleting the better master or substituting media beneath existing timings.
- [ ] Update exact release membership only after each identity decision is
  evidence-bound.

### 4.5 Prove acceptance, then converge and release

- [ ] Give runs 1004, 1143 and 1145 terminal dispositions (repair, exclude with
  reason, or an explicit accepted hold).
- [ ] Design how operation 4 reaches `Complete` (§3.1 item 5) now, before
  convergence work depends on it, without manufacturing checkpoint or closeout state.
- [ ] Re-run §4.1a's held-out validation on a fresh sample after repairs, as final
  acceptance evidence, across eras and apparently clean cases,
  covering split sermons, partial/composite recordings, corrupt transcripts,
  repeated performances, song identity/count and boundary quality.
- [ ] Regenerate corpus membership and the proposal census.
- [ ] Re-evaluate previously uncorroborated services and disagreements against the
  full-grade video evidence.
- [ ] Resolve surviving proposals by class where a safe deterministic rule exists.
- [ ] Complete editorial QA for titles, slugs, references, series, speakers, songs,
  children's talks and occasions.
- [ ] Audit exact assets, Scripture settlement, quarantine visibility and
  notification containment.
- [ ] Regenerate the hymn-usage apply artifact against the exact converged graph.
- [ ] Generate and verify authoritative Bundle A, optionally split by release era.
- [ ] Complete truth-set checks and public acceptance journeys.
- [ ] Create separately signed, era-sized release authorisations.
- [ ] Run `historic-import:release-batch --dry-run` for each authorised batch.
- [ ] Release only exact authorised membership, observe the rollback window and
  retain the release ledger.
- [ ] At IC8, retire the remaining one-shot historic-import surface and the inert
  cost-accounting residue together.

## 5. Go/no-go

| Gate | State | Required evidence to turn green |
|---|---|---|
| Processing | GO | Definitive passes drained; three failures remain explicit rather than hidden. |
| Containment | **NO-GO** | Every confirmed defect, current-policy objection and deferred identity row is held, excluded or repaired, and the live gate is rechecked. |
| Content acceptance | **NO-GO** | Held-out validation and current-policy reassessment show that unheld exact membership is safe to accept. |
| Public release | **NO-GO** | Phase 9 convergence and QA pass, then the operator signs an exact era-sized batch. |

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
