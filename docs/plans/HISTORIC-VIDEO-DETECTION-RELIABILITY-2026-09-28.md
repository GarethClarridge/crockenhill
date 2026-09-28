# Historic video detection reliability after canary 8

**Status — 2026-09-28 (late): reviewed; DR2 implemented uncommitted (freeze and HOLD unchanged);
DR4/DR6 sizing awaits operator decision.** See §0.
The operator requested investigation and a plan. No application code, processing state,
freeze, model configuration or acceptance rule was changed; no paid detections were run.
Canary 8 remains FAIL. Tier C and corpus dispatch remain on hold.

This is a focused work package under the
[historic video defect plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md),
§4.0. It does not replace the
[incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
or its publication, hold and import controls. The proposed sequence below replaces neither
the existing canary bar nor the operator's HOLD until adopted. Earlier canary results remain
evidence, not permission to proceed. This document owns only the detection-reliability work
between canary 8 and a decision to dispatch canary 9.

**Recommendation:** improve and measure both corrective retries and first-attempt
classification before moving the freeze. Do not redefine a flagged classification error
as a correct result. The first consumer is the approximately 400-service historic rerun;
the current frozen membership is exactly **437** runs.

## 0. Review outcome (2026-09-28, late)

> **Superseding proposal (2026-09-28, later):** the operator directed a four-draw ensemble
> (2 × gpt-5.6-luna, 2 × gpt-6-luna, parallel, consensus-composed, disagreements flagged, validation
> retry deleted). It is specified in [ensemble structure detection](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md),
> **a plan awaiting Codex review; nothing is built.** If it is adopted, the DR2 retry repair and
> `FLAG_RETRY_CHANGED_TALKS` described below are deleted. The spot-check comparison then reads
> the ensemble's disputes instead of a scratch second draw.

**949's retry evidence was partly recoverable.** `processing_metadata.service_structure_retry` on
run 949 reads: *"Section 13 (prayer, starts 1661.7s) lies almost entirely inside the previous
section (prayer, ends 2003.0s): they share 341.3s."* The rejected attempt therefore typed the
region around 1662–2003 s as prayer, nested, where the retry placed the Cold Harbour and
Crockenhill talks. That strongly supports the hypothesis that blind regeneration, not the first
attempt, produced the false talks. It does not show the rejected attempt's treatment of 781–1341 s.

**DR2 implemented (uncommitted; no paid calls):**

- `DetectServiceStructure::detectionRetryFeedback()` now lists the rejected structure, numbered
  as the findings number it, and asks for a repair that keeps unnamed sections' type, times and
  OoS item. It adds no interface change: the list travels as feedback.
- After recovery, including any reading-recheck adoption, `sectionsDisagreeingOnTalks()` pairs talks
  one for one (±30 s, the reading recheck's tolerance). New `ServiceStructureValidator::FLAG_RETRY_CHANGED_TALKS`
  is raised on retry talks without a counterpart, and on every retry section over a dropped talk.
  The flag forces review, blocks auto-extraction (not demoted/non-disqualifying) and is catalogued.
  It is flag-only, never a fallback to the invalid attempt, following the talk-interrupted precedent.
- `service_structure_retry` now keeps `rejected_sections` and an `outcome`
  (`retry_adopted` / `retry_changed_talks` / `retry_failed_validation`). The scratch draw
  driver mirrors this and records `rejected_sections`.
- Four regressions in `DetectServiceStructureTest` cover repair feedback, an introduced talk, a
  dropped talk and an agreeing retry. The full suite, PHPStan and Pint pass.

**Not implemented / departures:** structured (section-indexed) validation findings were not needed:
the flag compares every talk, so a legitimate talk repair such as a `multiple_sermons`
reclassification is reviewed, not exempted. That review is the guard's measured cost. DR4's held-out design (32 new services needing full-transcript
operator rulings, 96 sequences) and DR6's per-batch five-service clean audits (~22 batches for 437)
are operator-time decisions, not code. They were left for the operator, not adopted by default.

### Operator decisions (2026-09-28, late) — supersede DR4's held-out set and DR6's clean audits

- **Keep `structure_retry_changed_talks` broad.** Scoping it to the sections the findings name
  would have missed 949: the false Cold Harbour talk (1655–1813 s) lies inside the named prayers.
- **No whole-service markup.** Evaluation is scored automatically where truth exists; the operator
  answers only single, specific questions through the listening/review artifact pattern.
- **Evaluation:** (a) 949 synthetic repair: a clean draw with one prayer nested inside another, as
  the original failed, is repaired about 10 times and scored against existing truth. It is labelled
  synthetic. (b) Regression: the 16 canary services about 3 times each through the production
  recovery sequence, scored automatically. (c) Canary 9 on the frozen implementation.
- **Automatic comparisons** raise one spot question each, not a review of the service: detected
  talk count against the order of service, and the new run's talks against the run's previous sections.
- **Targeted spot checks, all instances, chosen by rule from each batch's new output before
  anyone looks at it:** every talk edge adjacent (<3 s) to another speech item ("Where does the
  talk end?" over ±45 s of transcript + clip), and every song without a confirmed lyric match
  ("Which song is this?"). Stored-state census, 437 runs: 105 such talk edges in 89 runs;
  55 of 1,175 songs are inferred/unmatched. That is about 8 questions per 20-service batch.
  Answers are also evidence: a recurring edge pattern becomes a detector fix, not a permanent check.
- Known residual gap: a wholly missed talk in a service where count comparisons agree.

### Reprocessing inputs: service artifacts moved to the internal disk (2026-09-28, late; uncommitted)

Census of the 437 frozen runs: each retains its transcript family, RMS, audio timeline, service
MP3, source working copy (while `MEDIA_PAUSE_TEMP_FILE_CLEANUP=true`) and banked structure. Future
flag, hold and extraction changes can therefore be re-derived without paid calls. A detector change
needs one re-detection call per service; a transcription change re-transcribes from the retained MP3.

These artifacts followed the media disk, so they were on the Staging drive. Operator decision:
no historic-only behaviour; routine and historic runs share one policy.

- New `media-processing.storage.service_artifact_disk` (`SERVICE_ARTIFACT_DISK`), resolved by
  `ServiceArtifactDisk::name()`. When unset, it falls back at read time to `transcript_disk`, leaving
  production unchanged. It is separate because `transcript_disk` also holds published sermon transcripts.
- `archiveAudio` writes service audio to the artifact disk; `service-audio/` is an artifact prefix.
- The historic guard does not swap this disk; it asserts it is local-driver and not publicly served.
  Bundle export accepts it, and `HistoricProcessingResultAssetTransfer` reads artifact paths from it.
- `media:move-service-artifacts` reads each run inside its own staging context, copies, verifies
  SHA-256 and repoints records. It never deletes the source. Applied 2026-09-28 to 1,903 files,
  with 0 missing and 0 conflicts. It uses **10 GB**, not the estimated 770 MB, because RMS logs are
  ~11 MB and MP3s ~11.5 MB each, ≈23 MB per service. Internal disk has 28 GB free. Every path field
  and record resolves and exists on `service_artifacts`; workers were restarted and verified.
- Backups: the Staging originals are a frozen first copy. The ongoing copy is
  `rsync -a --exclude '._*' storage/app/private/service-artifacts/ /Volumes/Sonnics/_service-artifacts-backup/`
  (no `--delete`), **run after every batch** and after weekly runs once resumed. The first sync
  is verified identical except for permission bits.
- Artifacts are never cleaned up; cleanup remains for large media on the temp disk.
- The answers to decision 3 in §0 above are banked as content-keyed rulings (span + transcript text)
  in the truth file, so later reprocessing is scored against them rather than re-asked.

### Evaluation and spot-check tooling built (2026-09-28, late; scratch, retire under IC8)

- **Draw driver** `storage/scratch/detection-draws-20260928.php`: `DRAW_NEST_PRAYERS_AT="949:1662"` takes the
  run's real first attempt and makes the prayer covering that second part of the prayer before it. The
  mutated structure is validated without re-snapping, then goes through production's retry, recheck and talk guard.
  A zero-cost self-test with the mock detector replaying clean draw 2 reproduced the canary failure:
  "Section 12 (prayer, starts 1661.7s) … ends 2002.9s: they share 341.2s". The canary's own
  failure read "Section 13 … ends 2003.0s … 341.3s". Output records `synthetic`, the rejected sections
  and the failure summary.
- **Scorer** `detection-score-20260928.py` (DR1): per-file membership; `REFUSED` for final
  validation failures; `flags` and `review_flags` treated as one field; talk-count errors split into flagged and unflagged.
  Canary 8 has 4 errors, all flagged; canary 7 has 3, all unflagged. Operator spot rulings are scored for
  runs without full truth.
- **Automatic comparisons: measured and changed.** Only **10 of 437** frozen runs list a talk in their
  attested order of service, so the count check rarely applies. It now fires only when the order lists a talk.
  Comparing with the pre-rerun sections gave **16** talk differences on batch 1, mostly corrections made by the
  new prompt. Two current-prompt draws differed in **0**. The comparison therefore uses a **second unadopted
  current draw**: roughly $0.006 per service, ~$3 for the corpus. When both draws find the same talk with
  different edges, it asks an edge question, not a presence question.
- **Spot checks** `storage/scratch/spot-checks/`: `select.php` applies the rules to current sections and
  cuts clips from the local service MP3. It skips anything a banked ruling already answers and defers
  edge questions behind presence ones. `build-page.py` generates the phone page from `page-template.html`;
  answers go to the page's `rulings` db collection. `bank-rulings.py` stores them in
  `storage/app/private/detection-rulings/rulings.json`, keyed by run, span and anchor line, with the
  transcript SHA-256.
  The batch 1 self-test produced 22 questions (13 talk end, 3 talk start, 4 presence, 5 weak song), a
  talk-heavy canary. The presence questions matched exactly canary 8's four false talks. Banking and scoring were tested end to end.
- **Backups** now include
  `rsync -a --exclude '._*' storage/app/private/detection-rulings/ /Volumes/Sonnics/_detection-rulings-backup/`.

## 1. Investigation findings

### Verified evidence

The working tree was clean at investigation start and HEAD was `3ffe4b54c`.
`storage/app/private/freeze-20260928c/membership.json` identifies full commit
`3ffe4b54c0877e3a5be9c75febafce2c31c650f7`, 437 members, membership hash
`8fe5add72e916e3a890435921157033d879221e28ec4f9d4842b14366227f46c`.

| Evidence | Finding | Interpretation |
|---|---|---|
| `storage/scratch/canary8-sections-20260928.json` | 949 has four `short_talk`s: Elmstead 781.011–933.009; Court Farm 1188.990–1341.010; Cold Harbour 1655.990–1813.000; Crockenhill 2008.070–2101.990. All carry `structure_talk_interrupted`. | Four false talks in one service; the existing ruled count is zero. Containment does not make the classification accurate. |
| `storage/logs/laravel.log`, 2026-09-28 16:48:11 UTC | Processing ID `fc86e9f6-243f-42c3-92dd-1f41b47f5a1f` retries after `non_chronological`; another completion is logged at 16:48:36. | Corroborates the reported retry. The session handoff identifies overlapping prayers as the finding. |
| `storage/scratch/detection-draws/c8probe949-g56-luna-d{1..10}.json` | All ten have one call and no recovery steps. Nine have no talks. Draw 1 invents a song introduction at 516.28–545 and a prayer before the sermon at 2705.99–2744. Both carry `structure_talk_fragment`. | First-attempt classification can also fail. These probes supply no conditional evidence about validation retries. |
| `storage/scratch/detection-truth-20260928.json` | 949 expects one sermon, no short talks; its start can include the prayer for the preacher. | Including that prayer in the sermon is allowed; publishing it as a separate talk is not. |
| `storage/app/private/freeze-20260928c/batch1-rerun-diff.txt` | Four holds carried; no dropped/cleared hold entry. Six attention entries are 972, 973, 974, 975, 979, 982, outside batch 1. | Known unrelated attention remains unresolved; do not count it as the cause of this canary failure or silently clear it. |
| `storage/scratch/detection-draws/p5order-g56-luna-d{1..5}.json` | Two earlier validation retries occurred, both on 1112 (draws 2 and 3). | Retry was not wholly untested, but those draws did not exercise 949's failure. |

The supplied canary report records 15/16 clean services and 36/36 ruled spans matched.
All expected spans matching is compatible with extra false talks. The session handoff also
records that 1346 recovered from a 600-second provider timeout via a queue retry and that
Tier C was not dispatched. These are reported operational facts, not new execution here.

### What the code establishes

- `app/Jobs/DetectServiceStructure.php`, `detectWithPrimaryRecovery()` makes one new
  detection after a recoverable validation failure. `detectionRetryFeedback()` passes the
  failure summary and an instruction not to invent content. The result replaces the initial
  result; there is no comparison of unrelated talk classifications before adoption.
- `app/Services/ChurchService/Structure/OpenAiServiceStructureService.php`, `buildPrompt()`
  adds that feedback before the ordinary inputs. The API request has just system and user
  messages. **It does not supply the previous structure.** This is whole-service regeneration,
  not an edit of the faulty sections.
- The missing-reading recheck is different: it adopts a recovered reading only when
  `shortTalksAgree()` also passes. That is a useful precedent for limiting collateral changes,
  not a policy to copy blindly: a hard-invalid first attempt is not a safe fallback.
- The existing prompt already distinguishes sharing-and-prayer from a genuine partner
  presentation followed by prayer. Repeating that sentence alone is not a demonstrated fix.
- `ServiceStructureValidator::checkChronology()` rejects non-positive duration and gross
  containment. Ordinary boundary overlap is tolerated. Do not loosen the validator or merge
  prayer sections automatically merely to avoid this retry.
- `structure_talk_interrupted` recognises talks separated by readings/prayers. It does not
  cover every isolated false talk. The fragment flag caught the two short probe mistakes;
  it does not prove longer false introductions or prayers will be caught.

### Limits of the evidence

The original canary-8 rejected response was **not recovered in this investigation**. The
retry metadata stores a failure summary, not the rejected sections, and the evaluation
telemetry stores usage rather than response text. It is therefore not established that the
initial attempt classified every church update correctly, or that the feedback caused the
retry error. Treat that as a hypothesis to test, not a root-cause conclusion.

The reported “two errors in 96 detections” has no sufficiently clear denominator here:
erroneous sections, affected services, detector calls and complete recovery sequences are
different units. Repeated draws on tuned examples do not estimate unseen-service accuracy.

### Evaluation gaps to close

`storage/scratch/detection-draws-20260928.php` does invoke production detection/snap/validation
helpers and reproduces recovery sequencing, including the reading recheck. However, it
duplicates that orchestration and saves only the final sections. It loses the rejected
structure and the exact per-attempt input/output sequence needed for this diagnosis.
The separate `StructureEvaluateCommand` evaluates a single detector call, so it is not a
substitute for the recovery-aware harness.

`storage/scratch/detection-score-20260928.py`:

- hard-codes 16 in verdict/report calculations; a held-out manifest needs a dynamic denominator;
- reads saved sections without using `hard_failures` to reject a validation-failed draw;
- does not separately score contained and uncontained errors, or normalise the two artifact
  flag names (`flags` in draws, `review_flags` in canary dumps);
- scores sermon starts, not sermon endings or the actual extraction plan. Existing extraction
  checks must remain separate; this score is not whole-output acceptance.

Existing job tests cover impossible timestamps, competing sermons, retry exhaustion and
reading-recheck talk preservation. They do not cover overlapping prayers followed by a
validator-passing retry that introduces unrelated talks. Prompt-text assertions establish
the presence of instructions, not model compliance.

## 2. Delivery sequence

### DR1 — Preserve evidence and make evaluation trustworthy

**Outcome:** every score can be traced to its exact inputs, attempts and acceptance decision.

- [ ] Preserve the named artifacts and their hashes outside disposable scratch retention before
  experimentation. Bind code revision, prompt, truth, transcript, audio timeline, attested OoS
  inputs, model, effort and actual tier. Keep transcripts and response bodies in private artifacts.
- [ ] Recover 949's saved retry summary through a read-only application/database path; search
  retained artifacts for the initial response. If absent, record that absence permanently.
  Any reconstructed overlap fixture must be labelled synthetic, never the original response.
- [ ] Extend the existing evaluation path, rather than introduce another scratch/tinker driver.
  Capture initial and subsequent raw/validated structures, exact feedback, pre/post-snap bounds,
  hard failures, review flags, adoption/refusal and call usage. Prove parity with production
  recovery and prove it does not mutate runs, sections, holds, publication or freeze metadata.
- [ ] Make the scorer use explicit membership, reject missing/duplicate/unexpected runs and hard
  failures, support partial diagnostic manifests, normalise flags and report one-to-one expected
  talk matches. Add fixtures for a false positive cancelling a missing talk in the total count,
  splits, merges, optional spans, unscored runs and accepted boundary alternatives.
- [ ] Report erroneous sections, affected services and total calls separately; break down first
  attempts, validation retries, reading rechecks and queue retries. Report refusals/parked runs
  separately, not as accurate detections. Do not infer extraction safety from a flag string alone.

### DR2 — Reproduce and constrain corrective retries

**Outcome:** a chronology correction cannot silently introduce unrelated publishable content.

- [ ] First add a failing PHPUnit regression using an overlapping-prayer initial structure and
  a mechanically valid retry with new talks. Include successful chronology correction, genuine
  partner talks, a retry still invalid, reading recheck after recovery, and reconcile preservation.
- [ ] Compare the current retry with one narrowly scoped candidate: provide the prior proposal
  and specific faulty sections with the full transcript, request a bounded repair, then validate
  the whole result and check for collateral changes. Define affected sections explicitly from
  structured validation findings, not fragile parsing of human error text.
- [ ] If repair scope cannot be established or unrelated talk identity/count/bounds change,
  route to review. Never adopt the invalid first attempt. In reconcile mode retain previously
  authoritative sections and their holds; for first processing keep the proposal non-authoritative.
  This is a candidate policy to verify against legitimate repairs, not an instruction to preserve
  every classification in an invalid proposal.
- [ ] Keep the retry bounded. Do not add repeated whole-service draws until one passes, a vote
  over outputs, or hard-coded handling of run 949. Measure review refusals as a cost of the guard.

### DR3 — Improve first-attempt classification where evidence supports it

**Outcome:** sharing/prayer, song introductions and standalone prayers stop becoming talks
without losing genuine partner presentations, children's talks or testimonies.

- [ ] Use 949 plus contrasting existing cases: 936/1356 talks containing readings; 1117 distinct
  talks separated by prayer; 1311 separate testimonies; 964/1025/1250/1112/1221 accepted boundaries.
- [ ] Inspect full transcript context for the distinction between reporting prayer needs and
  presenting a partner's work. Propose a concise general rule or prompt reorganisation only
  where that distinction is supported. Do not classify by title, duration or organisation name.
- [ ] Evaluate the retry candidate alone, then any prompt candidate separately. Keep the current
  model/effort initially. A new model comparison is not the default response to this failure.

### DR4 — Predeclare and run a bounded evaluation

**Outcome:** measured improvement survives repetitions and examples not used to tune it.

Before any new paid evaluation, record the exact manifests, revisions, draw counts, score rules,
maximum call/spend budget and stop conditions. Estimate spend from saved usage and the local
price snapshot; count feedback, reading rechecks and transport retries too. No paid calls were
made as part of writing this plan.

Recommended design, subject to adoption before execution:

| Set | Membership and draws | Purpose |
|---|---|---|
| Retry diagnostic | 949: 10 fresh first attempts, 10 calls with the captured validation feedback, 10 candidate repairs using a fixed invalid proposal; two contrasting repair cases, five calls per arm | Separate feedback effects from ordinary variance; synthetic inputs explicitly labelled. Forced feedback alone is a conditional diagnostic, not an end-to-end pass. |
| Regression | Existing 16 services, five complete production-equivalent sequences per service on the selected candidate | Preserve known talks, readings and ruled boundaries. Compare against frozen baseline evidence; generate matched baseline observations where saved inputs/revisions are not comparable. |
| Held-out acceptance | 32 previously unused services, three complete sequences each | 16 seeded random services from remaining frozen membership plus 16 distinct challenge services spanning eras, ordinary services, prayer/sharing, partner presentations, baptisms/testimonies and poor transcripts. Fix selection before looking at candidate outputs. |

The challenge sample is deliberately enriched and must be reported separately from the random
sample. Select from inputs and existing metadata, not candidate errors. Establish expected talks
and acceptable spans from full transcripts before scoring. The operator rules on ambiguous
content from full transcripts or source clips, never an agent's summary. Do not invent rulings.
Any unreadable source receives an explicit disposition, not a silent sample replacement.

Limit development to the retry candidate and at most one accompanying prompt candidate in this
cycle. If neither meets the gates, stop and report the failure class and review cost; propose a
new bounded cycle. Do not keep tuning against the held-out set. Once inspected for tuning, that
set becomes regression material and a new held-out set is required.

### DR5 — Acceptance and canary 9

**Outcome:** a single frozen implementation earns permission for bounded corpus processing.

Proposed gates, to adopt before DR4 rather than adjust after its results:

| Gate | Required result |
|---|---|
| Deterministic correctness | Focused regressions pass; invalid originals are never adopted; collateral retry changes cannot bypass review; evaluation leaves authoritative state unchanged. |
| Known-case accuracy | All 80 regression sequences scored; zero false/missing/split/merged talks, and all required spans within operator-accepted alternatives. A refusal is not a clean sequence. |
| Held-out accuracy | All 96 sequences scored; zero confirmed talk-count errors, including flagged ones. Resolve every boundary adjudication before acceptance. Report failures rather than relax this gate mid-cycle. |
| Safety | Zero observed erroneous outputs eligible for automatic extraction/publication; prove downstream behaviour with integration tests, including isolated false talks and retry refusals. Existing holds remain effective. |
| Review burden | Report distinct affected services, repeat flags, refusals and operator minutes separately from pre-existing holds. Proposed tripwire: more than 10% of unique acceptance services newly need detection review, or more than 5 minutes median review per affected service, requires an explicit workload decision before scaling. This is not a classification-error allowance. |
| Coverage | Retry-specific tests and diagnostic arms complete; passing first-attempt draws alone cannot satisfy retry coverage. Missing and unreadable cases remain visible. |

These are finite acceptance tests, not a claim of perfect accuracy across the corpus. Repeated
draws on one service are correlated; do not present 96 sequences as 96 independent services
or derive a population confidence claim from the enriched sample.

- [ ] Run project-required checks after implementation: focused PHPUnit tests, PHPStan, Pint,
  and the full parallel suite for the non-trivial retry change. Use Sail and retain full output.
  Dusk is required only if browser behaviour changes; no UI work is proposed here.
- [ ] Present results and any unresolved operator rulings. Only after the HOLD is explicitly
  lifted for the concrete change: commit, move the freeze, snapshot current authoritative state,
  verify membership/routes/holds, restart and verify workers, perform the existing preflight.
- [ ] Dispatch the complete 16-run canary once as canary 9, score it and run the custody/hold diff.
  Keep zero talk-count errors as its bar. Adjudication must finish before advancement. Do not
  rerun 949 alone to erase a failure. A failure stops dispatch and remains in the evidence.
- [ ] Only a passed canary and the parent plan's operational gates permit batch-1 Tier C.
  Verify actual extraction plans and source openings/endings under the existing checks.
  Detection acceptance does not authorise public release.

### DR6 — Controlled rollout and review of apparently clean services

**Outcome:** new corpus errors are detected before the next batch, including missing talks
that cannot flag themselves.

- [ ] Resume parent-plan era batches with an initial maximum of 20 services per batch.
  Review every retry/refusal, every new or changed talk, and every flagged talk before extraction
  decisions. Review full-service context, not just the proposed talk list.
- [ ] Before each next batch, audit five seeded, apparently clean services (or all when fewer
  than five), using full transcripts to search for missing talks as well as false positives.
  Keep this sample separate from flagged-service review and record selection before reading.
- [ ] Stop before the next batch on any confirmed new talk-count error, unacceptable content
  boundary, lost hold or error escaping review. This proposed stop rule is stricter than the
  existing “more than three false talks under 60 seconds” tripwire; on adoption it supersedes
  that tripwire for this rollout. It covers errors longer than 60 seconds and missing talks too.
- [ ] Track cumulative accuracy by unique service and failure class, conditional retry outcomes,
  review minutes and newly parked services. Apply the workload tripwire over completed batches.
  A review hold is containment, never a silently accepted accuracy failure.

## 3. Completion and unresolved decisions

Complete this work package when the evaluation gates are met, canary 9 passes, and its evidence
and rollout monitoring are handed back to the parent plan. Archive this plan at historic closeout;
retire temporary evaluation tooling under the existing IC8 ownership.

The operator still needs to adopt the evaluation sizes/workload limits and resolve any ambiguous
truth spans. The single maintainer can perform all review and acceptance roles. No second person
or independent human approval is required. A request to write this plan does not lift the existing
dispatch HOLD or approve changes to publication policy.
