# Ensemble structure detection

**Status — 2026-09-28 (late): PLAN FOR REVIEW. Nothing in this plan is implemented.** The
operator asked for an independent (Codex) review before any code. The freeze (`3ffe4b54c`) and
the canary-8 HOLD are unchanged. No commits have been made.

This plan belongs to the [detection reliability work package](HISTORIC-VIDEO-DETECTION-RELIABILITY-2026-09-28.md)
(§0 records today's rulings and measurements). It replaces that package's DR2 retry repair.
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
   **After each review, rulings that recur become further principles,** so review volume falls
   batch by batch. Each rule is measured against the corpus before adoption.
6. **Run the draws in parallel.** Wall time should be the slowest single call, not the sum.

## 1. Evidence

All measurements use saved read-only draws on the 16 ruled batch-1 services.
Truth is `storage/scratch/detection-truth-20260928.json`; the scorer is
`storage/scratch/detection-score-20260928.py`. Prompt labels p2, p3, p4 and p5order name the prompt
revisions evaluated on 2026-09-28; p5order is the current prompt.

- **Canary 8, run 949.** The rejected first attempt nested one prayer inside another. The stored
  summary reads: "Section 13 (prayer, starts 1661.7s) lies almost entirely inside the previous
  section (prayer, ends 2003.0s)". Blind whole-service regeneration then typed four church updates as
  talks. Ten read-only first attempts of 949 produced **9 clean, 1 with two different false talks**.
  The model's errors here are mostly unstable, not systematic.
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
    Four regression tests exist in `DetectServiceStructureTest`. **This plan deletes these** (§4).
  - `detectAndValidate()` loads the transcript, audio timeline and attested OoS, then runs the
    detector, `snapToSilences()` and `ServiceStructureValidator::validate()`.
  - `runPrimary()` persists through `ServiceSectionSyncService::sync()`, which keys rows by
    `section_order`. It records `service_structure` metadata and rechecks content holds. In
    reconcile mode a failed re-detection keeps the existing sections.
  - `runShadow()` / `detectShadowCandidate()` implement the `shadow` mode with
    `service_structure.shadow_model`. That is a model-upgrade evaluation path that runs *instead of*
    primary. See open question Q6.
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
- Laravel 13's `Illuminate\Support\Facades\Concurrency` is available with the default `process`
  driver. That driver runs each closure in a separate `php artisan` child process with serialised
  closures.

## 3. Design

### 3.1 Draws

- New config `media-processing.service_structure.ensemble` holds an ordered list of models,
  default `['gpt-5.6-luna', 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-6-luna']`. The first-listed model
  is the tie-break. Reasoning effort, prompt, inputs and service tier are the same for every draw.
- The detector gains a way to take the model per call instead of from global config. For example,
  a `model` argument on `ServiceStructureInterface::detect()` defaults to the configured model;
  the mock detector must accept it too. Whether this should be an interface change or a
  per-call detector instance is open question Q1.
- Each draw runs `detect` → `snapToSilences` → `validate`, exactly as `detectAndValidate()` does now.

### 3.2 Parallel execution

- Run the four draws through `Concurrency::run()` (process driver). Each child:
  - re-enters the run's **historic staging context** when it has one, because the context is
    process-local and the child is a new process;
  - loads its inputs itself, runs one draw, and returns a serialisable result: the validated structure
    array, hard failures, usage and latency.
- **Per-draw time budget** (for example 300 s, below the job's 900 s timeout). A draw that errors or
  exceeds the budget is recorded as `unavailable` and does not vote. Canary 8's run 1346 stalled
  600 s on the flex tier; that must not stall the service.
- The queue job keeps its own retries for whole-job failures. The ensemble has no inner retry loop.
- **Open (Q2):** whether the process driver is the right mechanism inside a queue worker. The
  alternatives are a Bus batch of four draw jobs plus a composing job, or concurrent HTTP. Worker memory,
  PHP-FPM/CLI limits, Sail containers and the historic staging guard's per-process baseline
  (`HistoricStagingGuard::$baselineStagingConfiguration`) all bear on this.
- **Rate limits:** four concurrent requests per service. The historic LLM queue has one replica by
  default (`HISTORIC_MEDIA_WORKERS_LLM`). Check the OpenAI tier's TPM against about 4 × prompt size.

### 3.3 Eligibility, replacing the validation retry

- A draw **votes** only if it passed validation.
- **Fewer than two votes** → the existing manual-review path (`llm_structure_validation_failed`)
  with every draw's proposal persisted. In reconcile mode, the existing sections are kept as now.
- Delete `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch of
  `detectWithPrimaryRecovery()`.

### 3.4 Claims, alignment and agreement

- A **claim** is what output depends on:
  - `short_talk`: presence and both edges, ±30 s;
  - `sermon`: start, ±30 s;
  - `song` and `bible_reading`: presence, ≥50% overlap of the shorter span.
- **Align claims across voters by type and time.** A cluster is the set of voters' claims that
  agree pairwise. A spot is **agreed** when every voter has a claim in the cluster. Otherwise it is
  **disputed**. That includes a voter with no claim there, a split, or a merge.
- **Order-of-service binding** of an agreed claim is compared too. Different bound items on the
  same span are a disputed binding.
- **Equivalence rules** run before the agreement test. Each is a named class with a docblock that cites
  the operator ruling and its corpus measurement. It is also listed in the plan's rulings table.
  - **ER1 — prayer before the sermon.** Two sermon starts are equivalent when everything between
    them is a prayer immediately before the sermon (by a preacher, or someone praying for the preacher).
    Ruled 2026-09-28; truth alternatives on 949 and 936 already encode it.
  - Future rules come from banked spot rulings. Before adoption, each is measured against the
    corpus for what it would silence: the measure-before-generalising rule.

### 3.5 Composition

- **Agreed claims:** write the **median** of the voters' boundaries, then re-snap to silence with
  `SilenceSnapService`. A sermon's end is taken as the draws' median, since publication cuts the
  sermon to the next song.
- **Disputed claims:** write the **majority** version. On a tie, write the version held by the
  tie-break model, then flag the spot.
- **Per-claim fields** take the majority across the claim's supporting voters, with ties to the
  tie-break model: title, `oos_item_id`, `song_title`, `reading_reference`, `sermon_reference`,
  `talk_type`, `summary`.
- **Filler** (welcome, notices, prayer, other) and structure-level fields come from the **most
  representative voter**, the one agreeing with the most clusters. It is clipped to the composed
  claims' boundaries. Structure-level fields are notes, notices, chapter markers and sermon
  absence. Filler has no downstream publishing effect, and `SectionReviewFlagPolicy` already
  demotes structural doubt on these types.
- **Validate the composed structure.** If it fails, the run goes to manual review with every draw
  persisted. The composer never writes an invalid structure.

### 3.6 Flags and evidence

- New `ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES` is raised on each written section at a
  disputed spot. For a claim that exists in only a minority of voters and is not written, it goes
  on the written section(s) covering that claim's span. It forces review and blocks
  auto-extraction. It is catalogued.
- `service_structure_ensemble` metadata holds:
  - every draw: model, validation result, usage, latency and sections;
  - the clusters and which voters support each;
  - every disputed spot with each voter's version;
  - the equivalence rules applied.

  The spot-check page and review UI can then ask "which of these is right?" with the alternatives.
- **Re-derivability (Q4):** the flag can be re-derived from the banked draws. Decide whether it joins
  `REANNOTATED_FLAGS`, so a new equivalence rule can clear old flags without paid calls.

### 3.7 Missing-reading recheck

- If **some** voters find the preached reading and others do not, that is a disputed reading
  claim and is flagged.
- The existing recheck (`recheckMissingPreachedReading`) runs only when **no** voter found a reading.
  It stays single-model on the tie-break model, with its talk guard (`shortTalksAgree`).
  **Q3:** should the recheck itself become an ensemble, or be retired once the ensemble proves it rarely fires?

### 3.8 gpt-6 payload

- Widen `OpenAiChatPayload::isReasoningModel()` to include gpt-6, then drop `temperature` and send
  `reasoning_effort`. A unit test covers the model-name table. The scratch driver's per-process
  patch is then deleted.

## 4. Deletions

- Validation retry: `detectionWorthRetrying()`, `detectionRetryFeedback()` and the retry branch.
- Today's uncommitted retry repair and guard: `sectionsDisagreeingOnTalks()`,
  `withRetryTalkChangesFlagged()`, `retryRecord()`, `FLAG_RETRY_CHANGED_TALKS` and its catalogue
  entry. Their four regression tests are replaced by the ensemble tests below.
  - The 949 regression is preserved as an ensemble scenario: one voter produces the talks and
    the others do not.
  - The `service_structure_retry` metadata key is no longer written.
- Shadow mode: **open (Q6).** The ensemble subsumes "run a candidate model beside the bound one".
  Deleting `runShadow()`/`detectShadowCandidate()` and `shadow_model` would remove a second
  model-evaluation path.

## 5. Tests (written first, failing, then green)

- Four agreeing voters produce the composed structure with median boundaries, re-snapped. No flag.
- One voter invents talks (949 shape): majority writes no talk. The spot is flagged, with the voter's
  version kept.
- A 2–2 split writes the tie-break model's version, flagged.
- A voter failing validation (nested prayers, timestamps beyond the recording) does not vote. No retry is made.
- Fewer than two valid voters → manual review with all draws persisted. In reconcile mode the
  existing sections are retained.
- ER1: sermon starts differing only by a pre-sermon prayer are agreed. Differing by a song or a
  reading is disputed.
- A disputed order-of-service binding on an agreed span is flagged.
- A voter that times out or errors is `unavailable`, and the service completes on the others.
- Parallel execution: the historic staging context is honoured in each child, and output lands on the
  service artifact disk. Serialisation round-trips the structure.
- Reading recheck fires only when no voter has the reading. Its talk guard is preserved.
- `isReasoningModel()` covers gpt-6.
- Flag policy: `FLAG_ENSEMBLE_DISAGREES` forces review and blocks auto-extraction. It is catalogued.
- Evaluation draw driver parity: the scratch driver calls the job's own ensemble path without writing.

## 6. Evaluation before canary 9

Read-only, predeclared, paid (≈$0.02 per service-sequence):

| Set | Draws | Purpose |
|---|---|---|
| 16 ruled services | 3 complete ensemble sequences each (48 × 4 calls ≈ $0.9) | Composed-output accuracy against truth, including flagged errors; flag rate; latency |
| 949 | 10 ensemble sequences (≈$0.2) | The canary-8 case: the false talks must never be written unflagged. They should be out-voted. |

Score with the DR1 scorer. Report:

- talk-count errors in the **composed** output, flagged and unflagged;
- span misses;
- services flagged and disputed spots per service, split by claim type and by which models disagreed;
- unavailable draws, and wall-clock time.

Also measure ER1's effect on flag volume.

## 7. Canary 9 and rollout

- Commit, move the freeze, restart and verify workers, run preflight, then run the complete 16-run canary.
- **Canary bar (Q5, operator):** the existing bar is zero talk-count errors including flagged ones. With
  an ensemble, a disputed spot is written as the majority and flagged. Does a flagged error still fail
  the canary, or does the bar become zero **unflagged** errors plus review of every flag?
- Rollout follows the reliability plan's DR6 as amended in its §0:
  - the spot-check selector reads `service_structure_ensemble` disputes, not a scratch second
    draw, and shows the voters' versions as the answer options;
  - edge and weak-song rules are unchanged;
  - rulings are banked, and recurring rulings become equivalence rules between batches.

## 8. Risks

- **Composed structure is not a real model output.** The median and majority fields could produce
  combinations no draw proposed, for example a title from one draw on a span from another.
  Mitigation: re-validation, and field majorities only among voters supporting the same cluster.
- **Systematic errors are invisible:** p3 shows 25% of errors shared by every draw. Spot checks
  on edges and weak songs, plus banked rulings, remain the only guard for that class.
- **Review volume:** 24–70% of services flagged before equivalence rules. Operator stance: volume is
  not an objection; recurring answers become rules.
- **Process-driver concurrency inside queue workers:** memory, context leakage and zombie children
  (Q2).
- **Provider rate limits and flex availability** with four concurrent requests.
- **gpt-6 behaviour drift:** gpt-6-luna has been evaluated on prompts p2/p3 only, not on the current
  prompt. The evaluation in §6 covers this.

## 9. Open questions for review

- **Q1:** Should the per-draw model be an interface argument or a detector instance per model?
- **Q2:** Concurrency mechanism: process driver, Bus batch, or concurrent HTTP. Staging-context safety in each.
- **Q3:** Missing-reading recheck: keep single-model, ensemble it, or retire it.
- **Q4:** Should `FLAG_ENSEMBLE_DISAGREES` be re-derivable (`REANNOTATED_FLAGS`) from banked draws?
- **Q5:** Canary bar with an ensemble (operator ruling needed).
- **Q6:** Delete shadow mode as subsumed by the ensemble?
- **Q7:** Are the claim definitions and tolerances right? Specifically: song/reading presence only;
  sermon start only; ±30 s talk edges. Should the reading reference or song identity be claims?
- **Q8:** Composition by median, versus taking the most representative voter's boundaries at agreed
  spots. Median reduces variance, but can land between two silences; it is re-snapped.
