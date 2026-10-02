# Historic Video Defect Discovery: Execution Log, 2026-09-24 to 2026-10-02

> Moved **verbatim** out of the historic plans when they were redrafted on 2026-10-02, before the
> final canary (canary 10). It is evidence, not current status: where a passage says "next",
> "pending", "uncommitted" or "not yet", read the current plan first. Headings are nested one level
> deeper than they stood; nothing else changed. Live rulings from these passages were carried into
> the plan's §4.0 "Standing decisions".
>
> - [Current plan](../plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) ·
>   [ensemble design and rule register](../plans/HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md) ·
>   [detection reliability work package (archived, complete)](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md)
> - Earlier: [2026-09-12 to 09-23](HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md) ·
>   [phases 0–8](HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md)

## Plan header and status blocks as they stood on 2026-10-01

## Historic Video Defect Discovery and Acceptance Plan

> **Latest update — 2026-09-28, after Codex review:** canary 8 on `3ffe4b54c` failed: 949
> produced four spurious talks after validation retry. Tier C and corpus dispatch remain on HOLD;
> the operational freeze is unchanged by the plan commits. The
> [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md) now sequences
> the reviewed [ensemble and deterministic review-loop design](../plans/HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md).
> Next: preserve replayable evidence, implement extraction-aware consensus and finish
> answer → correction → deterministic replay before increasing reprocessing volume. Local rulings
> must correct output; recurring patterns become general rules only after measured corpus replay
> and counterexample tests. These are plans, not implemented ensemble behaviour.
> 2026-09-29: delivery is split — canary 9 needs the ensemble, evidence banking and evaluation; the
> review loop must be finished before batch 1. The canary bar (Q5) is now zero unflagged talk-count
> errors with every dispute answered, adopted before canary 9. Targeted review remains the operator's chosen workload, with no reinstated full-service
> audits. This update supersedes older next-action summaries below. Custody, extraction and release
> controls remain in force; documentation commits authorise no paid calls, dispatch or publication.

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
> The [Historic Import: Incremental Convergence Plan](../plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
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

## Plan §4.0 as it stood on 2026-10-01: decisions, route builds, canaries 1–8, freezes 1–10, batch 1

#### 4.0 Corpus re-run — operator decisions 2026-09-23

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
  *Superseded 2026-10-01 (operator): Tier A only transcribes.* So that Tier A can run early, off
  the critical path, without a later rule commit discarding its detections (and the operator's
  answers to their questions), `startTranscriptionRound()` stops before detection: RMS log and
  audio timeline reused (the recording is unchanged; re-measuring cost ≈170 s of the one ffmpeg
  worker per run, ≈8 h over 170 runs), Whisper afresh, then `RecordCorpusRerunTranscription`
  stamps `detection: none` with the new text's hash and the worker's commit. The run then joins
  a Tier B round on the same snapshot or a later one. On the same snapshot `CorpusRerunGuard`
  lets through exactly the recorded text (and the step the run ended on), refuses an unfinished
  round or one stale workers ran, and Tier B takes the run whatever its spent grounds say. Tier A
  refuses a second transcription on a commit; Tier C refuses a run whose latest round on the
  commit only transcribed; a transcription stamp never changes the previous detection round's
  deferred media in the snapshot or diff.
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
     *Frozen on `cf6839b6b`* (local tag `historic-rerun-freeze-20260928b`; `.env` keeps
     `SERVICE_STRUCTURE_MODEL=gpt-5.6-luna`); workers restarted 16:27:53 BST, 11 s after the commit,
     queues empty. Snapshot `freeze-20260928b/membership.json`: same 437 members (`8fe5add7…`),
     routing `2fc8903c…` bound (166). Batch 1 dry runs: Tier B 16 ready, Tier A 0. **The canary is
     NOT dispatched** (operator stopped for a fresh session; this paragraph is uncommitted, as
     every commit now invalidates the snapshot). **Next:** preflight (queues, worker ELAPSED
     against `cf6839b6b`, mounts, free space), then `historic-import:rerun-redetect
     freeze-20260928b/membership.json <batch1-runs.txt> --max=16 --execute`; on completion
     `rerun-diff`, then dump the 16 runs' sections in the harness's draw format and score them
     with `storage/scratch/detection-score-20260928.py`. **Pass: 0 talk-count errors** against
     `detection-truth-20260928.json`, no hold dropped or cleared, and the truth set as before.
     Then Tier C for batch 1, then era batches, each scored the same way where the key covers it.
     *Superseded before dispatch by step 10.*
  10. ***Freeze moved again 2026-09-28 evening*** after a Codex review of the pipeline. The
     operator ruled that every fix goes in before the canary ("moving the freeze is trivial;
     redoing the canary later is more effort"). Committed with this entry:
     - `9837c8ef6` coverage counts shared speech once (union of spans; max corpus overlap 18.8 s,
       no verdict changes);
     - `83891029a` the missing-reading retry is adopted only when its short talks match the
       original's within 30 s, and a nearby reading counts only when it overlaps the sermon's
       `sermon_reference` (14 corpus runs now retry: carol services, and 1311 in batch 1);
     - `7445ec57c` a micro-section holds a sermon's release only when no song separates it
       from a sermon, talk or reading (no change on current stored state: 276/443 clear);
     - `84248c3b9` the prompt lets same-type items sung out of printed order keep their
       bindings. Five draws: 0 talk-count errors, 8/16 runs stable (the current prompt: 2/16).
       Codex's second prompt change, grounding music sections in the sound classification, was
       **rejected**: 0.2 errors/draw alone, 0.9 combined with the first.

     **Canary bar strengthened** (`detection-score-20260928.py`): PASS = every run scored,
     0 talk-count errors, every talk span and sermon start on a ruled span; ADJUDICATE = spans
     off a ruled span (each needs an operator disposition); FAIL = an unscored run or any
     talk-count error. A talk cut short was invisible to the old count. **Operator span rulings
     (truth file):** a prayer before the sermon *about the sermon*, by the preacher or someone
     praying for the preacher, may be included; a talk's closing prayer on its theme may be
     included or left out (964, 1025, as 936); 1250's passage announcement is preferred but
     optional; 1221's One-to-One talk is best ending at 1600, and running to 1671 with the
     book-voucher notice is acceptable; 1112 may end before the hymn introduction (1250
     precedent). 1028's sermon absorbing a 30 s music outro is not covered: a genuine error.
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


## Ensemble plan header as it stood on 2026-10-02

## Ensemble structure detection

**Status — 2026-09-28 (after Codex review): REVISED IMPLEMENTATION PLAN; not implemented.**
The operator requested that the review and deterministic-review-loop recommendations be incorporated.
The pre-revision plan is preserved in commit `2bb569482`. These are documentation commits, not a
new processing freeze: the freeze (`3ffe4b54c`), canary-8 FAIL and dispatch HOLD remain unchanged.
The operator's follow-up decisions of 2026-09-29 (§0, items 7–11) split delivery around canary 9
and settle Q5, ER1's scope, rule adoption and job retry. Items 12–13 (2026-09-29/30) hold filler, song
and reading timings to agreement only where they change a cut.

This plan belongs to the [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md)
(§0 records today's rulings and measurements). This is the design authority for its revised
DR1–DR6 delivery sequence and replaces its DR2 retry repair once implemented and verified.
It is a change to the **routine** detection pipeline. Historic and weekly runs behave identically,
following the standing ruling that historic work improves routine processing and gets no special path.

## Ensemble plan §1–§2: evidence and the code it changed (2026-09-28)

### 1. Evidence

The measurements below were reported from saved read-only draws on the 16 batch-1 services.
Truth is `storage/scratch/detection-truth-20260928.json`; the scorer is
`storage/scratch/detection-score-20260928.py`. Prompt labels p2, p3, p4 and p5order name the prompt
revisions evaluated on 2026-09-28; p5order is the current prompt.

**Evidence limits found in review:** the truth file mixes operator rulings with entries explicitly
labelled `consensus` (provisional, not verified). Agreement with the latter measures regression
stability, not independent accuracy. The current scorer covers talks and sermon starts, not all
song/reading identities, boundaries or extraction outcomes. §6 extends it before accepting results.
Repeated draws on these tuned services test variability, not unseen-service accuracy. Retain the
operator's targeted-question approach; do not silently reinstate whole-service markup or the
superseded held-out/clean-audit workload from the reliability plan.

- **Canary 8, run 949.** The rejected first attempt nested one prayer inside another. The stored
  summary reads: "Section 13 (prayer, starts 1661.7s) lies almost entirely inside the previous
  section (prayer, ends 2003.0s)". Blind whole-service regeneration then typed four church updates as
  talks. Ten read-only first attempts of 949 produced **9 clean, 1 with two different false talks**.
  These probes suggest unstable first-attempt errors on this example. The rejected original
  response was not recovered in full; its summary does not establish the classification of every
  span later called a talk. Do not claim that every false talk was caused by retry feedback.
- **Error capture by a second opinion.** This counts the primary's talk and sermon errors that
  land on a disagreement.

  | Primary + opinions (prompt) | Errors caught |
  |---|---|
  | 5.6 + 5.6 (p4, 24 talk errors) | 21 (88%) |
  | 5.6 + 5.6 (p2) | 54% |
  | 5.6 + gpt-6 (p2) | 65% |
  | 5.6 + 5.6 + gpt-6 + gpt-6 (p2) | 75% |
  | any combination (p3) | 25% |

  p3's remaining errors were shared by every draw of both models, so they are systematic and
  invisible to any ensemble.
- **Flag volume** (share of services with any output-relevant disagreement). Talks are compared
  on both edges ±30 s, the sermon on its start ±30 s, songs and readings on presence (≥50% overlap).
  - Current prompt, 5.6 + 5.6: **24.4%** (320 draw pairs). Of the disagreeing claims, sermons
    are 42%, talks 27%, songs and readings 15% each.
  - p2/p3, 5.6 + 5.6: 31–33%. 2 × 5.6 + 2 × gpt-6 with any disagreement: 66–69%. With a
    majority rule (the written claim outvoted): 39–40%.
  - Many sermon disagreements are a preacher-prayer choice the operator has ruled either way:
    949 at 2706 or 2744 s, 1025 at 1984 or 2020 s. Hence decision 5.
- **What the disagreements are** (p3, talks). With a second 5.6 draw, 44% of the second draw's
  differing talks were themselves acceptable spans. With gpt-6, 20% were acceptable and 28% had
  wrong edges on a real talk. gpt-6 disagrees more, and more of its disagreements are its own
  errors. The operator judged this acceptable: the aim is a different opinion, and review answers
  become rules.
- **Cost and latency per call** (saved usage, local price snapshot): 5.6-luna costs $0.0054–0.0056
  with a median of 55–57 s and p95 of 68–80 s. gpt-6-luna costs $0.0034, median 40 s, p95 56 s.
  **Four draws cost about $0.018 per service, ≈$8 for the 437-run corpus.** Run in parallel,
  wall time is about the slowest draw, roughly 1–1.5 minutes.

### 2. Current code this plan changes

Verified 2026-09-28 against the uncommitted tree on top of `3ffe4b54c`. Re-verify before coding.

- `app/Jobs/DetectServiceStructure.php`
  - `detectWithPrimaryRecovery()`: one detection, then one feedback retry when
    `detectionWorthRetrying()` matches `timestamps_outside_recording`, `non_chronological`,
    `multiple_sermons` or `incompatible_oos_item`, then `recheckMissingPreachedReading()`.
  - **Uncommitted today:** `detectionRetryFeedback()` lists the rejected structure for a repair,
    and `sectionsDisagreeingOnTalks()` / `withRetryTalkChangesFlagged()` raise
    `ServiceStructureValidator::FLAG_RETRY_CHANGED_TALKS`, also registered in `DetectorCatalogue`.
    Four regression tests exist in `DetectServiceStructureTest`; §4 preserves their behaviours
    while replacing the retry implementation.
  - `detectAndValidate()` loads the transcript, audio timeline and attested OoS, then runs the
    detector, `snapToSilences()` and `ServiceStructureValidator::validate()`.
  - `runPrimary()` persists through `ServiceSectionSyncService::sync()`, which keys rows by
    `section_order`. It records `service_structure` metadata and rechecks content holds. In
    reconcile mode a failed re-detection keeps the existing sections.
  - `runShadow()` / `detectShadowCandidate()` implement the `shadow` mode with
    `service_structure.shadow_model`. That is a model-upgrade evaluation path that runs *instead of*
    primary. Deletion is deferred under §9/Q6.
  - `public int $timeout = 900; public int $tries = 3; backoff [120, 300, 600]`.
- `app/Services/ChurchService/Structure/OpenAiServiceStructureService.php` reads the model from
  `config('media-processing.service_structure.model')` at call time. It sends `service_tier` through
  the flex fallback.
- `app/Support/OpenAiChatPayload::isReasoningModel()` is `/^(gpt-5|o[1-9])/i`. **gpt-6 does not
  match**, so a gpt-6 request keeps `temperature` (rejected) and never carries
  `reasoning_effort`. The scratch draw driver patches this per process. Production must fix it.
- `SectionReviewFlagPolicy` forces review for any flag it does not demote.
  `SermonAutoExtractionPolicy::NON_DISQUALIFYING_REVIEW_FLAGS` lists the flags that do not block
  auto-extraction. Every `ServiceStructureValidator::FLAG_*` must be catalogued in `DetectorCatalogue`
  (`DetectorCatalogueTest`).
- Laravel 13's process concurrency driver uses separate `php artisan` children. Its default process
  timeout is 60 s and `Concurrency::run()` propagates a failed child/timeout instead of returning
  sibling successes plus an unavailable vote. A bare call does not meet §3.2's contract.
- `recheckMissingPreachedReading()` returns the entire new structure when a reading is recovered
  and talks approximately match. It can replace agreed songs, sermon boundaries, references and
  flags; preserving its talk guard alone does not preserve ensemble authority.
- `SermonExtractionPlanResolver` uses the next song's start, trailing prayer/reading/other sections,
  and reading references/bounds. Notices can stop extension. It can omit a flagged reading while
  still extracting the sermon; a flag on a song does not automatically hold the sermon using it.
  `ExtractSermon::concludeWithoutSermon()` skips extraction based on the absence assertion.
- `SoundStage`, `SectionStructureFlagRederiver` and `ContentHoldRechecker` already provide shared
  deterministic processing. Extend these established responsibilities rather than build a parallel
  historic-only framework. Existing generic re-annotation has no ensemble evidence reader.
- Scratch spot-checks bank a transcript hash and anchor, but `select.php::$alreadyRuled` compares
  only run, question kind and approximate times. It suppresses questions without verifying that
  hash or applying the correction. `ConfirmServiceSection::apply()` clears all review flags;
  answering one new spot question must not invoke that blanket clearing behaviour.


## Ensemble plan §5–§7: tests, the §6 evaluation and canary 9 (2026-09-28 to 2026-10-01)

### 5. Tests (written first, failing, then green)

- Four agreeing voters produce coherent supported boundary pairs with provenance. No new dispute.
- One voter invents talks (949 shape): majority writes no talk. The spot is flagged, with the voter's
  version kept; a dropped claim in an uncovered gap still reaches review.
- A 2–2 split uses the specified model/slot tie-break, flagged. Both tie-break-model slots
  disagreeing, absent tie-break model, reordered completion and reordered claim arrays are covered.
- Non-transitive 100/125/150 s starts, overlapping clusters, long-reading/two-reading matches,
  type conflicts, absence votes, splits and merges cannot manufacture consensus or duplicate votes.
- A voter failing validation (nested prayers, timestamps beyond the recording) does not vote. No retry is made.
- Fewer than two valid voters → manual review with all draws persisted. In reconcile mode the
  existing sections are retained.
- Two/three valid voters, including same-model survivors, produce explicitly degraded review
  proposals and cannot silently obtain the full-ensemble unattended gate.
- ER1: sermon starts differing only by a span one voter types as prayer are agreed. A span any
  voter types as song or reading is disputed. Two starts on either side of a prayer select a
  complete supported alternative, never its midpoint or an internal silence.
- Different references, identities, OoS bindings, continuation/absence decisions and consequential
  filler are disputes. Free-text paraphrases do not invent semantic disagreement. Confidence and
  existing flags follow the explicit policy; unrelated holds survive every composition/replay.
- Validate real extraction outcomes: disputed next-song start, omitted reading, notice versus
  prayer tail, no song after sermon, sermon-end movement and disputed absence. Review reaches the
  actual extraction/publication gates, including baseline fallback, not just a flag-string test.
- Real subprocess integration tests: one timeout, provider error, abrupt exit, sibling results
  retained, parent cancellation/cleanup, staging mismatch and artifact identity. Fake or synchronous
  drivers alone cannot prove these. Test flex fallback and whole-stage deadlines.
- A whole-job retry runs a fresh ensemble and keeps the previous bundle; no invalid-draw
  replacement within an ensemble, stale input reuse or stale-revision overwrite. Record
  interrupted/unknown usage honestly.
- Missing-reading diagnostic cannot overwrite ensemble sections/flags; no default fifth call.
- `isReasoningModel()` covers gpt-6.
- Flag policy and catalogue cover disagreement/degradation; missing or stale banked evidence cannot
  clear a flag. Rule re-derivation recomputes it from matching evidence and preserves operator holds.
- Ruling tests: source/hash match, stale evidence, ambiguous mapping, conflicting/superseded answers,
  scoped resolution, cannot-tell/deferred, presence-before-edge deduplication and omitted claims.
- End-to-end review test: answer changes the output, survives new section rows and deterministic
  replay, updates only dependent plans, does not ask the same resolved question again, and leaves
  unrelated holds intact. Dusk covers browser interactions; Playwright remains visual-only.
- Replay twice and compare semantic output: no drift, repeated recut or reopened questions.
  Rule fixtures include counterexamples and known-correct decisions that must remain correct.
- Evaluation parity and read-only tests: invoke the same production services; no authoritative
  section/segment/hold/publication/freeze mutation. Scorer fixtures cover all claim types, missing/
  duplicate membership, provisional truth, refused/degraded results and extraction dependencies.

Run focused PHPUnit tests, PHPStan, Pint and the full parallel suite through Sail; retain suite
output. Run Dusk for the review interactions. This document revision itself changes no code.

### 6. Evaluation before canary 9

First run zero-provider-cost replay and deterministic tests against saved draws/rulings. Fix the
composer, scorer and review loop before buying fresh evidence. Freeze their versions and the input
manifest for evaluation; log any subsequent change as a new candidate rather than mixing results.

Fresh detection evaluation remains read-only and predeclared (≈$0.02 per four-draw sequence):

| Set | Draws | Purpose |
|---|---|---|
| 16 batch-1 services | 3 complete ensemble sequences each (48 × 4 calls ≈ $0.9) | Composed-output accuracy against ruled truth; provisional regression stability reported separately; flag rate; latency |
| 949 | 10 ensemble sequences (≈$0.2) | The canary-8 case: the false talks must never be written unflagged. They should be out-voted. |

**Built 2026-09-30:** `structure:ensemble-evaluate {manifest} --detector=openai` runs this table from
`storage/scratch/ensemble-eval-20260930/manifest.json` (caps 280 calls / $3.00, $0.025 worst-case
reserve per call checked before each sequence). First run stopped at 15/58 ($0.39) on the original
rule (two sequences in a row losing any draw) after OpenAI HTTP 520s; operator ruled 2026-09-30 to
resume (`--resume`, same manifest and inputs, spend carried) with the rule narrowed to two
sequences in a row left under three valid votes by lost draws. The cut is judged by its sermon
section only: it runs on to the next song by ruling, which the sermon-only truth cannot label.
Inputs come from the job's own builder; draws run four at a time in separate processes and are
kept whole; the cut is planned by the production resolver on a rolled-back copy of the run
(`SermonCutProbe`) and scored for wrong, unflagged and unreviewed-to-extraction cuts, plus each
service's cut spread across sequences. Without `--detector` it only builds inputs and prints the plan.

Extend the DR1 scorer before using it for acceptance. Do not infer whole-output correctness from
the existing talk/start score or a review flag alone. Bind expected membership, input hashes,
truth basis, code/prompt/rule versions, draw counts, maximum calls/spend and stop conditions.
The table plans 232 base calls (~$1.1); predeclare any transport/queue retry allowance separately
and account for actual tiers. No automatic reading-recheck allowance. Report:

- talk-count errors in the **composed** output, flagged and unflagged;
- boundary, reference, song identity, OoS binding and sermon-absence errors or unadjudicated claims;
- actual extraction-plan differences and whether erroneous outcomes can reach extraction/publication;
- services flagged and disputed spots per service, split by claim type and by which models disagreed;
- invalid/unavailable draws, degraded sequences, refusals, actual cost and wall-clock time;
- pre-review accuracy, post-review correctness and residual uncertainty as separate measures;
- review questions/time and ruling/rule reuse metrics from §3.10.

Update containment mapping for the ensemble flag and test it against application policy. Refused,
unscored and provisional-only results must not count as clean independently verified detections.
Use targeted operator questions for unknown identities/boundaries and conflicting alternatives;
unanswered items stay visible. Neither a count match nor an ensemble agreement proves completeness.

For ER1 and each candidate rule, replay the saved evidence and enumerate every suppressed dispute,
changed boundary/plan and known-truth regression. Measure correctness alongside flag reduction.
This bounded set cannot estimate unseen-service accuracy; do not portray repeated calls as
independent services or reinstate a larger review workload without an operator decision.

### 7. Canary 9 and rollout

- Canary 9 needs DR2, slim DR1 and DR4 (decision 7). The reusable answer/correction/replay loop
  (DR3) must be finished and verified before batch 1. Complete implementation/evaluation and
  present concrete results. Only after the dispatch HOLD is explicitly
  lifted: commit the implementation, move the operational freeze, snapshot authoritative state,
  restart/verify workers, check membership/routes/holds and run preflight and the complete 16-run canary.
  Documentation commits do not move the freeze or authorise paid calls, dispatch or publication.
- **Canary bar (Q5, adopted 2026-09-29 before canary 9):** zero unflagged talk-count errors and
  every dispute answered. A flagged error remains an accuracy error, not a correct detection: record
  pre-review accuracy and post-review correctness separately. Do not change the bar after seeing results.
- Rollout follows the reliability plan's revised DR6 and its recorded operator decisions:
  - the spot-check selector reads `service_structure_ensemble` disputes, not a scratch second
    draw, and shows the voters' versions as the answer options;
  - retain edge and weak-song selectors, deduplicated with ensemble questions;
  - apply matching rulings, correct output and deterministically recheck dependent plans before
    proceeding under existing extraction/release controls;
  - between batches, measure candidate general rules on saved evidence; adopt only justified rules;
  - report unresolved/deferred/stale questions, degraded runs, corrections, time and reuse.
- Keep the canary custody/hold diff and parent operational gates. A failure remains evidence and
  stops advancement; do not rerun only 949 to erase it. No public release follows from detection
  acceptance alone. Changes to rollout size/stop thresholds remain explicitly adopted decisions.

#### Canary 9 (2026-09-30) — detection passed, custody failed

Freeze `historic-rerun-freeze-20260930` on `61ff438b0`; snapshot `freeze-20260930/`; the 16 batch-1
runs dispatched 20:12 and settled 20:36 BST. All 64 draws valid. Talk-count errors 0 (unflagged 0);
cuts wrong 0 (1050, 1250 and 1356 held either way: interrupted sermon); 41 questions over 13 runs
(song 15, talk 11, reading 7, sermon 6, other 1, notices 1); 1025, 1028 and 1108 question-free with
the right cut. Every hold carried.

**Custody failed on 1221:** the composed titles respelt two bound songs ("Speak O Lord" →
"Speak, O Lord", "King Of Kings Majesty" → "King of Kings Majesty") on the same item and span, and
`ServiceSectionSyncService` compared raw title text, so it deleted both extracted clips, song video
619 (quarantined) and §2727's published state. No other copy; recovery is pipeline re-extraction.
The same review found the sync paired rows by position, so any inserted or removed section would
have shifted every later clip onto its neighbour's row and deleted it (1176 media-bearing sections
in the corpus, 261 published). Fixed: a bound section is identified by its item and an unbound
title is compared without case or punctuation; rows pair by that identity first, then position,
parked clear of the unique position while positions are rewritten. Simulated on the canary's own
before/after sections: the old rule loses exactly the two 1221 clips, the new rule none. The
operator chose to move the freeze to the fix and run canary 10 on the same 16 runs to see the
custody diff clean on live runs; 1221 is re-extracted through the pipeline after its questions
are answered.
