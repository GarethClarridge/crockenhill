# Historic Video Defect Discovery and Acceptance Plan

> Formerly `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`. Renamed 2026-09-14
> once bulk processing was complete and the remaining work became discovering,
> containing and detecting defects, then proving acceptance.

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
**Last reviewed:** 2026-09-14 — §4.1a's stopping rule withdrawn; §4.1b (coverage
matrix, disagreement censuses, tails, whole-output and rendered checks) and §4.3a
(a pipeline detector per class) added; §4.5 sample frame and regression set widened.

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

- [x] Commit the 2026-09-12 plan condensation and archived execution log so the
  evidence anchors this plan links to are stable. Done in `211c7f10b`.

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

**Method.** Three discovery mechanisms that do not depend on guessing the class in
advance, plus two changes of examiner modality:

1. *Field coverage matrix.* Enumerate every column, file and derived artifact the
   pipeline writes and record its check. Unchecked rows are the unknown-unknown
   inventory.
2. *Disagreement census.* For every field, find a second, independent derivation
   and census the disagreement corpus-wide. Two views of one fact disagree exactly
   where one is wrong, without knowing which class of wrong. This is how every
   wrong song and wrong reference to date was found.
3. *Tail inspection.* For every numeric field, examine the extreme few percent by
   hand. New classes cluster in tails.
4. *Whole-output consumption.* Watch and listen to complete outputs as a
   congregant would, on the rendered pages, not as frames and excerpts.
5. *Consumer-side rendering.* Render the whole quarantined membership through the
   public surfaces. Errors that appear only when data is rendered are a class the
   data census cannot see.

With 442 runs, any check that is cheap to automate runs over the whole corpus.
Sampling is reserved for checks that need a human, and §4.5's fresh sample bounds
those.

#### Field coverage matrix (drafted 2026-09-14 from the schema; keep current)

Status: **corpus** = an instrument has run over every active historic run;
**sample** = checked only in the §4.1a samples (60 services); **none** = never
opened by any instrument. "Second view" names the independent derivation to census
against. Every **none** and every **sample** row needs either a corpus instrument
or a recorded decision that the field is not produced for historic runs.

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
| Sermon `preacher`, `preacher_id`, `preacher_source`, `preacher_confidence`, `needs_preacher_review` | coverage census 2026-09-14 (422/438 `default`) | not produced until retraining (ruling 2026-09-14) | OoS email preacher; retrained speaker model |
| Sermon `series` | — | none | OoS; adjacent weeks' series; empty census |
| Sermon `segment_start_time`, `segment_end_time`, `duration` | duration census 2026-09-14 | corpus | span gaps are by-design concat plans; 902 plan ends past its recording |
| Sermon audio (MP3) content | decode sample (59); duration census and tail transcripts 2026-09-14 | corpus (length) / sample (loudness, clipping) | **defect**: 12 MP3s lose closing words; loudness and clipping still unchecked |
| Sermon video content | decode sample (59 files); header probe of 1,334 paths | corpus (headers) / sample (decode) | A/V sync at start, middle, end; resolution/fps/codec census; `video_quality_status` reason |
| Sermon `transcript_file_path` text | saved-vs-derived census (29 held); loop screen; cadence census | corpus | fresh re-transcription of a random minute; word rate; unobservable windows |
| Sermon `thumbnail_file_path`, `thumbnail_metadata` | coverage census 2026-09-14 | not produced until release (ruling 2026-09-14; 31/438) | after release-time generation: frame inside the span, not black or a slide |
| Sermon `video_quality_status`, `video_quality_reason`, `video_visibility_override` | every rejection adjudicated 2026-09-14 | corpus (rejections) / none (approvals) | **defect**: 27 of 47 rejections hide good video; approvals never sampled |
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
| SongVideo `video_file_path` content | decode sample (50 files); five frames | sample | full decode all 464; A/V sync; loudness; first/last frame not a speaker |
| SongVideo `duration` | duration census 2026-09-14 | corpus | **defect**: copied from the span, never probed; 89 frozen openings, 23 carry the preceding item's audio |
| SongVideo `recorded_date`, `is_featured` | owning-service date census 2026-09-14 (0 differ); date census 2026-09-14 against the source (0 differ); `is_featured` not produced | corpus | funeral hymns (211, 231, 232) excluded with their runs (ruling 2026-09-14) |
| Scripture passage enrichment (`EnrichHistoricScripturePassages`) | Scripture census 2026-09-14: 426 passages' api range and HTML verses equal the reference | corpus | orphan/duplicate passages still unchecked; 7 sermons never linked |
| Hymn usage apply artifact | §4.5 regeneration | pending | song identity census results |
| Search index / embeddings for historic sermons | — | none | index membership equals exact release membership; stale text after transcript repair |
| Public pages, sitemap, podcast feed, structured data | — | none | render every quarantined row locally; 404s, missing thumbnails, duplicate slugs, broken song links |
| Quarantine visibility and notifications | §4.5 audit | pending | — |

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
    therefore not produced yet, not wrong; §4.5 carries the retraining.
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
  is the summary; the JSON is authoritative where they differ.

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
- [x] Sermon audio against sermon video: duration, and an RMS profile correlation
  at three offsets, so a mismatched or mis-cut MP3 cannot hide behind a good video.
  **Done 2026-09-14.** Duration and the tail-loss estimate for all 436 with an MP3
  (above). RMS alignment at five points on 1266, 1128 and 1226 found a constant
  offset equal to each video's picture delay (3.9, 4.5, 4.6 s), so nothing is lost
  or shifted at the join and picture stays in sync after the opening. The picture
  starts after the audio by under 0.5 s in 186 videos, 0.5–1 s in 55, 1–3 s in
  185, 3–6 s in 11, and 8.3 s in 1206 (a camera-disconnected card).
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

