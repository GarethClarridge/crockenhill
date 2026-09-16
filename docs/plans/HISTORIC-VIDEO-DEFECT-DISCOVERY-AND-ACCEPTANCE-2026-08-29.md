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
**Last reviewed:** 2026-09-14 — discovery strengthened with blind source review,
evidence lineage, content alignment, controlled variations and interruption tests;
coverage and detector acceptance now require measured limitations, false positives
and reserved evaluation data. Actual browser delivery remains separate from the
completed HTTP-kernel census. These additions are planned work, not new measured
corpus defects. Earlier §4.1a/4.1b findings and operator rulings remain evidence.

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
| Sermon `video_quality_status`, `video_quality_reason`, `video_visibility_override` | every rejection adjudicated 2026-09-14 | corpus (rejections) / none (approvals) | **defect**: 27 of 47 rejections hide good video; approvals never sampled. **Resolved 2026-09-16 (§4.3a):** detector rebuilt and the 48 rows re-assessed through the pipeline; every stored verdict now agrees with the adjudication (28 approved, 19 rejected, 1 to review). Approvals remain unsampled |
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
| Retry/replacement generation consistency | archived canary/idempotence evidence | controlled interruption and equal-duration replacement tests pending | clean versus resumed processing; source, plan, media and verdict generation bindings |
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
- [ ] Listen to the original recordings first and record the actual sequence,
  identities, boundaries, interruptions and absent content. Do not show the reviewer
  generated sections, transcripts, labels or warnings until this source inventory
  is saved. Keep uncertainty explicit. Then compare all expected and actual outputs,
  including material for which the pipeline created no section or file.
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

### 4.2 Close the transcript-loop blind spot

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

