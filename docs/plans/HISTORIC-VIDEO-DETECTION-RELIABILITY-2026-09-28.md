# Historic video detection reliability after canary 8

**Status — 2026-09-28 (after Codex review): delivery sequence revised for the ensemble and
deterministic review loop; not implemented.** The original retry repair remains uncommitted code,
not the chosen next design. Baseline plans are preserved in `2bb569482`; this revision changes
documents only. Canary 8 remains FAIL; Tier C and corpus dispatch remain on HOLD at `3ffe4b54c`.
No paid calls, processing operations or acceptance-policy changes are authorised by these commits.

This is a focused work package under the
[historic video defect plan](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md),
§4.0. It does not replace the
[incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
or its publication, hold and import controls. The proposed sequence below replaces neither
the existing canary bar nor the operator's HOLD until adopted. Earlier canary results remain
evidence, not permission to proceed. This document owns only the detection-reliability work
between canary 8 and a decision to dispatch canary 9.

**Recommendation:** implement the reviewed ensemble design and complete the reusable
answer → correction → deterministic replay loop before increasing reprocessing volume.
Do not redefine a flagged classification error as a correct result. The first consumer is the
historic rerun of exactly **437** frozen runs; routine processing uses the same implementation.

## 0. Review outcome (2026-09-28, late)

> **Current design (2026-09-28, after review):** the operator directed a four-draw ensemble
> (2 × gpt-5.6-luna, 2 × gpt-6-luna, parallel, consensus-composed, disagreements flagged, validation
> retry deleted). It is specified in [ensemble structure detection](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md),
> **reviewed and revised; nothing is built.** It owns composition, runtime, evidence, review and
> replay specifications. The revised DR1–DR6 below replace the original retry-oriented delivery
> sequence. Remove the original retry implementation only with its regression coverage preserved.
> Spot checks consume ensemble disputes, and their answers correct output instead of merely
> suppressing future questions. Q5 (changing the canary bar) remains an operator decision.

The remainder of §0 records the earlier work and operator decisions. §1 preserves the original
investigation; its code/scorer gaps have partly been addressed by the work recorded here. Neither
section is an instruction to rebuild the superseded retry path or undo the later review design.

**949's retry evidence was partly recoverable.** `processing_metadata.service_structure_retry` on
run 949 reads: *"Section 13 (prayer, starts 1661.7s) lies almost entirely inside the previous
section (prayer, ends 2003.0s): they share 341.3s."* The rejected attempt therefore typed the
region around 1662–2003 s as prayer, nested, where the retry placed the Cold Harbour and
Crockenhill talks. That strongly supports the hypothesis that blind regeneration, not the first
attempt, produced the false talks. It does not show the rejected attempt's treatment of 781–1341 s.

**Original DR2 retry repair implemented (uncommitted; no paid calls for that repair):**

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

**Original retry-plan departures:** structured (section-indexed) validation findings were not needed:
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

This sequence incorporates the session's review. Detailed contracts, tests and remaining decisions
live in the [ensemble plan](HISTORIC-VIDEO-ENSEMBLE-DETECTION-2026-09-28.md). Historical retry
experiments, 80/96-sequence gates and five-clean-service audits from the baseline are superseded;
do not execute them alongside this sequence.

### DR1 — Preserve evidence and establish deterministic replay

**Outcome:** every result is reproducible locally from immutable inputs, draws and rule versions.

- [ ] Preserve the named source/draw/truth artifacts on private backed-up storage; record the
  partially recovered 949 summary and absence of its complete original response. Label synthetic
  fixtures honestly. Do not depend on scratch retention for acceptance or future re-derivation.
- [ ] Bind source identity, transcript, RMS, audio timeline, attested OoS, policies, code, prompt,
  schema, model/effort and actual tier. Bank raw/parsed/refined outputs, failures and usage per slot.
- [ ] Extract reusable orchestration into existing application/evaluation locations. Reuse sound,
  validation, flag and hold services. Provide deterministic composition/replay with read-only
  evaluation and explicit persistence; avoid another scratch or historic-only implementation.
- [ ] Test idempotence, stale-input rejection and evaluator parity. Evaluation must not mutate
  authoritative runs/sections/segments/holds/publication/freeze state; private evidence writes are
  explicit. Local rule changes must not require new LLM calls.

### DR2 — Implement the ensemble and extraction-aware agreement

**Outcome:** supported claims compose the output; disagreement protects the decisions it affects.

- [ ] Implement explicit per-call models and four stable slots (2 × 5.6, 2 × 6) over identical
  inputs. Fix gpt-6 request shaping and preserve flex fallback/actual-tier usage accounting.
- [ ] Implement bounded subprocess isolation, per-child outcome collection and durable slot
  results. Prove context restoration, timeouts/abrupt exits, sibling survival, cleanup and safe
  resume with real subprocess tests. No completed invalid draw is retried into a passing vote.
- [ ] Define/test one-to-one matching, non-transitive tolerances, split/merge conflicts, identity
  comparison and stable tie resolution. Include references, absence and every extraction-relevant
  boundary/filler decision. Treat reduced model/draw coverage as degraded and review-required.
- [ ] Compose supported boundary pairs and coherent fields; ER1 selects a complete accepted
  prayer alternative, never a midpoint. Preserve existing flags/confidence policy and provenance.
- [ ] Propagate disputes through actual extraction plans, including song boundaries, omitted
  readings, no-sermon completion and baseline fallback. Test outcomes, not merely flag strings.
- [ ] Replace validation retry and automatic single-model reading-recheck adoption. Preserve the
  original regression behaviours in ensemble tests. An optional reading diagnostic is review-only,
  bounded and not a default fifth call. Defer shadow deletion until its non-voting role is replaced.

### DR3 — Complete the review-answer-to-correction loop

**Outcome:** each answer repairs current output and survives reprocessing without repeated questions.

- [ ] Bank scoped rulings with source/transcript identity, anchors, alternatives, operator and
  revision history. Replace approximate-span-only suppression with verified evidence matching.
  Changed evidence or ambiguous/conflicting mappings stay stale/unresolved, never silently cleared.
- [ ] Apply local answers as constraints, then recompose, validate and update dependent extraction
  plans. Resolve only the named issue; preserve unrelated holds and publication controls.
- [ ] Deduplicate questions by issue. Resolve presence before edges, support omitted/split/merged
  claims, expandable clip/transcript context, both-acceptable/none-correct/cannot-tell answers and
  deferred status. Do not use blanket section confirmation to implement a narrow spot answer.
- [ ] Prove an answer corrects output, survives changed section rows and replay, and does not
  reopen unchanged resolved questions. Metadata changes do not trigger unnecessary recuts.
- [ ] Separate content corrections, accepted alternatives and general rules. Generalisation needs
  observable preconditions and counterexamples, not recurring wording or disputed model labels.
  Use existing contrasts (949, 936/1356, 1117, 1311 and accepted boundary cases) as regression evidence.
- [ ] Replay candidate rules on saved corpus evidence; enumerate changes, clearances, affected
  media plans and known-correct regressions. Add fixtures before adoption. No further LLM call is
  needed for applying answers, changing composition/validation or measuring equivalence rules.

### DR4 — Predeclare and run the bounded evaluation

**Outcome:** measure classification, composition, containment and review separately on the agreed scope.

- [ ] First run deterministic replay against saved evidence. Extend the DR1 scorer to all claim
  types and actual extraction plans; update ensemble containment mapping and application-policy
  parity. Enforce exact membership and one-to-one matches; keep false/missing/split/merged cases.
- [ ] Separate operator-ruled accuracy from provisional model-consensus stability. Refusals,
  degraded sequences, unreadable evidence and unanswered claims are not clean verified results.
- [ ] Freeze manifests, candidate versions, score rules, draw counts, maximum spend/calls and stop
  conditions before paid work. Follow ensemble §6: 16 services × 3 four-draw sequences, plus
  10 four-draw sequences on 949 (232 base calls, approximately $1.1 before any separately declared
  retry allowance). Do not run the superseded synthetic-repair experiment as an additional arm.
- [ ] Measure pre-review errors, post-review correctness, extraction eligibility, disputed spots,
  degraded/refused runs, latency/cost and review time/reuse. Measure ER1's correctness as well as
  its flag reduction. Answer unknowns through targeted source questions, not invented truth.
- [ ] Report the scope limit: repeated detections on these tuned services do not establish
  unseen-service accuracy. Do not reinstate whole-service markup or a held-out workload by default.

### DR5 — Verification, acceptance and canary 9

**Outcome:** one verified implementation and completed review loop support a concrete dispatch decision.

| Gate | Required evidence |
|---|---|
| Deterministic correctness | Ensemble §5 tests; supported composition, preserved holds, read-only evaluation, repeatable replay and durable scoped rulings. |
| Runtime | Real subprocess partial failure, deadlines, cleanup, staging identity, slot resume and capacity verified. |
| Accuracy | All declared sequences accounted for; ruled errors separate from provisional matches and unresolved claims. A flag is containment, not correctness. |
| Extraction | Integration tests and evaluation of actual cuts/absence decisions; no review bypass through omitted reading, next-song boundary or RMS fallback. |
| Learning loop | A review answer demonstrably repairs and persists through replay; candidate rules have corpus diffs and counterexample tests. |
| Operator work | Questions, corrections, accepted alternatives, deferred/stale issues and minutes reported; no unadopted 10%/five-minute workload tripwire. |

- [ ] Run focused PHPUnit tests, PHPStan, Pint and full parallel suite through Sail; retain output.
  Run Dusk for review interactions and keep Playwright visual-only. Documentation edits do not
  substitute for those implementation checks.
- [ ] Resolve ensemble Q5 before interpreting a different canary bar. The existing **zero
  talk-count errors including flagged ones** remains in force. If changed explicitly to zero
  unflagged errors plus completed review/correction, report pre-review accuracy separately from
  post-review correctness. Do not change the gate after seeing a failure.
- [ ] Present concrete evidence. Only after the dispatch HOLD is explicitly lifted: commit the
  implementation, move the operational freeze, snapshot authoritative state, verify membership,
  routes and holds, restart/verify workers and perform preflight. Plan commits do none of this.
- [ ] Dispatch the complete 16-run canary once, score it and run the custody/hold diff. Finish
  adjudication before advancement; do not rerun 949 alone to erase a failure. A failure stops
  advancement and remains evidence. Parent gates still govern Tier C and public release.

### DR6 — Controlled batches and measured learning

**Outcome:** targeted review improves current outputs and reusable rules before the next batch.

- [ ] Resume only under adopted parent-plan batching/operational controls. Use ensemble disputes
  instead of scratch second draws; retain edge and weak-song selectors, deduplicated by issue.
  The operator's no-whole-service-markup decision remains binding.
- [ ] Apply existing matching rulings before asking new questions. Resolve presence first and
  recompute affected plans after answers; defer cannot-tell without disguising it as resolved.
  Finish the repair/replay loop before increasing volume or advancing affected content.
- [ ] Between batches, test recurring-rule candidates against saved evidence; show changes and
  known-truth regressions before adoption. A smaller queue alone cannot justify a rule.
- [ ] Track distinct services, new/reused/deferred/stale questions, corrections versus accepted
  alternatives, rule-driven reductions, review minutes, degradation/refusals and residual errors.
- [ ] A lost hold or error bypassing review blocks affected progression and requires investigation.
  Explicitly record any changed rollout size or stop threshold before applying it; old proposals
  are not adopted thresholds. Detection acceptance never grants publication authority.
- [ ] Keep the known completeness gap visible: unanimous omissions may produce no question.
  Targeted selectors and banked rulings mitigate only the cases they reach. Additional audits are
  an operator workload decision, not silently required by this revised sequence.

## 3. Completion and unresolved decisions

Complete when the ensemble's deterministic/runtime/review tests pass, the declared evaluation and
canary meet the adopted gates, and reusable evidence, ruling application, replay and batch metrics
are handed back to the parent plan. Archive this work package at historic closeout; temporary
scratch tooling retires under IC8, while routine detection/review/replay remain application features.

The outstanding acceptance decision is ensemble Q5. Exact matching/tolerance fixtures and ER1's
general predicate/measurement must also be settled before their respective implementation and
evaluation gates. Changes to degraded-ensemble policy, evaluation scope or rollout workload need
an explicit recorded decision. The one maintainer can perform every review role. These document
commits do not lift the dispatch HOLD or change publication policy.
