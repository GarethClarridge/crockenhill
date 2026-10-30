# Historic Video Defect Discovery and Acceptance Plan

**Current worklist — reconciled 2026-10-29.** Historic outputs remain quarantined; no release batch
is authorised. The 10-21/22 round closed. Later shared-path fixes still need a measured round and
current-output acceptance. The next blocker is 1288's decoded-onset leak, not a policy dispute
about retaining the congregation's Amen. The proposed quiet-dip rule awaits listening/adoption.

This file owns **current video work and status**, not execution diaries or acceptance procedures.
The complete prior text is preserved in the [execution record through 10-29](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md).
Reconciliation below uses recorded evidence, not a fresh database or production census.

## 1. Outcome and boundaries

Produce faithful, usable historic outputs through the shared weekly livestream pipeline, then
accept exact artifacts before the programme's separately authorised release. Processing completion,
content acceptance, delivery verification and release authority are distinct.

| Responsibility | Authoritative document |
|---|---|
| Binding operator rulings | [Rulings register](HISTORIC-IMPORT-DECISIONS-2026-09-03.md) |
| Starting, freezing, watching, stopping and closing a local round | [Round runbook](HISTORIC-ROUND-RUNBOOK.md) |
| Output-quality checks, evidence, sampling and agent-first escalation | [Post-round audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md) |
| Song measurement units and protocol | [Song acceptance](HISTORIC-SONG-ACCEPTANCE-MEASUREMENT-2026-10-11.md) |
| Production census, cross-source convergence, release and retirement | [Programme](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) |

Follow those owners rather than copying their procedures here. The audit owns the operator's
2026-10-29 preference for Codex/Claude doing supported checks before escalating residual questions.
Containment is not acceptance; a missing or unassessable check is not a pass.

## 2. Completed work and archived evidence