- [ ] Shortest and longest 2% of sermons, children's talks and song clips.
- [ ] Largest gaps between consecutive sections; lowest transcript word rate per
  section; highest silence fraction per section.
- [ ] Largest audio-to-video duration delta; smallest bytes-per-second; any
  resolution, frame rate or codec that differs from the modal value.
- [ ] Earliest section start and latest section end relative to source duration.

#### Changes of examiner modality

- [ ] Watch and listen to five complete services' outputs end to end on the
  rendered local pages (sermon page with video and MP3, each song page), drawn
  across eras and including at least one held-then-repaired run. Record A/V sync,
  level, thumbnail, slug, series, title and page layout. This is a human check and
  is not delegable to frames.
- [ ] Render every quarantined sermon and song video through the public routes
  locally (Dusk or Playwright over the exact membership), plus sitemap, podcast
  feed and structured data. Fail on 404, missing thumbnail, duplicate slug,
  broken song link, empty summary rendered as content, or any exception.

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
- [ ] **Stopping rule (replaces round 2's).** Discovery stops when the coverage
  matrix has no **none** row, every cheap instrument has run over all 442 runs with
  its candidates adjudicated, the tails and the whole-output checks are recorded,
  and the operator has ratified the rulings. §4.5's fresh sample then bounds the
  residue of the human-only checks; it is not the discovery mechanism.
- [ ] Sample the held population on the other dimensions: draw 15 held runs and
  run the full checklist plus the matrix rows on them, so that clearing a hold for
  its recorded reason does not release an unexamined row.

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
- [ ] Extend the repetition screen to song sections (§4.1b, the §1216 class). A
  looping song transcript is unusable for lyric coverage and must demote the
  match, not pass silently.

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
- [ ] Redefine `confirmed` as two independent agreeing signals (any two of: exact or
  alternate title hint, lyric coverage, OoS position with an email or OpenLP item).
  One signal, or a looping transcript, yields `inferred` and a review flag. The
  lyric instrument alone is blind to looping transcripts (§4.1a round 2), so it
  cannot be the sole gate.

### 4.3a Put a detector in the pipeline for every class found

Added 2026-09-14. The programme's aim is that future services process correctly,
so containment of the historic corpus is not complete until each class found has
a detector on the weekly path. Flagging for review is sufficient for now; repair
automation is later. Most classes already have a working prototype under
`storage/scratch/residue-2026091*`; promote them into tested application code
rather than leaving them as one-off scripts. Every class found by §4.1a, §4.1b or
§4.5 gets a row here before its holds are cleared.

| Class | Found by | Detector on the pipeline today | Prototype | Pipeline item |
|---|---|---|---|---|
| Sermon transcript loops (≥5 repeats, ≥40 words) | P8-Q14 | yes (`service:screen-transcript-repetition`) | — | extend per §4.2 |
| Short, few-word and number-varying loops | 09-10 review | no | `correctness-20260910-unheld-short-loops` | §4.2 |
| Sparse 30 s-cadence transcript loss | §4.1a r1 | no | `residue-20260913-cadence.py` | §4.2 |
| Reading loop inside sermon text (1347) | §4.1a r1 | no | — | §4.2 |
| Looping song transcript (§1216; 229 song sections, 65 half loop) | §4.1b song-loop census | no (the P8-Q14 screen runs, but no song check reads its blocks) | `songloop-20260914-census.php`, `songloop-20260914-score.php` | **new** (§4.2): record the screen's blocks per song section as a demotion, not a hold (P8-Q14 keeps songs out of the hold); `SongPublicationBoundaryEvidenceService` treats loop blocks like unobservable windows; a song whose section is half loop or more reads as identity-unverified, not `confirmed`, until lyric or OCR evidence supports it; flag a song section whose loop crosses into a neighbouring section, or whose audio is speech under looped sung text (967 §1082, 1268 §4731, 1348 §4390); re-detect 1287 through the pipeline |
| Mixed-song clip (§3869) | P8-Q10/16 | policy returns `unresolved_multiple_songs`, never written to the review column | `correctness-20260910-song-policy` | §4.3 |
| Song clip with continuous spoken lead-in or tail (§988, §1457, §2897, §1475) | 09-10 review; §4.1a | no (gap-based only) | — | §4.3 |
| Wrong song from `title_hint_fuzzy` (25 clips) | §4.1a r2 | no | `residue-20260914-hint-census.php`, `residue-20260914-lyric-identity.py` | §4.3 |
| OoS items written from the wrong song | §4.1a r2 | no | same | §4.3 (re-resolve and rewrite) |
| Sung item typed reading/prayer/other (1253, 1349, 944 §666) | §4.1a r2 | no | `residue-20260914-sung-other.py` | **new**: lyric scorer plus singing-like RMS over non-song sections → retype candidate flag |
| OoS song with no detected section (944) | §4.1a r1 | no | — | **new**: OoS-vs-sections count/order flag (§4.1b census) |
| Talk cut by end of its only source (§1684, §1793, §3943) or starting mid-thought (§1421, 980) | §4.1a r2 | no | `residue-20260913-text-signals.json` | **new**: section within N s of source start/end plus mid-sentence transcript edge → `source_truncates_talk` flag |
| Source audio dropout inside a talk (≥15 s at ≤ −80 dB) | §4.1a r1 | no | `residue-20260913-dropouts.py` | **new**: RMS dropout flag on the section; not repairable, so the flag is the outcome |
| Published title/reference contradicts summary or transcript (881, 954, 844/845/850, 899) | §4.1a r1; §4.1b Scripture census | no | `residue-20260913-references.php`, `scripture-20260914-census.php` | **new**: published-vs-heard reference check and title↔section-title overlap at analysis time (summary overlap alone shares the title's source); adopted rows with null provenance refuse publication, distinguished from the 31 rows created before provenance tracking (2026-08-31), which must not be refused |
| Multi-passage reference cut to its first passage on link (1031, 1159, 1188, 1233) | §4.1b Scripture census | no | `scripture-20260914-register.json` | **new**: link a passage per part (or the envelope) without rewriting `reference` to the first part; flag a linked reference that no longer covers the analysis reference; re-link the four |
| Whole single-chapter letter rejected as a reference (957, 1090) | §4.1b Scripture census | no | same | **new**: accept a single-chapter book as its whole chapter in `validateBibleReference`; re-run analysis for the two |
| Sermon reference never linked to a passage (908–910, 912–915) | §4.1b Scripture census | no (Bundle A refuses at release) | same | **new**: a reconciliation check that every sermon with a parseable reference has a passage or a recorded absence; re-dispatch enrichment for the seven |
| Preached reading dropped from sermon media by an order flag (1075, 1254, 1286, 1299) | §4.1b Scripture census | no | same | **new**: in `selectBibleReading()`, do not exclude a reading held only for an order-of-service flag when its reference matches the sermon, or record the omission as a plan risk; re-plan the four through the pipeline |
| Verse quoted inside a prayer typed as a Bible reading (1043 §1528) | §4.1b Scripture census; operator 2026-09-14 | no | `scripture-20260914-register.json` | **new**: the structure detector keeps a verse quoted within a prayer inside the prayer section, not a `bible_reading` (census: 1043 is the only case of 587 readings); no re-detection is due, because 1043 is excluded as a Saturday rehearsal (date census; ruling 2026-09-14) |
| Structure boundary written one minute late: `m:ss` prompt times converted to seconds (confirmed 1203 §2535, 1183 §2391, 1305 §3894; probable 962 §924, 1141 §2137) | §4.1b Scripture census | no (a slipped time still matches a real cue) | `scripture-20260914-register.json` | **new**: render prompt cue times in the unit the model returns (seconds), or have it return cue indices; flag a section that starts mid-sentence after an unsectioned or foreign-content minute; screen every run for a strong boundary cue exactly 60 s before a section start; re-detect the five through the pipeline and check media that crossed the slipped minute (sermon 1102, song video 122) |
| Sermon page names the service's first reading, not the sermon's (157 of 438) | §4.1b Scripture census | no | `scripture-20260914-page-reading.txt` | **new**: `SermonPageContextService` shows the plan's reading, or the reading matching the reference, else none |
| Saved sermon text predates evidence (P8-Q1) | P8-Q1 | yes (`sermon_text_predates_evidence`) | — | keep |
| Sermon MP3 loses closing words (12; stream-copied video vs plan-cut audio) | §4.1b duration census | no | `duration-20260914-census.json` (`duration − picture delay − MP3 length`) | **new**: in `ExtractSermon`, produce the MP3 from the final sermon video's audio track: the whole track, with no second cut from the source and no length taken from the plan, for both `single_span` and `concat_spans` (this also removes the independent single-span cut behind 1257's loss); flag an MP3 whose length differs from its video's audio track; then re-run the 12 through the pipeline and clear their holds only on a clean re-measure |
| Video picture starts after its audio, or audio carries the preceding item (stream-copy keyframe lead-in): sermons 12 over 3 s; song clips 89 frozen openings and 23 with lead-in audio over 1 s | §4.1b duration censuses | no | `duration-20260914-census.json`, `songdur-20260914-census.json` | **new** (ruling 3a: smart cut): in the shared `VideoExtractionService`, which cuts both sermon pieces and song clips, re-encode only from each cut point to the next keyframe and stream-copy the rest, so every piece starts exactly on its planned time with picture and sound together; check picture delay and length after every extraction (0 within one frame; length equals the span); make the re-encode path meet the same check (its clips still lag 0.2–1.0 s); write `SongVideo.duration` from the probed file, not the section; re-run affected sermons and song clips through the pipeline |
| Hymn inside the sermon section (#885) | 09-10 review | no | — | **new**: lyric scorer over sermon-span windows with singing-like RMS |
| Duplicate/date pair identity | P8-Q7 | no | — | §4.4 |
| Automatic video-quality rejection hides a good video (27 of 47: static camera, dim lighting) | §4.1b matrix | the detector *is* the defect | `vq-20260914-register.json`; ffmpeg `freezedetect` over 4 min separated all 47 | **new**: replace the 1.5 s 16×16 burst with a long-window freeze/black measure, calibrated on the 47; decide whether a rejection needs review before it hides a video; then re-run quality assessment through the pipeline for the 27 wrong rows and 1225, never by hand (operator 2026-09-14), and confirm the 19 correct rejections stay rejected |
| Quality verdict written without run evidence (13, `sermons:assess-video-quality`) | §4.1b matrix | no | — | **new**: the command path records its assessment on the owning run |
| Rehearsal recording imported as its own service (1043, 1089: Saturday sermon-only takes of Sunday's sermon) | §4.1b date census | no | `date-20260914-register.json` (same reference within 7 days, 6-word phrase overlap) | **new**: after analysis, flag a sermon whose reference matches another sermon's within 7 days with transcript overlap over 20% (rehearsals 21–31%, Christmas readings 11–18%, series about 5%), and flag a sermon-only source dated the day before a Sunday service; add an operator exclusion reason for a rehearsal (`HistoricRunExclusion` accepts only `no_sermon_in_source`) that also withdraws the run's Sermon and SongVideo rows; exclude 1043 and 1089 (ruling 2026-09-14) |
| Non-Sunday occasion with no `occasion` (funerals 1051, 1098; holiday club 1144) | §4.1b date census | no | same | **new**: flag a non-Sunday service without `occasion` for review before publication; add an operator exclusion reason for a private occasion, with the same withdrawal of Sermon and SongVideo rows; exclude 1051 and 1098 (ruling 2026-09-14) |
| Closing prayer left out of the sermon when it has no section (7: sermons 1027, 981, 1193, 1299, 1172, 986, 990) | §4.1b section coverage census | no | `sections-20260914-register.json` | **new**: `resolveSermonEnd()` runs the span through unsectioned time to the next song as well as through trailing sections, under the same ceiling; test a sermon → unsectioned prayer → song fixture; re-plan and re-extract the seven through the pipeline |
| Singing invisible to the transcript: song section cut to its transcribed lines (7: 965, 1109, 1196, 1241, 1269, 1341, 1379), songs shifted one slot (1287) or no section at all (10: 963 ×3, 1001, 1034, 1135, 1195, 1231, 1244, 1311) | §4.1b section coverage census | no | `sections-20260914-screen.json` (RMS active ratio), `sections-20260914-sung-probe.sh` | **new**: before or after structure detection, flag any unsectioned span, or song section edge, where the RMS log shows sustained sound and the transcript shows an unobservable window or a "Thank you"/"Amen" loop; widen a song section across such sound up to the neighbouring speech, and propose a song section for a listed song with no section when the span fits; re-detect the 17 runs through the pipeline and listen to 1129, 1233, 1240, 1266, 1276 and 1316 |
| Silent source producing nothing (955) | §4.1a r2 | yes (`SILENT-SOURCE-EXCLUSION` plan) | — | keep |

- [ ] Fill the table's "pipeline item" column with a tested change or a recorded
  decision not to detect, for every row, before §4.5 acceptance.
- [ ] Each promoted detector ships with the corpus cases in this plan as regression
  fixtures (positive and negative), under `tests/Fixtures/StructureEval` or
  beside it, following the existing fixture conventions.
- [ ] Re-run every promoted detector over all 442 runs and reconcile its output
  with the holds already placed by hand, so the hand-placed set is a subset of what
  the pipeline would now flag.

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
- [ ] **Build and apply the occasion exclusions ruled 2026-09-14** (Saturday
  rehearsals 1043, 1089; funerals 1051, 1098). Today nothing can execute them:
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
- [ ] Design how operation 4 reaches `Complete` (§3.1 item 5) now, before
  convergence work depends on it, without manufacturing checkpoint or closeout state.
- [ ] Re-run §4.1a's held-out validation on a fresh sample after repairs, as final
  acceptance evidence, across eras and apparently clean cases,
  covering split sermons, partial/composite recordings, corrupt transcripts,
  repeated performances, song identity/count and boundary quality. The sample
  frame must include repaired and previously held runs, not only the never-named
  population (§4.1a's two samples excluded them), and the checklist must cover
  every row of the §4.1b coverage matrix, not the original seven dimensions.
- [ ] Build a pipeline regression set from operator-reviewed weekly services
  (recent weeks with email, OpenLP and a completed review). Re-run the weekly path
  on them after each §4.2/4.3/4.3a change and measure per-field agreement against
  the reviewed values, so a fix for one class cannot silently regress another
  field. Extend the existing `tests/Fixtures/StructureEval` and
  `TypingBaseline` fixtures rather than starting a new harness.
- [ ] Confirm every §4.3a detector is live on the weekly path before the historic
  release, so the next weekly service is protected by what the corpus taught.
- [ ] Regenerate corpus membership and the proposal census.
- [ ] Re-evaluate previously uncorroborated services and disagreements against the
  full-grade video evidence.
- [ ] Resolve surviving proposals by class where a safe deterministic rule exists.
- [ ] Retrain speaker identification on the historic video corpus (disabled
  deliberately; operator ruling 2026-09-14), then re-attribute the 422 default
  preachers, or accept "Visiting Speaker" explicitly for the release batch.
- [ ] Complete editorial QA for titles, slugs, references, series, speakers, songs,
  children's talks and occasions.
- [ ] Generate thumbnails for the exact release membership only after its editorial
  QA passes (deferred for cost; operator ruling 2026-09-14). The weekly job skips
  unpublished sermons and no release-path code generates them, so this must be an
  explicit step, then checked (frame inside the span, not black or a slide).
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
| Content acceptance | **NO-GO** | The §4.1b coverage matrix has no unchecked row, every corpus census and tail inspection is adjudicated, the operator has ratified the not-defect rulings, every §4.3a class has a pipeline detector or a recorded decision, and a fresh held-out sample drawn from the whole population (including repaired runs) shows that unheld exact membership is safe to accept. |
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