| Class | Found by | Detector on the pipeline today | Prototype | Pipeline item |
|---|---|---|---|---|
| Sermon transcript loops (≥5 repeats, ≥40 words) | P8-Q14 | yes (`service:screen-transcript-repetition`) | — | extend per §4.2 |
| Short, few-word and number-varying loops | 09-10 review | no | `correctness-20260910-unheld-short-loops` | §4.2 |
| Sparse 30 s-cadence transcript loss | §4.1a r1 | no | `residue-20260913-cadence.py` | §4.2 |
| Reading loop inside sermon text (1347) | §4.1a r1 | no | — | §4.2 |
| Looping song transcript (§1216; 226 song sections, 63 half loop on current membership) | §4.1b song-loop census; **re-anchored and contained 2026-09-16** | **yes, from 2026-09-16** (`SongLoopedTranscript`; before that the P8-Q14 screen ran but no song check read its blocks) | `songloop-20260914-census.php`, `songloop-20260914-score.php`, `sungpass-20260916-measure.php`, `songloop-20260916-adjudicate.php` | **Demotion built test-first 2026-09-16.** `SongLoopedTranscript` reads the screen's blocks from `MediaProcessingLog::recordedTranscriptSuspectBlocks()` — the run's own metadata, not the transcript artifact, so a screen still speaks for a section whose staging file can no longer be reached — clips them to the section, and reports the share they cover. At or above `section_publishing.song_boundary.looped_transcript_minimum_share` (0.5) it raises `song_looped_transcript` through `SongPublicationBoundaryEvidenceService`'s risks into the review policy's reasons, and `SongPublicationHandler::requiresApproval()` returns true on any reason. That is a **demotion, not a content-defect hold**, as P8-Q14 requires for songs: the clip reaches a reviewer instead of publishing itself. Null blocks are *unknown* and are recorded without demoting — every run completed before the screen existed carries none, and reading that as clearance would pass exactly the corpus the screen was built for; an empty list is the positive claim that the transcript was read and found clear. **`VERSION` 2 → 3**, which is the half that reaches the corpus: banked evidence is now stale, so `service:backfill-song-boundary-evidence` re-selects and re-assesses every song section rather than the rule applying only to sections assessed from now on. Deliberately *not* built: a risk on a loop crossing the section edge — `crosses_section` is measured and recorded, but nothing has measured that class and a rule ahead of its measurement is the error this programme keeps finding. Gates: 121 targeted tests (demotion end-to-end, the new service, the handler, and the backfill under the version bump), PHPStan clean over 968 files, Pint clean. **Gap discount built test-first 2026-09-16**, the second half of the same row. A wordless gap is evidence about a song's edges only because the cues around it record what was said; inside a loop they do not, so `gapIsLooped()` now discounts such a gap exactly where `gapIsUnobservable()` already does, at both the leading and trailing edge. The blocks travel in `loadInputs()` — which is defined as everything an assessment reads — so no observation signature changed, and they come from run metadata, so they are still available when the transcript artifact cannot be reached. A discount is the *absence* of a risk, so it is recorded in the evidence a reviewer reads (`start_evidence`/`end_evidence` basis `looped_gap`) rather than among the reasons that withhold a clip, matching `unobservable_gap`. Each edge has a **control test** proving the fixture genuinely raises `song_boundary_spoken_framing` and `song_boundary_trailing_content` first, so neither discount test can pass because nothing was there to discount. **`VERSION` 3 → 4**: this changes what banked evidence *says* without changing what it was read *from*, which the inputs fingerprint cannot see, so the version is the only thing that makes those rows stale — and **a second backfill pass was therefore run** (operator instruction, 2026-09-16; `backfill2-20260916-gate-before.json` / `-after.json`), because the earlier pass banked version 3 under the pre-discount rule and some of its sections were held for framing a loop invented. It re-banked all 796 at version 4 (version 3 now 0) and removed **33 boundary risks** — `spoken_framing` 204 → 201, `..._exceeds_limit` 182 → 167, `trailing_content` 98 → 83. **It released nothing, and could not have**: `SongBoundaryEvidenceBackfill::writeSection()` sets `needs_manual_review = true` when reasons exist and has no else branch, so a hold is only ever raised, never lowered. Flagged stayed at 462 and publicly published song videos at 37, exactly as before. The visible consequence is that **7 sections now carry evidence reading `decision: release_eligible` with no risks, their `song_publication_review` reasons unset, and `needs_manual_review` still true** — held with nothing recorded saying why: §1392 (1028), §1924 (1111), §2069 (1130), §2483 (1198), §3097 (1248), §3310 (1264), §3486 (1278), all `pending_approval`, so none is publicly visible. These are precisely the clips the loop invented a framing risk for, and releasing them is an operator act: `ConfirmServiceSection` is the only writer of `needs_manual_review = false` in the codebase, which is the review queue working as designed rather than a gap. Six further in-scope sections stay at version 1 and are **correctly** excluded — `ServiceSection::scopeNotSuperseded()` drops any section whose run is superseded (1189 → 893, 1249 → 1248, 1378 → 890), and refreshing evidence for a withdrawn run's clips would bank evidence for clips that no longer stand for anything. Note in passing that §3111, §3119, §4608 and §4618 are `published` on superseded runs; that is a pre-existing question this row does not answer. Gates: 94 section-publication tests, PHPStan clean over 968 files, Pint clean. **The 20–50% band was then measured, and the line replaced rather than moved** (operator asked whether to lower it, 2026-09-16; `songloop-20260916-band.php` → `-band.json`, read-only). Adjudicating all 85 sections between a fifth and a half by the same lyrics test: **59 `loop_defect` (69%)**, 10 legitimate repetition, 5 mixed, 11 unjudgeable for want of catalogue lyrics, 0 read errors. Against 80% above the line, **the share barely discriminates** — a threshold that hardly changes the defect rate as it is crossed is a number drawn across a continuum, and it is wrong in both directions: §1862 loops at 0.51 repeating a phrase nowhere in its song, while §3750 loops well past the line singing its own chorus and was named a genuine chorus by the 09-14 census. Lowering the line to 0.2 would have held all 85, including those 10 choruses and 11 unjudgeable, which is the "increasing holds alone is not an automation improvement" trap this plan already warns against. **So the phrase decides and the share became the fallback** (`VERSION` 4 → 5, built test-first): `SongLoopedTranscript` resolves the bound song's lyrics, normalises phrase and lyrics alike to words, and raises the risk when any repetition block repeats text the song does not contain; where the song carries no catalogue lyrics — 11 of the 85, a blind spot the share does not have — the share still decides, so a thin catalogue cannot make a section read as clean. The observation records which `basis` decided it, and `inputs()` now fingerprints the lyrics, so editing a song's words makes banked evidence stale rather than leaving a cleared clip cleared. **14 of the band's defects were unheld and in scope, and were held** (operator instruction; shares 0.22–0.48; `songloop-20260916-band-gate-before.json` / `-after.json`): gate before 0 refusals, after **14 refusals**, with **0 publicly visible throughout** — every clip was already quarantined, so nothing changed in public exposure. Their recorded reason names the phrase rather than the share, because that is what condemns them. **The 14 were then demoted** (operator instruction, 2026-09-16; `songloop-20260916-band-gate-demote-before.json` / `-demote-after.json`), because a row claiming `published` while held with a quarantined clip is a contradiction every "published sections" census walks into — the demote pass alone assesses 353 such rows. The objection worth testing first was whether demotion hides a section from review, and it does not: `ServiceReviewDashboardQuery`'s base predicate is `notSuperseded()` AND (`needs_manual_review` OR `pending_approval` OR structural uncertainty), and demotion leaves the first disjunct set, so the section stays in the queue exactly as before. It is also the established practice here — 38 held published sections were demoted on 09-15 with only 2 of them visible, and a further 43 the same day with none visible or refused. Gate before and after are identical where it matters: 14 sections, 14 content holds, 14 refusals, **0 publicly visible throughout**; all 14 now read `not_applicable` with their clips quarantined, publicly published song clips stayed at 36, and no publicly visible section anywhere carries a loop reason. The caveat stands that these holds rest on a lyrics comparison rather than on anyone listening. **§2738 (run 1222) and §2916 (run 1235)** — the two `mixed` sections the hand-holds missed — were held by the third pass and now sit in the same `published`-with-quarantined-clip state; they were left alone, being outside the instruction, and are the obvious next candidates if that state is to be cleared. Note that the rule change releases nothing by itself: the backfill only ever raises a hold, so sections held above the line for repeating their own chorus stay held until someone confirms them in the review queue. **The band under a fifth was then measured too** (`songloop-20260916-lowband.php` → `-lowband.json`, read-only), because removing the share as the gate made a population relevant that had never been looked at: while the demotion depended on coverage, a section looping under 0.2 could not withhold a clip. All 78, 0 errors: **54 `loop_defect` (69%)**, 13 legitimate repetition, 10 unjudgeable, 1 mixed. **Three bands now read 80% / 69% / 69%** — near-identical defect rates across the whole range, which is the measurement that retires the share as a signal rather than merely demoting it. The extreme case is §1577, whose loop covers **0.7%** of the section and whose phrase appears nowhere in its bound song: no threshold could have found it. **A correction to the hand-holds above**: the 14 should have been 16. That band's exposure filter counted only pure `loop_defect`, but the rule raises the risk on any absent phrase, so the two unheld in-scope `mixed` sections (§2738 run 1222, §2916 run 1235) belonged in it — a filter written for the old rule and reused after the rule changed. **Third pass run 2026-09-16** (operator instruction; `backfill3-20260916-gate-before.json` / `-after.json`): all 796 re-banked at version 5, flagged **476 → 502**, and this pass *did* move the queue, unlike the second. Six of the seven sections the second pass left held with no recorded reason regained one; only §2069 still carries a hold with nothing explaining it. **It also caught what no measurement here could.** §351 (run 911, **weekly**, 2025-04-13) was `published` with **video 559 publicly visible**, bound `confirmed` to a song whose lyrics do not contain the phrase its transcript repeats 34 times. This plan's 437-run register is historic-only by construction, so §351 was invisible to every census run today, exactly as §339 and §348 were this morning — the rule found it because it no longer depends on a measurement anyone had taken. Held by the pass and **demoted on operator instruction** (`songloop-20260916-s351-gate-before.json` / `-after.json`): `published` and visible before, `not_applicable` with the clip quarantined after; publicly published song clips fell **37 → 36**, and **no publicly visible section anywhere now carries a loop reason**. Three further weekly sections name it (§366, §381, §409), all awaiting approval with no clip generated. **Still to build**: a song half loop or more reading as identity-unverified until independent performance evidence meets §4.3's confirmation rule, OCR or another ASR pass not sufficing; the flag for a loop whose audio is speech under looped sung text (967 §1082, 1268 §4731, 1348 §4390); and re-detecting 1287 through the pipeline. **Re-anchored 2026-09-16** to the 437-run denominator (the five excluded runs removed): 226 song sections carry blocks, 63 at half loop or more, 89 with a generated clip — the 09-14 figures of 229/65 differ only by the excluded runs. **25 of the 63 were unheld**, 22 with a clip, every one bound `confirmed`. **Adjudicated before holding** (operator ruling 2026-09-16, `songloop-20260916-adjudication.json`) by the 09-14 census's own test — whether the looped phrase occurs in the *bound song's* lyrics, since a chorus legitimately repeats text the song contains: **20 `loop_defect`** (no block's phrase appears in the bound lyrics; repeats run to ×96 on "for the lord i will st" in §1862, ×89 in §2309, ×81 in §3321), **1 `legitimate_repetition`** (§3750, run 1154 — independently reproducing one of the six genuine choruses the 09-14 census named, so the test returns a known-good answer rather than flagging everything), **2 `mixed`** (§1308, §4501: one block in the lyrics, the rest not) and **2 `unadjudicable`** (§2439, §2755: the bound song holds no `lyrics_plain`). **The 20 were held** through `service:hold-section-content --execute` with the reason and evidence recorded; the other 5 were deliberately left alone and still need a decision. Gate before (`songloop-20260916-gate-before.json`): 20 sections, **0 content holds, 0 refusals** — all 19 clips releasable. Gate after (`-gate-after.json`): **20 content holds, 19 refusals**, each "song video N is held for review: section M awaits manual review"; §3321 has no clip and is held on its section alone. **The other five were held too, by operator ruling 2026-09-16**, in three classes with separate recorded reasons so the register does not later read as one measured verdict: §3750 is held **despite adjudicating as legitimate repetition** — its looped phrase occurs in the bound song's own lyrics, so this is a ruling, not a detection, and it should not be cited as a defect the screen found; §1308 and §4501 are `mixed`, where one block's phrase is in the lyrics and the rest are not, so the song repeating does not account for the loop; §2439 and §2755 are unjudgeable, their bound songs carrying no catalogue lyrics to compare against. Residue gate before (`songloop-20260916-residue-gate-before.json`): 5 sections, 0 content holds, 0 refusals. After (`-after.json`): 5 content holds and **3 refusals** (song videos 176, 271, 431); §2439 and §2755 have no clip and are held on their sections alone. All 25 of the unheld half-loop sections are therefore now contained. The holds are containment and the detector is the rule: these 25 sections are its first regression cases, and the backfill under `VERSION` 3 is what applies it to the rest of the corpus. **The backfill dry run then found the class on the weekly lane** (`service:backfill-song-boundary-evidence`, read-only, 2026-09-16). It selects 798 sections as missing or stale under version 3 and reports "would newly hold 464" — which is *not* a delta: the count is `$reasons !== []`, and 462 of the 804 in-scope song sections were already flagged, so the rule's own contribution is two sections. `song_looped_transcript` is named on 51, of which 48 are the registered half-loop sections in scope and **all 48 were already held**. The other two were invisible to this plan by construction: **§339 (run 910) and §348 (run 911) are `livestream` runs**, so neither sits in the 437-run historic denominator. Both were `published` with no review flag and both song videos (557, 558) were publicly visible. Adjudicated by the same lyrics test (`songloop-20260916-weekly-gate-before.json`): §339 repeats "we look forward to the lord" 65 times plus two further blocks, §348 repeats "come and out of glory god of heaven we h…" 26 times, and **not one phrase occurs in its bound song's lyrics** — decode loops, not choruses. **Held and demoted 2026-09-16** (operator ruling), by `service:hold-section-content --execute` then `service:demote-held-publications --section=339 --section=348 --apply`, scoped by section so no unruled row could be caught. Gate before: 2 sections, 0 refusals, both visible. After (`-weekly-gate-after.json`): both `not_applicable`, both held, **both videos quarantined, 2 refusals**; publicly published song videos fell from 39 to 37. Demotion is not deletion — every file and row is intact behind the hold. **The exposure figure is a measurement, not a floor**: of the 342 unheld in-scope song sections, none is unscreened (145 screened clean, 197 carrying blocks), so the rule has genuinely spoken for all of them. The finding that matters beyond these two rows is that **this class is not historic-only** — a detector built for the corpus found its first live defects on the weekly path, which is the argument for building the remaining rows as prevention rather than containment |
| Mixed-song clip (§3869) | P8-Q10/16 | policy returns `unresolved_multiple_songs`, never written to the review column | `correctness-20260910-song-policy` | §4.3 |
| Song clip with continuous spoken lead-in or tail (§988, §1457, §2897, §1475) | 09-10 review; §4.1a | no (gap-based only) | — | §4.3 |
| Wrong song from `title_hint_fuzzy` (25 clips) | §4.1a r2 | no | `residue-20260914-hint-census.php`, `residue-20260914-lyric-identity.py` | §4.3 |
| OoS items written from the wrong song | §4.1a r2 | no | same | §4.3 (re-resolve and rewrite) |
| Sung item typed reading/prayer/other (~~1253, 1349, 944 §666~~; **1014 §1301 only**) | §4.1a r2, **corrected 2026-09-16** | no | `residue-20260914-sung-other.py`, `sungpass-20260916-measure.php` | **Measured 2026-09-16 over all 437 runs** (`sungpass-20260916-measure.json`, read-only). **Four of the five named sections are not sung items.** The prototype scored lyrics alone; adding the sound instrument (`SustainedSound` at the 09-15 thresholds) and word rate separates them, and each of the four is a different thing: §4717 (1253) is a *spoken* congregational reading of Psalm 46 colliding with the metrical psalm "God Is Our Refuge And Our Strength #046A" (0.578 active, 21.4 pauses/min, 107 wpm; its order-of-service item is typed `bibles`); §4406 (1349) is two hymn verses **read aloud as a prayer** — the speaker says "let's make these verses our prayer" (0.640/21.8, 102 wpm; OoS item typed `custom`); §666 (944) is an **ASR loop** ("we're going to sing again" ×22), which the repetition screen already blocks at 950–974, so it belongs to the song-loop row; §981 (973) is the hymn's **announcement**, and the hymn was never sung on the recording — the audio ends at 3132 s with no sustained span after 3000 s. Only **§1301 (1014) "Lo He Comes With Clouds Descending"** is genuinely sung (0.929 active, 1.2 pauses/min, 44 wpm), and the sermon media span 0–1501 includes it. Corpus-wide only 10 non-song sections carry a catalogue hymn title, all typed `other` and none held; nine run at 52–398 wpm and are announcements or readings. **So the class has one member in 437 runs.** Design consequence: a lyric scorer over non-song sections is not the instrument — spoken hymn and psalm text is normal here by design — and an AND of lyric and sound thresholds matched *nothing* (`both_count` 0). Sound-led with a lyric floor finds §1301 and excludes the nine wordless music/pre-service-audio sections; record that the lyric floor only works where ASR heard the singing, which is exactly what the "singing invisible to the transcript" row says often fails. **Built test-first 2026-09-16, in two stages, because neither stage alone holds what the rule needs.** `MistypedSungSections` runs at structure detection, after the sustained-sound stage has settled the song sections — so a span still typed as something else is one no song claimed — and flags a welcome, prayer, notices, reading or `other` section of 45 s or more that is at least 0.8 sustained and runs under 60 words a minute, as `structure_section_reads_as_sung`. It needed the transcript, which the sound path did not carry; `DetectServiceStructure` and `StructureEvaluateCommand` both already had one in scope at the call, so it is passed in rather than re-loaded. **The lyric floor this row originally proposed was not built, and should not be**: it only works where ASR heard the singing, which is exactly what fails for invisible singing. The absorption test does that filtering better and for free — measured 2026-09-16, 13 sections read as sung across 437 services and **exactly one is absorbed into a sermon's span**, §1301; the other twelve are pre-service music, opening audio, closing blessings, welcomes and a promotional video, none of them absorbed. So `SermonExtractionPlanResolver::resolveSermonEnd()` raises `sermon_absorbed_sung_item` when an absorbed section carries the flag, placed with the other risk checks and *before* the ceiling block that can clear `$absorbed`, or it would never fire on a ceiling-capped span. **The flag never reaches a sermon, song or children's talk**: `SermonAutoExtractionPolicy` permits automatic extraction only when every flag on the chosen section is registered as non-disqualifying, so an unregistered flag on a sermon would quietly stop it extracting — there is a test pinning that, painting the sermon's own span as unbroken sound so only the type exclusion stands in the way. Gates: 6 unit tests, 41 resolver tests, 27 job tests, PHPStan clean. **Due**: the flag is written at detection, so no existing run carries it — §1301 gains it only when run 1014 is re-detected through the pipeline |
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
| Hymn inside the sermon section (#885) | 09-10 review | no | `sungpass-20260916-measure.php` | **Measured 2026-09-16 over all 437 runs** (`sungpass-20260916-measure.json`). #885's hymn is located: sermon 885 belongs to **run 949**, and its closing hymn sits at **4635–4795 s** inside sermon §723 (2744–4827) — wholly within the unobservable window 4615.16–4797.16 (`retranscription_failed`), so the transcript holds **no** lyrics for it and a lyric scorer sees nothing. The sound instrument finds it exactly: 1.000 active, 0.0 pauses/min, against 0.952/3.4 for the same run's song §718 and 0.683/17.9 for its own sermon body. **Sustained sound alone is not the detector**: sustained spans of 30 s or more inside a sermon and held by no song section number **272 across 150 runs**, nearly all ordinary preaching at 104–181 wpm. Adding word rate collapses it to **2 spans**, and the collapse is stable from <20 to <60 wpm rather than balanced on a tuned edge. The two survivors are run 949's hymn (0 cues, 100% unobservable, at the sermon's end) and run 1014 §1300's "Thank you ×4" ASR artefact, **already held**. **new**: flag a sustained-sound span of 30 s or more inside a sermon section, held by no song section, whose word rate is under 40 wpm; two candidates per 437 runs is the whole review burden. Regression cases: run 949 §723 (positive), run 1014 §1300 (positive, ASR artefact), and any preaching span at 104–181 wpm as negatives |
| Duplicate/date pair identity | P8-Q7 | no | — | §4.4 |
| Automatic video-quality rejection hides a good video (27 of 47: static camera, dim lighting) | §4.1b matrix | the detector *is* the defect | `vq-20260914-register.json`; ffmpeg `freezedetect` over 4 min separated all 47 | **Built 2026-09-16.** `VideoDeadPictureProbe` measures freeze and black time with ffmpeg's own detectors over 6 windows of 30 s spread from the recording's first second to its last; `SermonVideoQualityAssessmentService` reads the *coverage* of that dead time and nothing else — the 16×16 fingerprint burst, the brightness floor and the GD frame scoring are deleted. **Coverage decides how far a verdict may go:** dead in three quarters of the windows or more rejects (and hides); any lesser dead time is `partially_frozen`/`partially_black` for review, because a recording that carries real preaching for part of its length is not the detector's to withhold. Calibration over all 48 rejections, running the application service (`vq-20260916-detector-check.json`, `vq-20260916-windows.json`): **48 of 48 agree with the 2026-09-14 adjudication** — the 19 correct rejections stay rejected at 6/6 dead windows, the 28 wrong ones approve at 0/6, and 1225 reads 2/6 → needs review (operator: SHOW it). **Re-assessed through the pipeline 2026-09-16** (operator approval), by `sermons:assess-video-quality --all --reason=frozen_frames` then `--reason=mostly_black`, sequential and in-process — never `--queue`, whose workers hold stale code — and never by hand. All 48 stored verdicts now agree with the adjudication (`vq-20260916-reassessment.json`): 28 approved, 19 rejected, 1225 to review; no sermon outside the register was touched and all 48 remain quarantined, so nothing changed in public exposure. The 19 rejections also split 12 `mostly_black` / 7 `frozen_frames`, matching the 09-14 count of 12 black recordings and 7 holding cards that the old detector labelled `mostly_black` alike. **Approvals censused 2026-09-16** (`vq-20260916-approvals-census.json`, read-only): 432 approved videos, 429 measured — the 3 unmeasurable are published rows whose local clips do not exist, their sources intact on `/mnt/cbc-services`. 421 agree; **8 flagged for review, 0 newly rejected** (1.86% review burden). Adjudicated against contact sheets, black extents and audio levels (`vq-20260916-approvals-adjudication.json`): **7 are real defects the old detector missed** — four black openings of 52–177 s (926, 930, 941, 975), a 106 s loss of picture mid-sermon under continuing speech (1276), and the camera-fault card in 1189 and 1230 — and **one is a false positive** (867, a projected Philippians 2 slide held 21 s; the only flag on a published row), a rate of 1 in 429. The seven remain stored as `approved` until re-assessed through the pipeline. Thresholds must not be tuned against this set. Note the census cannot see a video both detectors approve wrongly; that stays with §4.5's reserved human sample. **The seven defects were re-assessed through the pipeline 2026-09-16** and now read `needs_review` — `partially_black` for 926, 930, 975 and 1276, `partially_frozen` for 941, 1189 and 1230. 867 was deliberately left `approved`: a held slide is not a broken recording, and flipping it by hand would bury the one measured false positive. Two rows with video (897, 1005) have never been assessed at all and sit outside this census, which covered approvals only |
| A discredited verdict survives where the evidence cannot be re-read (862, **published**) | §4.3a approvals census 2026-09-16 | the guard is deliberate: a settled verdict outranks an unreadable file | `laravel.log` 2026-09-16 07:40:57 | **new**: sermon 862 (published, 2023-09-03) is hidden from its public page by the old detector's `frozen_frames` verdict of 2026-07-09 — the class that proved 25 of 27 wrong. It was matched by the re-assessment pass, but this machine holds no clip for it, so `AssessSermonVideoQuality`'s settled-verdict guard held rather than overwrite a verdict with `missing_video_file`, and the row kept the discredited answer. The guard is right in itself; the gap is that nothing distinguishes a verdict worth keeping from one whose detector has since been replaced. Re-assess 862 where its bytes live (production), or re-derive the clip from the surviving source (`/mnt/cbc-services/2023-09-03/Morning/Sunday 3rd September 2023 [YouTube backup].mp4`, 2.96 GB, 4356 s). **Measured 2026-09-16: the rejection is demonstrably false.** The sermon's span in that source (2066–4107 s) shows no freeze and no black in any of 6 windows of 30 s, and frames at 2100, 3000 and 4000 s show the preacher at the lectern in three different postures — the static-camera class the old detector misread 25 times in 27 (`vq-20260916-candidates/862-source.jpg`). What is missing is not evidence but a machine that can write the corrected verdict where the bytes live. Consider recording the detector's identity alongside a verdict, so a superseded verdict can be found rather than inferred |
| Sermon video opens with no picture (926: 85 s, 930: 85 s, 975: 52 s, 941: 177 s), or loses it mid-sermon (1276: 106 s of black from 1682 s while speech continues at −2.6 dB) | §4.3a approvals census 2026-09-16 | the rebuilt detector flags it for review; nothing prevents it | `vq-20260916-approvals-adjudication.json`, contact sheets in `vq-20260916-candidates/` | **new**: the clip begins before there is any picture, so the cause sits upstream of the detector and is not yet established — determine for each whether the black is in the source or introduced by the planned span, by comparing the section's start against the source's first picture. If the span is at fault, check picture start against audio start at extraction (the measurement ruling 3a's smart cut already added) and re-plan or refuse a sermon whose opening carries no picture; if the source is black, it is a recording dropout like the RMS dropout class and the flag is the outcome. 1276's loss is mid-recording, so it is a dropout regardless of the cut. Re-extract or re-plan the five through the pipeline once the cause is known; the camera-fault cards in the same census (1189, 1230) need no new response, being the partial form of the whole-recording cards the detector already rejects |
| Quality verdict written without run evidence (13, `sermons:assess-video-quality`) | §4.1b matrix | yes (`AssessSermonVideoQuality::owningRun()`) | — | **Closed 2026-09-16.** The job already resolves the run that published the sermon when it is dispatched with a sermon id alone, and writes the verdict there. Verified on live data rather than from the test: each of the seven command-path assessments run on 2026-09-16 wrote status, reason and window counts to its owning run, and all 13 verdicts that originally left evidence only in `laravel.log` (runs 1044–1113) now carry current evidence on the run. No assessed row is left without it |
| Rehearsal recording imported as its own service (1043, 1089: Saturday sermon-only takes of Sunday's sermon) | §4.1b date census | no | `date-20260914-register.json` (same reference within 7 days, 6-word phrase overlap) | **new**: after analysis, flag a sermon whose reference matches another sermon's within 7 days with transcript overlap over 20% (rehearsals 21–31%, Christmas readings 11–18%, series about 5%), and flag a sermon-only source dated the day before a Sunday service; add an operator exclusion reason for a rehearsal (`HistoricRunExclusion` accepts only `no_sermon_in_source`) that also withdraws the run's Sermon and SongVideo rows; exclude 1043 and 1089 (ruling 2026-09-14) |
| Non-Sunday occasion with no `occasion` (funerals 1051, 1098; holiday club 1144) | §4.1b date census | no | same | **new**: flag a non-Sunday service without `occasion` for review before publication; add an operator exclusion reason for a private occasion, with the same withdrawal of Sermon and SongVideo rows; exclude 1051 and 1098 (ruling 2026-09-14) |
| Closing prayer left out of the sermon when it has no section (7: sermons 1027, 981, 1193, 1299, 1172, 986, 990) | §4.1b section coverage census | no | `sections-20260914-register.json` | **new**: `resolveSermonEnd()` runs the span through unsectioned time to the next song as well as through trailing sections, under the same ceiling; test a sermon → unsectioned prayer → song fixture; re-plan and re-extract the seven through the pipeline |
| Singing invisible to the transcript: song section cut to its transcribed lines (7: 965, 1109, 1196, 1241, 1269, 1341, 1379), songs shifted one slot (1287) or no section at all (10: 963 ×3, 1001, 1034, 1135, 1195, 1231, 1244, 1311) | §4.1b section coverage census | no | `sections-20260914-screen.json` (RMS active ratio), `sections-20260914-sung-probe.sh` | **new**: before or after structure detection, flag any unsectioned span, or song section edge, where the RMS log shows sustained sound and the transcript shows an unobservable window or a "Thank you"/"Amen" loop; widen a song section across such sound up to the neighbouring speech, and propose a song section for a listed song with no section when the span fits; re-detect the 17 runs through the pipeline and listen to 1129, 1233, 1240, 1266, 1276 and 1316 |
| Song clip loses its own verses to a neighbouring section (114, 192, 543 unsectioned; 240 prayer; 539 reading; 360, 207 preceding song, so 206 carries the next song) | §4.1b tail inspections (song-edge census) | no (clip duration is checked only against its own span) | `songedge-20260914-census.php`, `songedge-20260914-probe.php`, `tails-20260914-register.json` | **new**: at publication, score transcript cues within 90 s outside each song section against the bound song's lyrics (ignoring announcement lines and title words) and flag a match for review; since the transcript misses much singing, also flag an edge where RMS shows sustained sound running into an unsectioned span or a non-song section; widen the section to the singing and re-extract through the pipeline; re-detect 960, 1034, 1035, 1049, 1108, 1196 and 1291 |
| Duplicate OoS item binds a clip to the previous song (1337 §4275, video 410: #699 listed twice, hint "Shine Your Light" matched `confirmed`) | §4.1b tail inspections | no | same | **new**: a section whose hint does not match its bound song's title or lyrics cannot be `confirmed` to that song; flag consecutive items with the same `song_id` (joins the OoS census duplicate-item class); re-resolve 1337 |
| Song clip audio upsampled to 96 kHz and re-encoded at 128 kbps (245 of 464) | §4.1b tail inspections (format census) | no | `tails-20260914-fingerprint.php` | **new**: in `AudioEnhancementService::enhanceVideo()` pass `-ar` equal to the input's sample rate (the `loudnorm` 192 kHz output), and a bitrate no lower than the source's; probe every clip after publication for sample rate equal to the source fingerprint; re-publish clips through the pipeline once the song-edge and smart-cut changes land, so each is re-encoded once |
| Song section with no song in it, above the 15 s micro floor (song videos 74 → §622, announcement only; 83 → §678, doxology as a second copy of the hymn) | §4.1b tail inspections | no (held only by generic review) | `tails-20260914-register.json`, `sungpass-20260916-measure.php` | **Measured 2026-09-16** (`sungpass-20260916-measure.json`). 42 song sections run under 60 s across the 437 runs, of which **39 are already held** — only §625 (938), §2851 (1231) and §3368 (1269) are not, so the practical exposure is three sections, not the class. 25 match their bound song's lyrics, 17 match nothing. **The doxology rule cannot work from lyrics: `matches_previous` is 0 corpus-wide, because no catalogue song contains "creatures here below" at all.** So the question this row asked is answered — the doxology is *absent* from the catalogue, not mis-attributed — and the choice is to add it as its own item or to detect the case without lyrics (a short song section following another song section, whose text matches no catalogue song). §678's best lyric match was a spurious "For All the Saints" at 3 bigrams, so any lyric rule here also needs a floor. §622 separates cleanly as announcement-only: 8 cues, coverage 0.08, sustained 0.00, while its announcement names the bound title (overlap 1.00). **new**: flag a song section under 60 s whose text matches no catalogue song, distinguishing an announcement (names the bound title, speech-rate) from a fragment; decide the doxology's catalogue status |
| Public sermon query resolves media through config, not the row's `asset_disk` (listings, browse, service lists, podcast feed) | §4.1b consumer-side rendering | no | `render-20260914-feeds.php` | **new**: add `asset_disk` to `SermonRepository::basePublicSermonQuery()` and fail loudly when it is not loaded, as `isWholeContentPublic()` does for `publication_state`; test a feed item whose `asset_disk` differs from `SERMON_STORAGE_DISK` |
| Quarantined sermon records a media file that does not exist (857 MP3) | §4.1b consumer-side rendering | no | `render-20260914-register.json` | **new**: before release, check every recorded asset path exists on its `asset_disk` and refuse the row otherwise; trace 857 |
| Service URL offered while the service archive is disabled (null `public_from`) | §4.1b consumer-side rendering | no | same | **new**: `publicUrlFor()` returns null when `publicFrom()` is null, matching `applyDateEligibility()` |
| Silent source producing nothing (955) | §4.1a r2 | yes (`SILENT-SOURCE-EXCLUSION` plan) | — | keep |

**Audit of the `new` markers, 2026-09-16.** The table overstates what is left by
about half. Of the 29 rows still marked `**new**`, **14 are already built**, checked
against the code rather than against commit subjects: the single-chapter reference
(`chaptersInBook($passage) === 1` in `SermonAnalysisValidator`); the preached reading
held only for an order flag (`isPreachedReadingHeldOnlyForOrder()` inside
`selectBibleReading()`); prompt cue times in seconds; the sermon page's own reading;
the MP3 taken from the final video's whole audio track; the smart cut, with a song
clip's length probed rather than copied from its section; the rehearsal and
private-occasion exclusion reasons (`EXCLUSION_REASON_REHEARSAL_DUPLICATE`,
`EXCLUSION_REASON_PRIVATE_OCCASION`); the sermon span running through unsectioned
time to the next song (`$runsToNextSong` in `resolveSermonEnd()`); song recovery from
sustained sound; the hold for lyrics sung outside a section; `enhanceVideo()` passing
`-ar` from the probed input rate; `asset_disk` in `basePublicSermonQuery()`; and no
service URL while the archive is disabled.

**Two are partial.** The talk cut by the end of its source has
`FlagSectionTruncatedBySource`, but it is raised only by the offline
`ScreenSectionSourceBounds` command, and neither the mid-sentence transcript edge nor
the talk *starting* mid-thought is detected. The duplicate OoS item has the adjacent
same-song flag in `SongPublicationReviewPolicy`, but nothing refuses `confirmed` when
a section's hint matches neither its bound song's title nor its lyrics.

**Nine are genuinely unbuilt** (built test-first on 2026-09-16: the song-loop demotion
with its gap discount, and the sung-item detector with its absorbed-into-the-sermon risk; the
speech-under-looped-text flag remains): an OoS song with no detected section, the audio-dropout flag, the
published title or reference contradicting what was heard, multi-passage linking
(`ScriptureReferenceResolver::normalize()` still returns `$passages[0]`, which is the
defect itself), references never linked to a passage, a verse quoted in prayer typed
as a reading, a hymn inside the sermon section, an announcement-only song section,
and the check that a recorded asset path exists before release. Two more rows were
opened by the 2026-09-16 approvals census and are open by construction.

Each remaining row should be re-checked this way before anyone builds it, and rows
that share evidence should be batched: the song-loop, sung-item, hymn-in-sermon and
announcement-only rows all want one transcript-and-RMS pass over the same corpus.

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
re-run named below is still due, and each waits so a run goes through the pipeline once,
after its last fix. No hold was cleared.

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
  - Every cut is measured the way the censuses measured. Picture and sound must start
    within one frame plus one AAC frame, and each run within 0.1 s of the span. That is
    wider than the row's "one frame": a 40-minute weekly cut ran 60 ms long. A smart cut
    that fails falls back to a re-encode; a re-encode that fails throws. Joined sermon
    spans are checked against their parts' measured lengths.
  - Measured on real sources: a 297 s song span from a historic `.mkv` came out 8,923
    frames (8,923.2 expected), started together, with no decode errors, in 5 s. A 40-minute
    weekly sermon came out 2 frames long with no decode errors, in 13 s.
- *`SongVideo.duration`* (`2a0ae93bc`). `SongPublicationHandler` probes the clip it
  publishes and records that length. The section span stands in only when the file cannot
  be measured.
- Not verified: playback of a TS-joined cut on Safari and iOS, which decode the in-band
  parameter set change. Check one sermon and one song clip on a device before re-running
  the corpus.
- Due: restart the queue workers onto this code, then re-run the affected sermons and song
  clips through the pipeline. Song clips still wait for the song-edge change, so each is
  re-encoded once.

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
- Due: re-detect the 17 runs through the pipeline (plus 1196 and 1276), then re-extract the
  seven closing-prayer sermons and the widened song clips. Clips still wait for the lyric
  half of the song-edge row, so each is re-encoded once.

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
- [ ] Re-run every promoted detector over all 442 runs and reconcile its output
  with the holds already placed by hand, using current eligible membership if the
  baseline changes. Every still-present confirmed defect must be detected or have
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
- [x] **Build and apply the occasion exclusions ruled 2026-09-14** (Saturday
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
- [ ] Confirm every §4.3a detector is live on the weekly path before the historic
  release, so the next weekly service is protected by what the corpus taught.
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
| Containment | **NO-GO** | Every confirmed defect, current-policy objection and deferred identity row is held, excluded or repaired, and the live gate is rechecked. |
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