| Recorded work | Reconciled status / evidence |
|---|---|
| Processing rounds through 10-22 | Closed; no release implied. [Closure record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#10-22-later-the-round-closed) |
| Three duplicate/date pairs | Corrected locally 10-16, then re-detected, re-cut and seven identity holds released under X4 in the later 10-18 record. The old §4.4 instruction to do those steps is superseded. Other song/quality holds remain independent. See §4.4 below. |
| Ruled-evidence capture | Full capture/scoring and CI ratchet landed 10-23; later decodes and rulings supersede its counts. Do not restart the old 883-ruling capture backlog. Current reconciliation is §4.1a/§4.6. |
| Stored review-state repair | Re-derive → recompute → backfill → demote applied locally 10-25. The earlier instruction to apply it is complete; fresh policy/output checks are still needed after processing. |
| Song and speech edge fixes | Dependent openings, 1096's other-song title rule, 1120's song-order lyric extension, fading-song speech starts and the narrowed smeared-opening fix are committed. Six moved smeared starts were heard acceptable; 1250's internal start is met by the planner. They still need pipeline application and encoded-output verification. |
| Internal-join regression coverage | Added 10-29 (`abd330797`), including part-specific identity; capture's `open_defect` handling corrected. 86 previously unguarded passing entries now checked; 17 failing entries remain inputs to the next measurement. |
| K16 reword | Parked by operator: one gain (1131), one loss (968). Do not re-ask the corpus or build its two extra guards without a later adopted change. |
| Audience/content policy | Decided (G10–G11); production enforcement is a verification task under programme §8, not an open policy question. |

Earlier evidence: [10-03–10-16](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-10-03-TO-2026-10-16.md),
[09-24–10-02](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-24-TO-2026-10-02.md),
[09-12–09-23](../archived-plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-EXECUTION-LOG-2026-09-12-TO-2026-09-23.md),
and [pilot/bulk](../archived-plans/HISTORIC-VIDEO-PILOT-TO-BULK-EXECUTION-LOG-2026-08-29-TO-2026-09-11.md).
These explain decisions; they do not supply current route lists or counts.

## 3. Current measurement status

No new census was run for this consolidation. October 16 membership, readiness counts and disk
figures are historical. Even the later 426-run / 1,351-ruling counts predate subsequent changes.
Recount eligible runs, unresolved rulings and unassessable evidence on final code before freezing.
The audit's dated discovery totals similarly require hash reconciliation, not automatic credit.

## 4. Remaining work in execution order

### 4.0 Round status

The previous round is closed. There is no current dispatch authority in this document. Complete
§4.1a, then use the runbook for an authorised frozen round. Do not reuse October route lists.

### 4.1 Current processing work

The only current pre-freeze sequence is §4.1a. The old “one final round” narrative and its clone,
queue and route state are in the archive; a historical “final” label is not acceptance evidence.

### 4.1a Before the next round: regression coverage and combined verification (2026-10-29)

1. **Internal joins — implemented; retain regression protection.** Part-specific rulings cover
   1208 and 1235 without superseding the video's opening. 1250's approximate 28:59 internal start
   is protected by `SongEndEdgesTest`. Include all of these in final replay and encoded canary checks.
   The 17 still-open fixture entries from `storage/scratch/open-defect-fix-20261029/` need dispositions
   in step 3; the 86 newly guarded passing entries must remain guarded.
2. **1288 — resolved; next-word-sound rule adopted (10-29 night).** “Amen Heard Again” confirmed a
   real defect (B again: “It's not the second Amen that's the problem.”): `beforeWordHeardElsewhere()`
   stopped 1288's end on the decoded onset of “So” (3755.31), carrying ~50 ms of it. Measured
   read-only over 2,024 plans; the dip before the onset, not the leak, separates the cases.
   `CueSafeExtractionPlan::beforeNextWordSound()`: an end on a decoded next-word onset with the
   sound already up and a ≥20 dB dip in the preceding 0.25 s ends at that dip's quietest point.
   [Before The Next Word](https://claude.ai/artifact/8sq6bC4EP16CR9fnjdX6r7): all four B, operator:
   “significantly better … there isn't really a pause between words” (keep: 928 internal reading end
   1866.11, `part` §512; 1288 3755.19; 1340 3271.89; 1303 §3860 1129.84). Corpus replay moves exactly
   those four. 1288's three tests are green; full suite 10,905 passed, Dusk 63/63. Starts excluded.
3. **Measure the combined result on final code.** Recount eligible runs and open/unassessable
   rulings; compare current executed cuts with proposed plans, including internal joins, refusals,
   changed edges and conflicting rulings. Use existing replay/capture tools; write-capable planners
   run in rolled-back transactions outside an active round. Record exact membership and prerequisite
   routes: **1120 needs `ExtendSongsOverOwnLyrics`, not extraction alone**. Dispose of every move
   and refusal; reuse unchanged valid evidence and hear new/conflicting audible cases.
   **In progress 10-29.** Membership recounted: **425** eligible runs (completed, non-superseded
   historic), identical to the 10-24 list; nothing in flight. Replay
   `storage/scratch/combined-measure-20261029/` (`measure.php` → `plans-*.jsonl`, `analyze.py`): per run,
   one rolled-back transaction inside the run's staging context; plan A on stored sections (the
   `reExtract` route, and the actual route of 942, 974, 1363), then the round's recompose replayed
   (stamp + request on the latest banked attempt, chain from detection to the refining projection,
   including `ExtendSongsOverOwnLyrics`), then plan B. No provider call. 1120 confirmed: extraction
   alone ends §1999 at 952.46, recompose at the ruled 961.27; recompose also moves §2004's start
   1365 → 1355 (musical introduction, unheard). The first pass (`run1-no-ocr/`) is not usable for
   songs: OCR is an OpenAI vision call the replay cannot make. The corrected pass replays each
   unchanged song's banked OCR text instead. Results and dispositions pending.
   **Incident (10-29 night): the corrected pass deleted 126 recorded clip files.** It ran each run
   inside its staging context, so the sync's replacement branch (`cleanupExtractedAssets()`) deleted
   real files on `historic_staging`; the database rollback held (rows verified unchanged), the deletes
   did not. 87 short talks, 36 songs `not_applicable`, 3 songs `pending_approval`; list and recorded
   hashes in `combined-measure-20261029/deleted-clips.json`. No second copy: 123 none, 3 an older cut in
   quarantine; staging is single-copy (C2). The first pass (no staging context) deleted nothing.
   They are derived from the masters and need a re-cut before any review of them. **Operator 10-29
   night: "Let the next round do it."** The round's membership must include all 96 runs in that list,
   and its diff must show each of the 126 sections with a new recorded clip.
   Replays must not activate the staging context and must mark disks read-only.
   **The 17 open fixture entries, disposed (stand-in pass; confirm on the fixed pass):** met by the
   round 2 (1012 start, now governed by 10-25 `keep 0.0`; 1070 §3705); superseded by K16/the Amen rule
   4 (1053 §1610, 1186 §2410, 1070 end, 1218 end: each ends on the preacher's Amen, dropping only the
   song announcement or the congregation's Amen); conflicting ruling, to hear 2 (1115, 1127: a 10-18
   "cut off mid sentence" says later, K16 now ends earlier on the Amen); open misses to step 4: 9 —
   late sermon/reading starts 1136, 1308, 1262 §3289; `song_end_fade` song ends 1215 §2637, 1108 §1895,
   1108 §1897; singles 1262 §3278, 1262 §4913, 1108 canary-11 start. Section rulings on sermon sections
   (§1610, §3705, §2410) are judged on the sermon video's edge there. `gather.py` maps every
   `wrong_place` answer to `outward`; four are structure answers ("the middle of a different song"):
   1012 and 1282 are superseded, **1273 §3416 and 1311 §4874 stand** and need a structure disposition,
   not an edge score.
   **Classes measured for step 4:** 38 sermon starts lie > 5 s after their section start, nearly all
   unchanged by the round; 2 ruled late (1136, 1308). `song_end_fade` places 234 song ends; of 63 ruled,
   54 met and 9 missed, early (941 §751, 976 §958, 1272 §3403) and late (1215, 1108 ×2) alike, so no
   single bias; the rest marginal (1250 §3127, 1292 §3653) or unchanged (1262 §3288).
   **Step 3 status at 10-30 (handover).** Done: membership (425), the guarded route replay on final code
   (`no-stand-in/`, option 1, committed `c24d55d60`), 1120's route, the 17 open entries, the K16
   shortenings, the late-start and `song_end_fade` classes, the K16 reword on every end. Still open:
   (a) the edge moves — 451 outputs move, 380 edges by ≥ 1 s (runbook §5: each ≥ 1 s move goes on a
   page unless a ruling the new cut meets already covers it; group the rest by `reason`);
   (b) 22 refused plans and 87 unassessable rulings, each with a cause; (c) structure dispositions for
   1273 §3416 and 1311 §4874; (d) the draft page `storage/scratch/shortened-ends-listening-20261029/`
   (6 questions: 1004, 1005, 1115, 1127, 1157, 1219; built, not published) plus whatever (a) adds;
   (e) the reword adoption (step 4). Analyse with `python3 combined-measure-20261029/analyze.py
   combined-measure-20261029/no-stand-in`. Before any round: the internal disk had ≈ 4 GB free on 10-30
   (runbook asks ≥ 10 GB; `storage/scratch` 26 GB, `laravel.log` 809 MB; nothing deleted without go).
   **3a. Prerequisite found by step 3 — re-detection discarded unchanged sections' evidence (built
   10-29 night; operator: "Build it", then option "1"; corpus re-measure running, then suite and commit).**
   *Problem.* `ServiceSectionSyncService::hasMaterialSignatureChange()` decides whether a re-detected
   section is the same one. When it says no, the replacement branch drops the old metadata (banked
   `song_ocr_text`, the match record), resets publication to `not_applicable`, cleans up extracted
   assets and detaches song videos. It compared the section's item, its title and its exact times,
   and three of those misfired on unchanged spans: detection's classified sections carry only the
   draws' `oosItemId`, so a section bound to the run's own item arrived with none (933); planned items
   the draws did not cite arrived with none too, though the projection after the sync rebound the same
   item (108 in the corpus, e.g. 967 §1073); the projection recreates the run's own items each round,
   so ids churned (131, e.g. 939 §636: 20150 → cited 4883 → bound 24911); and re-detection's arithmetic
   returned 2010.5800000000002 for a stored 2010.5799999999999 (1120 §2009). On recompose that meant
   songs lost OCR confirmation, their catalogue titles ("Where, O grave…" → "Song") and the K16 answers
   that name them, or were re-OCR'd with paid calls where a source was staged, and unchanged clips
   stopped describing their recorded cut and lost their publication. Shared path: any re-detection of
   an already-projected run (`redetectServiceStructure`, on corpus re-run or recovered-transcript
   grounds, weekly runs included); a plain retry re-detects only if detection itself failed, before
   any item is bound.
   *Decision (operator, option 1 of three):* a section is unchanged when its type and span, to the
   millisecond, are unchanged, whatever its item or title. Matching re-derives the song
   (`withoutSongBinding()`), and a person-reviewed binding stays while the span is unchanged. Rejected:
   also requiring the normalised title (would still replace reworded draw titles) and moving the
   decision after the projection (a larger restructuring).
   *Built:* `materialIdentity()` returns type and millisecond-rounded bounds; the first, narrower carve-out
   for run-projected items was removed. `ChurchServiceItem::isAttestedOnlyByDetection()` (from
   `MatchSongsFromTranscript::onlyTheRunAttests()`) stays for matching's song withdrawal. Tests, red
   first: four unchanged-span shapes keep evidence, publication, clip and song video (933, 967 none
   cited, 939 another cited, a reworded title), float noise keeps publication, a moved bound or a changed
   type is still replaced. Two older expectations changed with the decision: canary 9's
   `an_unbound_section_given_a_different_title_*` now keeps its clip over the same span, and the
   re-detection that names another song drops the binding without marking the section superseded.
   Sync 47/47; matching, item, recompose, re-detection, sync and projection tests 498/498; Pint, PHPStan.
   *Measured (guarded replay, no OCR stand-in, `combined-measure-20261029/`):* the first, carve-out
   fix (`no-stand-in-fix1/`) cut unchanged-span publication resets from 582 to 169, leaving 60 songs
   unconfirmed and 60 re-titled; **option 1 (`no-stand-in/`) leaves 7 changed sections of 425 runs, none
   the defect:** 0 unconfirmed, 1 respelt; 4 songs (981 §1043, 1020 §1340, 1304 §3875, 1305 §3888; three
   published) whose span genuinely changes at detection and is restored by the later song-edge steps,
   so they reset and need re-approval after the round (only option 3 would avoid it; recorded as a
   residual); 2 move between two catalogue songs both titled "Great Is The Lord" (318/317; 977 §1009,
   1305 §3886: an OpenLP duplicate for curation); 1344 §4342's fuzzy title-hint match no longer stands
   (current policy). Refusals 27 → 22 and unassessable rulings 93 → 87 against the stand-in pass. No
   provider call, job or file write in any pass. Separate finding, unmeasured: the projection recreates the run's own items
   each round; check what an item carries that churn could lose before deciding it matters.
