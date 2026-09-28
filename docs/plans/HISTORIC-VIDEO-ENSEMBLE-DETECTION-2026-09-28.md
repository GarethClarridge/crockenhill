# Ensemble structure detection

**Status — 2026-09-28 (after Codex review): REVISED IMPLEMENTATION PLAN; not implemented.**
The operator requested that the review and deterministic-review-loop recommendations be incorporated.
The pre-revision plan is preserved in commit `2bb569482`. These are documentation commits, not a
new processing freeze: the freeze (`3ffe4b54c`), canary-8 FAIL and dispatch HOLD remain unchanged.
Changing the canary acceptance bar still requires the explicit decision in §9/Q5.

This plan belongs to the [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md)
(§0 records today's rulings and measurements). This is the design authority for its revised
DR1–DR6 delivery sequence and replaces its DR2 retry repair once implemented and verified.
It is a change to the **routine** detection pipeline. Historic and weekly runs behave identically,
following the standing ruling that historic work improves routine processing and gets no special path.

## 0. Operator decisions this plan implements (2026-09-28)

1. **Four equal draws, not a primary plus opinions.** Run two `gpt-5.6-luna` and two
   `gpt-6-luna` detections of the same inputs with the same prompt, in parallel. A spot where they
   all agree is accepted. A spot where they disagree is flagged for review. A different model is
   wanted because it is more likely to hold a different opinion than a second draw of the same model.
   On the canary sample the operator preferred 5.6's version, but that need not hold across the wider corpus.
2. **Compare the whole structure**, not only talks: talks, sermon, songs and readings.
3. **Do not pick one draw to write.** The written sections are a **consensus composed from the
   draws**, so nothing publishable rests on one draw's say-so. 5.6 breaks ties only.
4. **Delete the validation retry** in favour of voting. A draw that fails validation does not vote.
   The retry is the path that produced canary 8's false talks on 949.
5. **Encode operator rulings as equivalence rules.** First: a sermon start that differs only by
   the prayer before the sermon counts as agreement (ruled acceptable either way, 2026-09-28).
   **After each review, recurring rulings become candidates for further principles.** A local
   correction is immediately reusable on matching evidence; generalisation requires observable
   preconditions, counterexamples and measurement against saved corpus evidence before adoption.
6. **Run the draws in parallel.** Wall time should be the slowest single call, not the sum.

Agreement never overrides an existing content hold. The implementation guarantee is that every
decision affecting extraction has supported evidence, and unresolved disagreement reaches the
actual extraction gate. The technical policies below implement this alongside the operator's
preference for deterministic correction/replay rather than further model calls.

## 1. Evidence

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

## 2. Current code this plan changes

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

## 3. Design

### 3.1 Draws

- New config `media-processing.service_structure.ensemble` holds an ordered list of models,
  default `['gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-6-luna']`. Assign immutable slot IDs
  before dispatch; completion order must never decide a tie. The first-listed model is the
  tie-break model, with slot order resolving residual ties between its own differing draws.
- Use explicit per-call model/options on the detector interface, including the mock, rather than
  changing global configuration. Reasoning effort, prompt, inputs and requested service tier are
  identical across slots apart from model; record the actual tier separately after flex fallback.
- Snapshot or content-hash-bind the transcript, RMS, audio timeline, recording identity/duration,
  recording-song policy and attested OoS before dispatch. Children verify the same snapshot;
  do not let independent live reloads turn input drift into apparent model disagreement.
- Bank raw response, parsed structure, pre/post-refinement structure and validation outcome.
  Each draw uses the production `detect` → `snapToSilences` (including `SoundStage`) → `validate`
  path. Composition and replay use those same deterministic services, not scratch copies.

### 3.2 Parallel execution

- Prefer isolated subprocess draws with a controlled runner using the installed process facilities.
  Collect outcomes individually, including parent-enforced timeouts and abrupt exits; catching
  exceptions inside a closure cannot handle a child killed by the parent. A bad child must not
  discard sibling results. Explicitly stop/reap children on normal failure and cancellation, and
  test parent termination/orphan handling in the actual worker environment.
- Capture staging context as serialisable data plus scalar run/slot IDs. In a fresh child,
  reconstruct it and enter `HistoricStagingContextRegistry::within()` before resolving storage.
  Do not serialize activated global config or the registry: the guard needs the child's pristine
  baseline. Queue lifecycle hooks do not run for an invoked closure. Verify artifact disk identity
  and private storage in each child; context mismatch is a failure, not a fallback to another disk.
- Set explicit connection/request and process deadlines (initial candidate: 300 s per draw), with
  a whole-stage deadline below the 900 s job timeout and margin for composition/persistence.
  Flex fallback and any explicitly requested recheck share the remaining budget. A recheck cannot
  add the configured 900 s HTTP timeout after an ensemble. Verify worker/queue timeout alignment.
- Return and durably persist each slot's outcome independently: valid, invalid, unavailable, or
  interrupted/unknown, with usage and latency where known. Use isolated writes/atomic aggregation
  rather than concurrent read-modify-write of the run's metadata. A lost response can have incurred
  provider cost; do not report unknown usage as zero.
- Whole-job retries resume genuinely unfinished slots only when evidence and request versions still
  match. Valid and invalid completed draws are both final evidence: never redraw an invalid result
  until enough votes pass. Retain attempt history for unavailable slots and bound any retry budget.
  Protect slot claiming and final persistence against duplicate workers and stale run revisions.
- Bus batches are an alternative only with an explicit worker-topology change: the historic LLM
  queue defaults to one replica, so four queued draws would otherwise be sequential. Never block
  its sole worker awaiting work on that same queue. Concurrent HTTP would need equivalent async
  flex fallback, usage handling and isolation. Do not introduce either architecture speculatively.
- Check peak memory, Sail/CLI process behaviour, cancellation and rate limits before rollout.
  Account for four concurrent prompts plus output budgets, rather than assuming single-call limits.

### 3.3 Eligibility, replacing the validation retry

- A draw **votes** only if it passed validation.
- **Fewer than two votes** → the existing manual-review path (`llm_structure_validation_failed`)
  with every draw's proposal persisted. In reconcile mode, the existing sections are kept as now.
- Two or three valid votes permit a reviewable composition, but record a **degraded ensemble**.
  Do not label it four-draw agreement or allow unattended adoption of affected extraction/absence
  decisions until reviewed. Record surviving model coverage, especially two votes from one model.
  Any future relaxation needs measured evidence and an explicit policy decision.
- Structural validation is not semantic correctness. Preserve validation flags on eligible draws;
  agreement cannot silently turn an already-held proposal into an unflagged one.
- Delete `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch of
  `detectWithPrimaryRecovery()`.

### 3.4 Claims, alignment and agreement

- A **claim** includes every fact used to choose or describe published content:

  | Claim | Compare |
  |---|---|
  | Short talk | Presence, both edges, talk type and output-relevant binding |
  | Sermon | Presence/absence, both edges, preached reference, continuation and pairing decisions |
  | Song | Presence, identity, OoS binding and boundaries, especially the start used to end a sermon |
  | Bible reading | Presence, reference, OoS binding, both edges and sermon pairing |
  | Adjacent filler | Type/bounds wherever these change inclusion or stopping of an extraction span |

- ±30 s talk edges remain the initial alignment tolerance, not proof that every cut in the interval
  is acceptable. Overlap (including the old ≥50% of the shorter span) is a candidate-match signal,
  never sufficient agreement for songs/readings. Predeclare boundary tolerances and semantic
  identity normalisation before evaluation; justify them against accepted cuts, not desired flag volume.
- Align one-to-one across draws with at most one claim per slot in each cluster. Every member of
  an agreed cluster must agree with every other member. Do not use transitive closure: starts at
  100, 125 and 150 s under ±30 s do not form an agreed cluster. A long reading matching two shorter
  readings is a split/merge dispute, not two votes from the same slot.
- Specify and test deterministic assignment/tie ordering before implementation is accepted.
  Where competing assignments materially change support or output, preserve the alternatives as
  an alignment dispute instead of manufacturing a majority. Results must be invariant to arrival
  order and input array ordering when immutable slot/claim IDs are unchanged.
- A spot is agreed only when all eligible voters support its output-relevant claims; absence of a
  claim, type conflicts, splits, merges, identity/reference/binding differences are disputes.
  Degraded-ensemble review in §3.3 applies separately even if surviving voters agree.
- Canonicalise identities with existing deterministic song/Scripture resolvers and version the
  rules. Null versus a claimed identity is not automatically agreement. Free-text wording is not
  an exact-string vote; titles/summaries use the provenance rule in §3.5.
- Equivalence rules run before agreement, with explicit evidence, scope and version. A disputed
  model label alone must never prove the condition used to suppress that same dispute.

  | Rule | Authority and conditions | Measurement required before general application |
  |---|---|---|
  | ER1: complete pre-sermon prayer may be included or excluded | Operator ruling 2026-09-28; recorded alternatives on 949/936. Everything between starts must be established as that complete prayer, by a matching content ruling or a verified deterministic predicate. Unknown or mixed prayer/reading/song content stays disputed. | Replay saved corpus evidence; list every suppressed dispute and affected cut, including counterexamples. Recorded local alternatives are usable immediately on matching evidence; a general predicate is not yet proven. |

- Additional recurring rulings are candidates, not automatically general principles. §3.10 defines
  the replay, counterexample and regression requirements for adoption.

### 3.5 Composition

- Compose per claim, never adopt a whole authoritative draw. At agreed spots choose an actual
  supported boundary pair from the cluster (for example, the pair minimising total boundary
  distance to the others, then tie-break model/slot order). Do not independently average starts
  and ends. Under ER1 choose one complete accepted alternative: two starts before and two after
  a prayer must never average to the middle of the prayer.
- At disputed spots use the uniquely best-supported version as a **flagged proposal**, counting
  absence as an alternative. A tie prefers a supporting tie-break-model slot, then stable slot
  order; if that model is absent use stable slot order and retain degraded status. An unresolved
  assignment/split/merge remains reviewable evidence, not an unflagged arbitrary partition.
- Select a coherent semantic field bundle from supporters of the chosen claim; do not combine a
  reference, identity and binding no supporter proposed together. Compare these fields as claims
  before choosing the bundle. Select title/summary wording from its representative supporter with
  provenance, rather than treating four different paraphrases as four substantive disagreements.
- Define confidence conservatively (initial policy: minimum among the chosen claim's supporters),
  preserve relevant existing flags, and re-derive applicable deterministic flags. Keep dissenting
  evidence even when its section is omitted. Model confidence is not calibrated ensemble accuracy.
- Filler/notes may use the most representative supporter with stable ties only where this cannot
  change extraction. Where filler changes a cut, it participates in agreement. Rebuild/check
  chapter markers against composed spans. Sermon absence is an explicit voted decision, never
  copied as incidental metadata from the representative draw.
- Apply matching local rulings as explicit constraints, recording before/after and authority.
  Use shared boundary/sound refinement in a fixed order and track changes. Refinement must not
  create a new unsupported cut or erase an accepted complete alternative; re-evaluate support
  and dependencies after any movement. Start each replay from immutable evidence, not the last
  already-refined output, so repeated replay cannot drift.
- Validate the complete composition and derive its actual extraction plan. Structural validity
  alone cannot prove semantic agreement. If invalid, retain proposals for manual review; reconcile
  keeps the previous authoritative sections. No invalid composition reaches section sync.

### 3.6 Flags and evidence

- New `ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES` is raised on each written section at a
  disputed spot. For a claim that exists in only a minority of voters and is not written, it goes
  on covering sections. If no section covers it, retain a run-level unresolved dispute; do not lose
  questions in gaps. Catalogue disagreement/degradation signals and define their policy explicitly.
- Propagate each unresolved dispute to every dependent extraction/absence decision. A song-start
  dispute can hold the sermon cut; a reading dispute must not be bypassed by silently omitting it;
  a missing-sermon dispute cannot conclude the run as no-sermon. Prove these through the actual
  resolver/job/publication paths, including RMS fallback and manually reviewed paths. Keep valid
  existing operator authority and unrelated content holds intact.
- `service_structure_ensemble` references a private, immutable evidence bundle containing:
  - run/source identity; hashes or snapshots of transcript, RMS, audio timeline, OoS and policy inputs;
  - prompt/schema, code and deterministic-rule versions, requested models/effort/tier;
  - stable draw/attempt IDs, raw/parsed/refined structures, failures, actual tier, usage and latency;
  - alignment, alternative clusters, votes, degraded status and per-field/boundary provenance;
  - composed output, extraction-plan dependencies, disputes, applied rulings and rule versions.
  Store large bodies on the existing private service artifact disk, with references in metadata;
  back them up with the service artifacts before disposable scratch can be removed.
- Re-derive disputes without paid calls only when the evidence bundle is complete and still maps
  to current content. Missing/stale evidence means unresolved, not agreement. Extend the established
  re-derivation path with an evidence-aware ensemble pass; do **not** merely add the flag to
  `REANNOTATED_FLAGS`, whose current validator pass cannot reconstruct it and could clear it blindly.
- Rule changes produce a reviewable before/after diff with their version and affected decisions.
  Preserve historical proposals, rulings and holds; flag withdrawal is not publication approval.

### 3.7 Missing-reading recheck

- If **some** voters find the preached reading and others do not, that is a disputed reading
  claim and is flagged.
- When none finds it, first apply saved rulings and existing deterministic reference/pairing checks.
  Ask a targeted question only when the missing reading is consequential under existing policy.
- Remove automatic whole-structure adoption by the single-model recheck. If retained as an
  explicitly requested diagnostic, its response is **review evidence only**, within the remaining
  deadline/budget; it cannot replace the ensemble or clear its flags. No default fifth paid call.
  Any later automated recovery must meet the same claim/support and extraction gates.

### 3.8 gpt-6 payload

- Widen `OpenAiChatPayload::isReasoningModel()` to include gpt-6, then drop `temperature` and send
  `reasoning_effort`. A unit test covers the model-name table. The scratch driver's per-process
  patch is then deleted.

### 3.9 Deterministic replay and scoped recomputation

Model detection supplies evidence. Composition, boundary/sound refinement, validation, ruling
application, extraction planning and question generation form a shared deterministic replay path.
Evaluation invokes the same services without syncing sections, synthesising segments, clearing
holds, publishing or moving freeze state. Private evaluation artifacts are allowed and identified.

| Change | Required work |
|---|---|
| Equivalence, validator, alignment or composer rule | Replay saved raw/parsed draws with the new deterministic versions; no detection calls |
| New operator ruling | Recompose/revalidate affected content and dependent extraction decisions; no detection calls |
| Metadata-only correction | Update validated metadata/review state; do not recut unchanged media |
| Corrected media boundary | Recalculate the plan and use existing authorised re-extraction/publication controls |
| Model, prompt or reasoning settings | New explicitly scoped detection evidence; preserve the old bundle |
| Source/transcript/OoS change | Invalidate dependent results; remap rulings only with verified matching evidence, otherwise mark stale |

Replay is deterministic and idempotent: same evidence/rules/rulings gives the same semantic
output; a second application causes no boundary drift, repeated recut or reopened resolved issue.
Timestamps in audit records do not count as semantic differences. Persist results only against
the run/content revision they were computed from. Extend existing services and evaluation tooling;
do not grow another scratch driver, historic-only pipeline or general-purpose rules framework.

### 3.10 Review answers as durable constraints and measured rules

Before scaling reprocessing, complete **answer → correction → deterministic replay → verification**.
Banking an answer or suppressing a question alone does not complete a repair.

| Ruling kind | Example | Authority |
|---|---|---|
| Content correction | This span is not a standalone talk; its end is this anchor | This recording and matching evidence |
| Accepted alternatives | Include or exclude this complete prayer | The enumerated alternatives on this content |
| General principle | A verified observable pattern admits the same treatment elsewhere | Versioned predicate, tested counterexamples and corpus measurement |

- Use stable content/question identity rather than `section_order` or row ID. Bind source identity,
  transcript hash, span, anchor, question scope, alternatives, answer, operator and ruling revision.
  A changed transcript/timebase or ambiguous remapping marks a ruling stale/conflicting. Approximate
  times alone cannot authorise reuse. Preserve superseded answers instead of overwriting history.
- Apply matching rulings to output, not just the question selector or score. Record original and
  corrected values; revalidate chronology, coverage, claim relationships and affected media plans.
  Contradictory rulings stay unresolved rather than silently letting the last writer win.
- Generate one specific question per underlying issue, grouping duplicate flags/model proposals.
  Resolve presence/type before edges/identity when those depend on presence. Rejecting a talk
  removes its now-irrelevant edge questions. A question remains attached to omitted claims too.
- Present the relevant clip and transcript with expandable context and actual alternatives.
  Keep model labels secondary. Support "both acceptable", "none correct", a specific correction
  and "cannot tell". Retain cannot-tell as deferred on unchanged evidence, not completed and not
  repeatedly rediscovered as a new question. Surface deferred/stale totals for the operator.
- Resolve only the question's named issue and its mechanically obsolete dependants. Do not use
  blanket section confirmation to clear other flags or content holds. The review UI must support
  the correction needed, including starts, splits/merges and omitted claims; the current short-talk
  end-shortening form alone is insufficient. Reuse shared components/actions and project UI skills.
- Recurring answers propose a rule with explicit preconditions, version, local examples and
  counterexamples. Replay against the saved corpus before adoption; list changed outputs, cleared
  questions, changed extraction plans and any regression of a known-correct decision. An uncertain
  semantic pattern remains a targeted question rather than a brittle keyword/LLM-label rule.
- Keep regression fixtures for the ruling and its counterexamples. Adopt a rule only when its
  predicate and observed effects support the generalisation; repeat frequency alone is insufficient.
- After each batch report new/reused/deferred/stale questions, actual corrections versus accepted
  alternatives, questions avoided by rulings/rules, review time, refusals/degradation and known
  errors after replay. Fewer flags is not success if errors or unjustified clearances increase.

## 4. Deletions

- Validation retry: `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch.
- Today's uncommitted retry repair and guard: `sectionsDisagreeingOnTalks()`,
  `withRetryTalkChangesFlagged()`, `retryRecord()`, `FLAG_RETRY_CHANGED_TALKS` and its catalogue
  entry. Preserve their behavioural regression coverage in the ensemble scenarios below; the
  project rule against deleting existing tests still applies. Do not simply remove four guards
  because their implementation names disappear.
  - The 949 regression is preserved as an ensemble scenario: one voter produces the talks and
    the others do not.
  - The `service_structure_retry` metadata key is no longer written.
- Missing-reading recovery: remove automatic adoption of a single recheck's entire structure.
- Shadow mode: **defer deletion**. A voting ensemble member affects output; a non-voting candidate
  is an evaluation instrument. Delete the old mode only after its useful evaluation role has an
  explicit tested replacement, in a separately bounded change. It is not needed to ship this plan.
- Move reusable evaluation/review/replay logic into existing application locations. Retire scratch
  duplicates only after parity and artifact preservation; temporary tooling retains IC8 ownership.

## 5. Tests (written first, failing, then green)

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
- ER1: sermon starts differing only by a pre-sermon prayer are agreed. Differing by a song or a
  reading is disputed; unknown prayer evidence is not enough. Two starts on either side of a prayer
  select a complete supported alternative, never its midpoint or an internal silence.
- Different references, identities, OoS bindings, continuation/absence decisions and consequential
  filler are disputes. Free-text paraphrases do not invent semantic disagreement. Confidence and
  existing flags follow the explicit policy; unrelated holds survive every composition/replay.
- Validate real extraction outcomes: disputed next-song start, omitted reading, notice versus
  prayer tail, no song after sermon, sermon-end movement and disputed absence. Review reaches the
  actual extraction/publication gates, including baseline fallback, not just a flag-string test.
- Real subprocess integration tests: one timeout, provider error, abrupt exit, sibling results
  retained, parent cancellation/cleanup, staging mismatch and artifact identity. Fake or synchronous
  drivers alone cannot prove these. Test flex fallback and whole-stage deadlines.
- Completed slots survive worker retry; no invalid-draw replacement, duplicate slot claim, stale
  input reuse or concurrent metadata loss. Record interrupted/unknown usage honestly.
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

## 6. Evaluation before canary 9

First run zero-provider-cost replay and deterministic tests against saved draws/rulings. Fix the
composer, scorer and review loop before buying fresh evidence. Freeze their versions and the input
manifest for evaluation; log any subsequent change as a new candidate rather than mixing results.

Fresh detection evaluation remains read-only and predeclared (≈$0.02 per four-draw sequence):

| Set | Draws | Purpose |
|---|---|---|
| 16 batch-1 services | 3 complete ensemble sequences each (48 × 4 calls ≈ $0.9) | Composed-output accuracy against ruled truth; provisional regression stability reported separately; flag rate; latency |
| 949 | 10 ensemble sequences (≈$0.2) | The canary-8 case: the false talks must never be written unflagged. They should be out-voted. |

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

## 7. Canary 9 and rollout

- Finish and verify the reusable answer/correction/replay loop before scaling reprocessing. Complete
  implementation/evaluation and present concrete results. Only after the dispatch HOLD is explicitly
  lifted: commit the implementation, move the operational freeze, snapshot authoritative state,
  restart/verify workers, check membership/routes/holds and run preflight and the complete 16-run canary.
  Documentation commits do not move the freeze or authorise paid calls, dispatch or publication.
- **Canary bar (Q5, operator):** the existing bar is zero talk-count errors including flagged ones. With
  an ensemble, a disputed spot is written as a flagged proposal. Keep the existing bar unless the
  operator explicitly adopts zero unflagged errors plus completed review/correction of every dispute.
  Under either policy a flagged error remains an accuracy error, not a correct detection. Record
  pre-review accuracy and post-review correctness separately; do not relax a gate after seeing failure.
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

## 8. Risks

- **Composition can be wrong despite validation.** Use supported boundary pairs and coherent field
  bundles, compare extraction dependencies, retain dissent and verify actual downstream decisions.
- **Shared errors remain invisible to voting.** The p3 table reports 25% error capture, not 25%
  shared errors. Targeted edge/weak-song checks and banked rulings help only where their selectors
  reach. A unanimously missed talk can still escape; do not claim completeness from no disputes.
- **Review volume:** 24–70% of services flagged before equivalence rules. Operator stance: volume is
  not an objection. Reuse local answers immediately, but do not lower correctness to reduce flags.
- **Stale rulings and over-generalisation:** approximate span matching or a rule justified only by
  a disputed model label can silently reproduce an error. Bind evidence and test counterexamples.
- **Process isolation and partial failure:** explicit budgets, durable slots, cleanup and real worker
  tests are required. Check capacity; a timeout is not a negative content vote.
- **Provider rate limits and flex availability** with four concurrent requests.
- **gpt-6 behaviour drift:** reported comparisons use p2/p3, not the current prompt. §6 measures the
  current candidate on the bounded sample, without claiming unseen-corpus accuracy.
- **Review tooling scope:** the current scratch bank/selector and broad confirmation action do not
  implement scoped durable corrections. Finish this loop before increasing processing volume.

## 9. Review dispositions and remaining decisions

| Question | Disposition from this review |
|---|---|
| Q1: per-draw model | Explicit per-call options; no global mutation (§3.1). |
| Q2: concurrency | Controlled isolated subprocess runner; prove partial failure/deadlines/staging with real workers (§3.2). |
| Q3: reading recheck | No automatic whole-structure replacement or default fifth call; optional diagnostic is review-only (§3.7). |
| Q4: flag replay | Evidence-aware deterministic re-derivation; no blind addition to the generic flag list (§3.6). |
| Q5: canary bar | **Operator decision remains open.** Existing zero-error bar stays in force; containment and accuracy stay distinct (§7). |
| Q6: shadow | Defer deletion until a tested non-voting evaluation replacement exists (§4). |
| Q7: claim scope | Include identities/references, absence and all extraction dependencies; predeclare exact boundary/normalisation rules before evaluation (§3.4). |
| Q8: boundaries | Select supported pairs/complete alternatives, not arithmetic medians (§3.5). |

Before coding the composer, settle the deterministic matching objective, residual assignment
ties and per-claim tolerances in fixtures. These are implementation specifications to measure, not
permission to weaken the acceptance bar. ER1's general predicate needs its corpus measurement;
until then apply only evidence-backed local alternatives. Any relaxed degraded-ensemble gate,
expanded paid evaluation or changed rollout workload requires an explicit recorded decision.