4. **Dispose of residual misses.** 928 still loses one word at a speech start over fading music;
   this is separate from its internal end in step 2. The measured class is 2 of 30 fade ends (928
   and 1220, the latter ruled either). Merely classifying mixed words as unsung does not fix 928's
   gap search. Record measured repair or containment for the class. 1131 remains an early-end miss
   (current 1410.28; ruled 1461.42), with the K16 reword parked. Include all further material misses
   from step 3; neither a recorded miss nor a fixture marked open is acceptance.
   **K16 class measured 10-29 night (step 3, `combined-measure-20261029/shortened-ends.json`):** of 188
   sermon ends the round would shorten, 176 drop only an announcement, lyrics or the congregation's
   Amen; 7 drop the closing prayer with an end that still reads as one (acceptable under K16 as softened
   10-29 night); **2 drop the last point: 1131 and 1140** (new: "As we draw to our close… how can you not
   glorify God?"); 4 need hearing (1004, 1005, 1157, 1219; draft page `shortened-ends-listening-20261029/`).
   1140's banked answer is untied, outside the parked reword's 116-end measurement. **Reword asked on
   1140 (operator: "You can make the calls"; 3 Luna calls, `k16-reword-20261029/answers/1140.json`):**
   it keeps the application, 1808.62 → 1853.29, though that cue carries a dangling "Let's" of the next
   line; 1131 → its ruled 1461.42. Record now 2 mended, 1 broken (968), untied ends unmeasured. Next: ask
   the reword on every untied end after the fixed re-measure (≈735 calls, ≈$0.55 at the 08-18 price
   snapshot), recording each answer's full identity but **not banking it** (operator: redraw changes
   "are useful learning for future weekly processing"). If adopted, the round redraws every K16 end as
   weekly would; its decided ends compared with the measured ones give the redraw change rate (§4.2),
   and only flips that change a heard cut go to listening.
   **Untied ends measured 10-30 (`k16-reword-20261029/measured-untied.json`, answers with identities in
   `answers/`, nothing banked):** 417 K16 ends (113 tied, 301 untied, 5 unanswered, 6 without a question);
   643 Luna calls, ≈ $0.31. The reword changes 10 untied ends. Read from the transcript: better 1140,
   1044 (keeps the closing prayer), 1098 (keeps the preacher's Amen), 1008, 1071, 1086, 1096 (drop
   housekeeping, a communion transition, an after-service invitation); worse **1375 and 1324, which
   take in quoted lyrics and the next hymn's title** — the reworded prompt's "even when it mentions,
   quotes or leads into the song" contradicts K16's "reading lyrics aloud alone does not establish that
   they conclude the sermon"; 958 to hear (a point built round the hymn title "Facing a Task
   Unfinished"). With the tied result: 9 better, 3 worse (968, 1375, 1324), 1 to hear.
   **Adopt the reword as measured (operator 10-30: option 2, "Current version seems fine to me"; not
   refined for 1375/1324; plan only — do it in a fresh session).** The losses 968, 1375 and 1324 are
   accepted. The round then redraws every K16 end (§4.2 redraw stability; nothing banked to stop it).
   1. *Code, test first.* `SermonSongIntroductionCheck::SYSTEM_PROMPT` takes exactly the two
      replacements in `k16-reword-20261029/measure.php` (saved whole in `system-prompt.txt`) and
      `PROMPT_VERSION` 2 → 3, so every banked answer stops matching. `SermonSongIntroductionCheckTest`
      pins version 2 (line ~480); update it red-first, with a test that a banked v2 answer is not read.
   2. *Re-bank the ruled-endings fixtures, no re-capture.* 587 of 591 fixtures (322 runs) carry banked
      K16 artifacts, matched by an identity that includes `prompt_version`, so all would fail on missing
      evidence after step 1. Measured 10-30: of 1,061 artifacts, 585 ask exactly the question tonight's
      measurement asked, 451 differ only in `media_processing` (the encoder/version a fixture is pinned
      to; question text, sermon end and song start identical, so the measured answer stands), 23 are a
      different question (older transcript, sermon end or song start) and 2 are runs with no measured
      answer (893, 945; not eligible). For each artifact write the v3 artifact: the same identity with
      `prompt_version: 3`, calls from `answers/<run>.json` where the question fields match, otherwise
      the reword asked on the artifact's own stored `prompt` (≈ 60 Luna calls for the 25), and the
      decision computed through `decision()` exactly as `bank()` does; remove the v2 artifact so
      fixtures do not double. The seven fixtures with a `song_introduction` block replay their own
      calls and need the block's calls re-asked the same way. Fixture `files` are lists of
      `{disk, path, content}`; the artifact kind is `sermon-song-introduction-<sha256 of identity>`.
   3. *Ruled endings.* Run them; every changed result is checked against its ruling, not adjusted to
      pass. Expected: 1131 and 1140 newly met; 968, 1375 and 1324 newly missed, recorded as accepted
      losses under this decision (with the operator's words) rather than silently re-expected.
   4. Focused tests, PHPStan, Pint, the full parallel suite, Dusk; record; commit to master.
   5. *Live:* nothing to apply now. The round's `TranscribeOutputEdges` asks every K16 end afresh
      (≈ 1,000 calls, ≈ $0.50); compare its decided ends with `measured*.json` for the redraw change
      rate, and send only flips that change a heard cut to listening.
5. **Validate, freeze and canary.** Focused tests, PHPStan, Pint and full parallel suite on final
   code; Dusk for browser behaviour. Follow runbook §§3/6 for live headroom, backups, frozen
   membership, worker revision and pause/resume. Inspect actual song-to-speech transitions and
   internal joins, and the audit's representative local acceptance journey before bulk expansion.
   Stop expansion on regressions or unassessable results. Acceptance follows the round, not its label.

Detailed measurements and prior interpretations are in the
[pre-freeze record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#41a-before-the-next-round-regression-coverage-and-combined-verification-2026-10-29).

### 4.2 Regular uploads are the lasting outcome

Weekly processing resumes after the historic import is done (R8); preparation can proceed now.
Historic livestream and weekly livestream share processing, but historic evidence does not certify
production hardware/backends or direct-upload modes. The old mode-by-mode inventory is dated evidence
in the [record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md#42-regular-uploads-are-the-lasting-outcome), not current runtime proof.

- [ ] Confirm where weekly runs (production per the standing ruling) and verify actual transcription,
  timed cues/edge evidence, classifier capacity, models, encoder, worker settings and speaker-off state.
- [ ] Make consequential review journeys usable in admin: edge questions, content-hold release,
  held-sermon recuts and talk–reading answers. Carry Q1's shared review-question/consequence pattern,
  durable answers and common publication predicate; talks stay ask-only. Verify implementation before
  assuming the old “not built” status still holds.
- [ ] Run two recent services through the production weekly path at take-over, measuring time,
  CPU and cost, with all questions answerable and outputs accepted under the audit procedure.
- [ ] Preserve originals for the backlog from 2026-08-30 onward and process oldest first after take-over.
- [ ] Extend the source-reviewed evaluation set beyond deterministic cut fixtures to structure and
  song identity; score before and after take-over. Keep mock-response tests distinct from model evidence.
- [ ] Measure model redraw stability: a weekly run gets one draw. Where a round re-asks a model question
  (first case: K16 if the reword is adopted, §4.1a step 4), compare the decided outcome with the one
  measured before and record the change rate and the flips; do not bank answers to suppress it.
- [ ] Audit already-published weekly clips read-only for the redesigned edge/sync classes; any
  production recuts require their own operational authority.

**Direct-upload scope:** the 09-22 local whole-sermon reproduction found no pathological loops in
five cases; it did not establish a rate or provider equivalence. No speculative safeguard is owed
for that unreproduced case. Direct-mode timed transcription/repetition work is the decided legacy
transcript follow-up in programme §6 (after historic completion and weekly take-over), not a blocker
for this livestream round. Another backend needs its own verification before use.

### 4.3 Acceptance evidence still owed

The [audit](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md) owns the complete checklist, agent-first procedure, sampling and stopping rule;
its §7 reconciles the former September checkbox backlog. Do not recreate those boxes here.
Outstanding lanes are source-first completeness and independent clean-case checks, full media and
join verification, detector evaluation, song acceptance, final editorial QA and real delivery.
Changed artifacts invalidate dependent checks. Older flags and “no issue found” reviews require
current evidence; unknown disposition remains open, not silently passed.

### 4.4 Bind deferred identity disputes

**The three adoption decisions are complete locally, not deferred.** The 10-18 record supersedes
this section's old pending instructions: kept runs 1120, 1034 and 1375 were corrected, re-detected,
re-cut and their seven identity holds released under X4. Poorer runs 930, 1035 and 1374 remain
excluded; retained assets were not deleted. The run-to-service mapping is 1120→617, 1034→1018,
1375→1121. Other quality holds, including 1034's song-boundary holds, are separate.

- [ ] Verify that mapping, exclusions and current assets in the next membership reconciliation and
  production census. Do not repeat adoption or clear unrelated holds. The dated evidence is in the
  [complete record](../archived-plans/HISTORIC-VIDEO-EXECUTION-RECORD-THROUGH-2026-10-29.md), under “Duplicate identity, 1034, 1120, 1375: released under X4”.

### 4.5 Prove acceptance, then converge and release

- [ ] Finish the audit's current-artifact acceptance ledger (§7), including the song protocol,
  editorial QA, reviewed speaker/partial-recording presentation and deferred thumbnail generation.
- [ ] Supply accepted membership and residual dispositions to programme §0.4/IC5 for the fresh
  cross-source census and rehearsal; an unresolved item stays held rather than blocking unrelated
  accepted outputs without cause.
- [ ] Complete programme §§7–8 for production apply, release authorisation and destination observation.
  This file neither grants release nor duplicates those procedures.

### 4.6 Lasting evaluation and long-term custody (added 2026-10-16)

- [ ] Re-score the current ruling ledger on final evidence and maintain the passing CI ratchet.
  The full 10-23 capture supersedes the 883/168-capture backlog; later wider decodes reduced active
  unassessable rulings to six in the 10-27/28 record, but subsequent changes require a fresh count.
  Keep unmet/unassessable cases explicit and preserve original hearing evidence and part identity.
- [ ] Add replay coverage for structure answers, the loop page and song stage 1; the edge ratchet
  alone does not cover them.
- [ ] A second copy of source masters remains **operator-deferred** until a destination is available
  (likely the staging drive after processing). Do not confuse this with C2's deliberate single-copy
  quarantine/staging outputs. Retain C6 until processing/custody permits cleanup to resume.
- [ ] Before IC8, decide and retain a maintained post-release media repair route, or explicitly
  record that released outputs are final. The masters/promotion tooling currently live locally.

### 4.7 Legacy sermon transcripts (decided 2026-10-17; planned, not built)

The decided scope and implementation contract now live once in
[programme §6, legacy transcript follow-up](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md#legacy-transcript-follow-up-decided-2026-10-17).
It includes production-only sermons and starts after historic completion and weekly take-over.

## 5. Go/no-go

| Decision | Current evidence / next action |
|---|---|
| Next processing round | Not ready to freeze: complete §4.1a, including candidate answers/adoption, final checks and fresh membership. |
| Content acceptance | Not established for the next outputs: audit current artifacts under §4.3/§4.5. |
| Production convergence and delivery | Not established by local processing: programme census, rehearsal and destination checks remain. |
| Public release | No batch authorised. Programme §8 owns the separate signed act. |

**Maintenance:** replace statuses in place, linking durable evidence. Do not append session diaries
or copy procedures into this worklist. Archive completed execution detail with a dated supersession
header; preserve unresolved work explicitly. New facts belong here only when they change next action,
acceptance status or an unresolved dependency.
