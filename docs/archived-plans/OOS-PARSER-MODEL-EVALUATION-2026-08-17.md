# OoS Email Parser: `gpt-5.6-luna` Non-Inferiority Evaluation

> **Status (2026-08-18): CLOSED without a model verdict. The current `effort=none` prompt, schema and
> parser configuration is not viable for source-exact, repeatable extraction, which is logically
> prior to any model comparison.** Both arms ran, are banked and are
> provenance-identical apart from the declared model; raw discordance is real and re-verified
> (`M = 460` primary / `536` all-tier). But §6.2 step 2's within-arm stability gate fired and, after
> two rounds of genuine comparator fixes and one corrected diagnostic per arm, still does: asked to
> parse the same email twice, **nano returns a materially different scored extraction 80.0% of the
> time and Luna 63.3%**, against a 10% threshold. Item structure alone changes in 21/30 nano pairs
> and 8/30 Luna pairs, independently putting both arms above the threshold. The decomposition also
> shows changes in dates, services, content scope and routing, so the finding cannot be explained
> away as confidence/prose noise or service-plan ordering.
> A non-inferiority test between two arms that each disagree with *themselves* on 63–80% of
> sources cannot answer the question it was built to answer.
>
> **Nano stays configured only as the unchanged status quo. Luna is neither adopted nor rejected.**
> Luna was directionally more stable in this 30-source sample, including fewer disagreements in
> every recorded substantive field group, but the paired overlap needed to establish that difference
> inferentially was not retained. Recording either model as the winner would therefore be wrong.
> Do not adjudicate the 536 discordant sources.
>
> **Update (round six, 2026-08-18): the reasoning-effort half of that revival question is now
> answered, and the answer is no.** A `nano/low` arm at `n = 100`, provenance-identical down to the
> parser-surface hash, returns **77.0% self-disagreement** against `none`'s 80.0% — and moves
> `routing_category` 8pp in the *wrong* direction, flipping sources between `review_required` and
> `auto_importable` between two parses of the same email. Reasoning does bite where predicted
> (`item_structure` 70% → 45% of sources) but the instability redistributes rather than clearing.
> **Do not run `medium`, `high` or `xhigh`, and do not open another model evaluation.** The remaining
> lever is the prompt and schema surface, where 33.5% of first-pass extractions already fail
> deterministic validation. See §12 round six.
> §12 rounds three, four and five record the two comparator defects, the bound computed
> before spending, and the diagnostic that closed it. Three earlier design versions were retired
> before this — a five-arm model search, a superiority-gated statistical programme, and a first
> non-inferiority draft whose cheap-labelling rule and validation rules could each have changed the
> decision incorrectly. §12 records every removal and correction, so this document is not re-inflated
> or re-broken later.
>
> **This is a non-inferiority question.** Verified 2026-08-17 against official OpenAI documentation:
> `gpt-5.6-luna` is the same-tier successor to `gpt-5.4-nano`, identical on input price and ~4%
> cheaper on output, and `gpt-5.4-nano` carries no deprecation notice or retirement date. So the
> decision is **"is Luna a safe replacement?"**, not "is Luna better?". **Parity is a pass.** The
> reason to settle it now, with no deadline, is that the finding can still change the decision — under
> a forced migration it could not.
>
> **Nothing here changes production configuration.** Every run is measurement-only against a freshly
> provisioned rehearsal database that **the runner itself selects and certifies** (§5.2). Adoption
> authorises a separate reviewed configuration change (§10). Rollback is one setting.
>
> **Owner boundary.** IC3 measurement work under
> [incremental convergence](../plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md). Read-only
> evidence selection, never mutation authority and never an import gate.

## 1. Decision to make

> Does `gpt-5.6-luna` at effective reasoning effort `none` extract the OoS email at least as
> faithfully as `gpt-5.4-nano` / `none`, within a declared margin, without regressing safety, routing
> correctness, cost, latency or review burden?

| Result | Decision |
|---|---|
| Luna within the §7.3 margin, no guardrail breach | **Adopt Luna** as production candidate |
| Luna breaches the margin, or any guardrail | Stay on nano |
| Discordance too large, or instability too high, for either conclusion | Stay on nano; report that as the finding |

The middle and last outcomes are real answers, not failures. The first is the expected one.

A second, strictly optional question arises only after Luna/none is adopted:

> Does Luna/`low` reduce a *specific observed* Luna/none failure class enough to pay for its
> reasoning tokens and latency?

OpenAI's migration guide is explicit that when the workload runs at `none` you should "keep it as
your latency baseline and also test `low` when the workflow benefits from reasoning or tool use".
That contemplates a `low` arm; it does not require one. This plan requires an observed Luna failure
class before Phase B is worth running. Terra, Sol and higher efforts are outside this plan.

## 2. Why non-inferiority, and why now

Official documentation fetched 2026-08-17 and re-confirmed on review:

| Model | Input / 1M | Cached input / 1M | Output / 1M | Role |
|---|---:|---:|---:|---|
| [`gpt-5.4-nano`](https://developers.openai.com/api/docs/models/gpt-5.4-nano) | $0.20 | $0.02 | $1.25 | Current baseline |
| [`gpt-5.6-luna`](https://developers.openai.com/api/docs/models/gpt-5.6-luna) | $0.20 | $0.02 | $1.20 | Same-tier successor |

Prices are a dated design input, never the billing authority. Take one official price snapshot for
the configured service tier before the arms are frozen and hash it into the run manifest.

Three facts set the gate direction:

1. **No forcing function.** Nano is documented as active. Nothing compels a move today.
2. **No saving worth chasing.** 4% on output tokens does not justify migration risk alone.
3. **Nano will not be supported forever.** Same-tier successors exist because predecessors retire.

Demanding a demonstrated quality *gain* from a same-tier successor at parity price would predictably
return "stay on nano" even where Luna is a perfectly adequate replacement — spending the
implementation effort to learn nothing. Re-verify prices and deprecation status when the snapshot is
taken. **If nano acquires a retirement date, rewrite this plan around a deadline**: the question
becomes "when must we move?", and a failing evaluation no longer changes the answer.

## 3. What is measured

### 3.1 Primary estimand: faithfulness to the email

The parser controls only what it extracts from the verbatim email, so the truth is what the email
states: service boundaries and identities, content scope (`full` / `partial` / `unknown`), the
ordered item list, item types, exact supporting source-line ids, and song titles as written before
catalogue resolution.

The primary endpoint is **source-exact correctness**: every service, item, type, order and
source-line binding correct for that source email. Paired by source email. **An unusable parse counts
as source-incorrect**, never as a missing observation.

An `indeterminate → mismatch` transition is improved coverage, not improved accuracy. Record it as a
silent failure made visible; never count it as a correct identity or item.

### 3.2 The primary must read the *raw* parse, not the resolved one

Verified 2026-08-17. The earlier claim that "the parse is resolver-independent" was too broad and is
corrected here:

- `SongTitleResolver` is called by `HistoricItemGroundTruth::generate()` and `OosArchiveEvaluator`,
  but **not** by `OosEmailParserService::parse()` or `OpenAiOosEmailItemExtractor`. Catalogue
  resolution genuinely is downstream.
- **But `OosArchiveIdentityResolver::resolve()` runs immediately after the parse** and does
  `resolveMissingDates()` → `resolveIdentity()` → `applyCuratedContentScope()`. It backfills missing
  dates from the manifest's ground-truth date and **overwrites the model's content scope with the
  curated one**. Comparing the resolved result would therefore conceal exactly the model identity and
  scope errors this evaluation exists to detect.
- The raw parse survives in the cache binding as `raw_result` with a `raw_result_hash`, but
  `OosArchiveParseCacheBinding::evidence()` **strips `raw_result`** before reports and per-entry
  evidence see it.

**Requirement.** Before teardown, the arm runner exports a versioned, source-keyed projection of the
raw result: raw service identities and content scope, ordered items with types and source-line ids,
stated confidence, routing category, and `raw_result_hash`. **The comparator's primary analysis
consumes that projection and nothing else.** Resolver- and manifest-dependent staged data is
secondary diagnostics only (§3.3).

### 3.3 Secondary diagnostics: agreement with later service evidence

The hymn workbook and OpenLP answer a different question — whether the email plan agrees with what
was later sung or projected. A service can change after the email was written, so disagreement is not
automatically a parser error.

| Dimension | Evidence | Valid meaning |
|---|---|---|
| Song membership | Hymn workbook | Agreement with songs recorded as sung |
| Song count | OpenLP | Agreement with songs projected |
| Song order | OpenLP | Agreement with projected song order |

These are **descriptive only**: transition matrices, counts and raw differences. They decide nothing,
they never override a source-faithful primary result, and **they carry no p-values.** Reporting
unadjusted McNemar p-values on three dimensions would reintroduce multiplicity through the back door
— so `ItemGroundTruthArmComparison`'s per-dimension `mcnemar` p-values and its `holmAdjust()` are
both removed (§9.1). Removing only the correction would be worse than keeping both.

### 3.4 Curation tiers and the primary denominator

Evidence availability and curation scope are fixed before parsing and may bound a denominator.
Verdict classes may not.

| Tier | Meaning | Treatment |
|---|---|---|
| `full` | Curated source presents a complete running order | **Primary population, `N_primary`** |
| `partial` | Source names selected material only | Separate paired scope/safety diagnostic; never scored as a missed full order and **never in `N_primary`** |
| `no_source` | No current email source exists | Acquisition gap; no model inference |

`N_primary` is the **full-scope source count**. An earlier draft defined the eligible set as `full`
plus `partial` and then applied the §7.3 margin to that combined `N`, which diluted the margin with
sources the plan itself excludes from extraction correctness. The existing comparator already encodes
`PrimaryTier = 'full'`; this aligns the denominator with it.

Tier binds to the exact contributing source set. `sourceRecords->first()` is not a valid rule for a
service with multiple current email lineages: derive one unambiguous tier from every contributing
source or fail as `mixed`, never scored. A missing tier never defaults to `full`. (12 rehearsal
services carry more than one current email source record and 7 of those disagree on
`payload_complete`.)

### 3.5 Explicitly out of scope

**Confidence calibration.** AUC, Brier scores, reliability bins and threshold fitting are removed.
IC3 has already measured confidence AUC at 0.52–0.61, so the signal is too weak to discriminate two
models; population calibration cannot be computed from a label set enriched for disagreement; and
threshold policy is a separate reviewed decision a model migration must not quietly make. What
*replaces* it is narrower and paired: the routing-safety guardrail of §7.4.1.

**Absolute accuracy claims.** No absolute precision or recall figure is reported as a population
rate, because the label set cannot support one. Only paired between-arm differences are inferential.

## 4. Corpus and arms

### 4.1 Eligible source set

No new warehouse artifact. The eligible set is the `full` and `partial` email sources of the approved
curation manifest; `N_primary` is the `full` subset (§3.4). The runner records the manifest hash, the
derived source-key list, that list's SHA-256, and both counts. Both arms bind to the same hash. No
parse output, confidence or match verdict may influence membership.

Report by era, source-length quartile, single/multi-service and format family, to expose distribution
shift between historic and weekly sources.

### 4.2 Arms

| Arm | Model | Configured effort | Effective effort |
|---|---|---|---|
| A | `gpt-5.4-nano` | `none` | `none` |
| B | `gpt-5.6-luna` | `none` | `none` |

Production's configured `minimal` normalises to effective `none` for GPT-5.4+, so this is a clean
model-only comparison. Run A and B as close together as operationally possible with identical source
order, concurrency, prompt, schema, ceilings and retry policy. Pin dated model snapshots where the
account exposes them; otherwise record the alias and every returned model value, and fail the arm if
the returned model changes within it.

Freeze prompt, strict schema, parser version and resolver surface before either arm runs. Any prompt
change is a separate candidate intervention — never change prompt and model in one comparison.

## 5. Run integrity

These controls exist for one reason: **an arm that is quietly wrong produces a clean-looking result
that is false, with no later signal.** Controls that merely prevent inconvenience are excluded — a
full arm costs about a dollar, so rerunning beats building trusted recovery logic.

### 5.1 The run must prove which model it used

The highest-value control and the cheapest. Before any spend the runner prints the resolved `config()`
values it will use — model, configured and effective reasoning effort, completion ceiling, service
tier, database connection and database name — and refuses on any mismatch with the declared arm.

This matters because Laravel's config cache, `.env` state and Sail env propagation can each silently
serve a different value than the operator believes, and this application already has a recorded
incident of a defaulted config making a whole run silently fake. A comparison that ran the same model
twice yields a perfect, meaningless "no difference".

The arm mapping is fixed in code and **applied inside the running process**, then read back from
`config()` and asserted. There is no `config:clear` step in the procedure: clearing a cache proves
nothing about what the process then resolved, and its presence would imply the runner depends on
ambient configuration it should be setting itself.

Per call, assert `response_model` consistency and **fail the arm if the returned model changes within
it**.

### 5.2 The run must select and certify its own database

`ProvisionRehearsalDatabaseCommand`'s docblock states that it "deliberately does not" point
`DB_DATABASE` at the database it provisions, "so that switching targets stays an explicit operator
act". An earlier draft of this plan provisioned a rehearsal database and then ran the arm against
whatever `DB_DATABASE` happened to be — the precise failure §5.1 exists to prevent.

**The runner therefore selects the named rehearsal connection, reconnects, and certifies it
in-process** before any spend: expected connection name, expected database name, rehearsal marker
present, production anchor absent
(`HistoricImportProductionGuard::guardsCurrentEnvironment()` already provides the last).

Other refusals: stale or mismatched arm config, missing API key, existing output path, unexpected
model or effort, source-set hash mismatch.

### 5.3 The run must prove it covered everything

Bind the arm to the source-key list hash and expected counts. If any source fails after its bounded
retries, mark the **entire arm incomplete**. An incomplete arm is never compared: comparing the
surviving intersection lets the run's own outcome choose the population, which is the same class of
error as filtering a verdict class out of a denominator. There is no `--resume`.

### 5.4 Cache, log and telemetry isolation

- Freshly provisioned rehearsal database per arm. `OosArchiveParseCacheBinding::rawCacheKey()`
  contains neither model nor effort, so a shared cache would score one model twice.
- Separate log file per arm in addition to `OPENAI_EVALUATION_ARM`; scraping a shared `laravel.log`
  is not reliable attribution.
- **Telemetry is one-to-many per source.** A source can produce several calls — retries, and
  distinct call roles. Key each usage record by source key **plus attempt number and call role**, so
  cost and latency per source are derivable without re-running and a retry is never mistaken for a
  second source. Record `usage_missing: true` rather than dropping a call.
- Record per-call latency in the arm run, so §7.4's p95 guardrail is computable without a second pass.
- Export the §3.2 raw-result projection before teardown. This is the one export whose absence cannot
  be repaired later: the rehearsal database is dropped by the next provisioning run.

### 5.5 Live compatibility canary

Before corpus spend each arm parses three deterministic canaries: a short full order, the longest
eligible full order, and a multi-service email. Three calls to validate an arm before committing
hundreds. The canary must prove the alias is available to the account, the returned model is
consistent, effective effort and service tier are correct, strict JSON schema succeeds, usage is
present and source-addressable, the token ceiling leaves visible JSON, the raw-result projection is
populated, and no strict line-id, date or scope invariant fails. Canary failure stops the arm. Unit
tests fake the network and never substitute for this check.

### 5.6 Retry and timeout policy is not a prerequisite

`config/openai.php` sets `request_timeout` but no connect timeout, and transient failures are not
distinguished from permanent 4xx. Both are real defects **of the production parser** and both should
be fixed — as independent maintenance, on their own schedule, not as a gate on this evaluation. Both
arms run the identical current policy, so no contamination arises either way; a stalled arm is rerun.
Keep only the classification that already exists and is needed here: `truncated` versus
`unusable_response`, checked via `finish_reason` before empty content becomes a generic failure. Keep
concurrency identical and bounded across arms so provider load cannot become an arm effect.

## 6. Labelling

Model calls are the cheap input here; human attention is the scarce one. But cheapness must not be
bought with a selection risk, and an earlier draft's "label only enough to confirm the split" rule
did exactly that.

### 6.1 Two different kinds of discordance

These were conflated in an earlier draft and the distinction is now load-bearing:

- **`M` — raw discordance.** The count of sources where the arms' outputs differ at all: identities,
  scope, item list, types, order, source-line bindings, **or routing category** (§7.4.1). Computable
  with no labels.
- **`b` and `c` — correctness discordance.** `b` = Luna correct and nano wrong; `c` = nano correct
  and Luna wrong. These require adjudication.

A raw disagreement can also be **both arms wrong, differently**, which belongs to neither `b` nor
`c`. Therefore `b + c ≤ M`, and **`b + c` cannot be known before adjudication.**

### 6.2 Label every raw-discordant source

**Step 1 — run both arms on the full eligible set. No labels.** Both arms are cheap (§2); parse
everything rather than sample.

**Step 2 — measure within-arm instability.** Choose 30 sources by `sha256(evaluation_id +
source_key)` and run each arm twice on them before its full run. Compare each arm's two outputs **for
equality**, not for correctness — no labels exist yet, so a correctness-based rule would be
unsatisfiable.

Instability has a consequence; it is not reported and ignored:

| Observation | Consequence |
|---|---|
| Both arms stable (< 10% self-disagreement) | Proceed; report the floor alongside `M` |
| Material instability in both arms (≥ 10%) | Run two full replicates per arm and compare replicate-consistent outcomes only |
| **Asymmetric** instability (one arm materially less stable) | That is itself a finding about the candidate. Repeat the subset at higher `n`; if it persists, **refuse inference** and report it — a less deterministic parser is not a safe replacement even at equal accuracy |

**Step 3 — compute `M`,** and size the labelling work with §7.3's threshold.

**Step 4 — label every raw-discordant source.** Adjudicate each against its verbatim lines: which
arm, if either, is faithful to the email. Below the §7.3 threshold this is bounded work by
construction — under ~83 sources at `N_primary ≈ 500`. There is **no partial-labelling rule**: with
`M` unlabelled sources outstanding, every one of them could be a nano-only win, so stopping early on
favourable labels is optional stopping wearing an efficiency costume.

If partial labelling is ever reinstated, it requires a declared deterministic label order and a stop
rule that only fires when **a worst-case allocation of every unlabelled source cannot change the
result**. Nothing weaker.

**Step 5 — test** (§7.3), then apply the guardrails (§7.4).

**No concordant control sample.** Identical shared extraction error is unchanged from nano, so
non-inferiority is legitimately blind to it. But identical *extraction* is not identical
*operational risk* — see §7.4.1, which replaces the random control with a targeted, paired
adjudication.

**No sequential-looks problem.** The discordant set is enumerated once, before any label is opened,
and labelled completely. Any later expansion batch is a declared second look.

### 6.3 Diagnostic challenge set

Report, never as an unbiased denominator: full-scope zero extractions after resolver normalisation;
clean-title workbook-more-than-email mismatches; long, multi-service and previously retrying sources;
known source-line, date, scope and item-type disagreement cases. The previously counted 40
one-directional workbook mismatches and 15 full-scope zero extractions are provisional pre-run
figures and must be recalculated against current code. Label all of the rebuilt cohorts, not an
impressionistic sample.

## 7. Comparison and decision rule

### 7.1 Unit of analysis

The independent unit is the **source email / model call**, not the service identity. One email may
produce several correlated services sharing a call. The existing comparator is identity-level and
must be extended to a source-level primary; its identity-level matrices stay as diagnostics.

### 7.2 Fail-closed comparison, and what must *not* be fatal

Before any rate or interval, the comparison validates and returns non-zero on failure, emitting only
a create-once `incomplete` diagnostic — never an inferential result or decision label:

1. Supported format, version constant and runtime shape for every input artifact.
2. Unique source keys; duplicates fatal.
3. **One-sided source emails** — a source present in one arm's projection and absent from the other:
   fatal run incompleteness.
4. Zero **curated** drift: identity allowances, evidence availability, tier, curation, catalogue,
   parser and prompt/schema hashes must be identical across arms.
5. Neither arm incomplete; no missing call telemetry.
6. Requested/returned model consistency and exact run-manifest binding.
7. Input SHA-256s recorded in the output.

**What must not be fatal.** An earlier draft required identical *identity sets* and made every
one-sided identity fatal. That is wrong for the primary endpoint: **if Luna misses a service or
invents an extra one, that is the model outcome the evaluation exists to score.** Aborting would hide
the regression rather than count it. The distinction:

| One-sided thing | Treatment |
|---|---|
| Source email | **Fatal** — the arm did not cover the corpus (§5.3) |
| Model-produced service or item within a shared source | **Scored** over the union of both arms' outputs, with absence represented explicitly |
| Curated identity allowance or evidence binding | **Fatal** — that is drift in the fixed inputs |

Malformed rows and unknown outcomes are never skipped; missing tiers never default to `full`.

### 7.3 The margin, the interval, and how much labelling it needs

**The acceptance statement, stated as the maintainer's risk tolerance rather than dressed up as a
derivation:**

> **At most 3 additional source-exact failures per 100 full-scope sources is acceptable, provided
> there are zero hard-safety regressions and zero Luna-only false auto-imports.**

So `δ = 3` percentage points, frozen before the arms run. At `N_primary ≈ 500` that is about 15
additional wrongly-extracted sources. Change `δ` before the run if you disagree, never after.

**The decision interval.** Adoption requires the **lower one-sided 95% bound on `Luna − nano`
source-exact correctness to be above `−δ`**, computed with a **paired score interval (Newcombe's
method for the difference of paired proportions)**, or an exact unconditional matched-pair
non-inferiority test where discordance is very small.

Not a paired Wald interval: its coverage is unreliable precisely when discordance is small or
imbalanced, which is the regime this plan expects. And **not McNemar's exact test**, which tests
`b = c` and cannot by itself test a non-zero `−3pp` margin.

**The planning heuristic.** For sizing the labelling work only, the plug-in Wald standard error is
`sqrt((b + c) − (b − c)²/N) / N`. Dropping the second term gives `sqrt(b + c)/N`, which is exact at a
tie and conservative away from one, so at `b = c` an exact tie clears the margin when

```
b + c  ≤  (δ·N_primary / 1.645)²
```

`1.645` is the one-sided 95% normal quantile. At `N_primary = 500`, `δ = 0.03` the threshold is
**83.16, so counts up to and including 83 pass** at a tie.

Because `b + c ≤ M` (§6.1), applying this threshold to the *observed raw* `M` is conservative in the
safe direction: if `M` clears it, `b + c` certainly does. What the threshold sizes is the work, and
what it can never license is leaving discordant sources unlabelled (§6.2 step 4):

- **`M` at or below the threshold:** label all `M` sources. Bounded work; a tie would pass, so the
  labels exist to confirm the split is not lopsided against Luna.
- **`M` above it:** label all `M` sources; the adjudicated split decides the outcome.
- **`M` far above it:** report that as the finding. Disagreement at that scale between two same-tier
  models — checked against §6.2 step 2's noise floor — says more about parser stability than about
  model choice, and should be investigated before any migration.

The runner prints actual `N_primary`, `M` and the computed threshold; do not carry the worked example
as a constant.

### 7.4 Guardrails

Any breach fails the arm even if §7.3 passes.

1. **Safety (hard):** zero new strict source-line or phantom-line violations; date-, identity- and
   scope-invalid results remain held; no unattended finalisation and no publication boundary change.
2. **Completeness (hard):** every expected source processed and bound to telemetry; zero curated
   drift (§7.2).
3. **Routing correctness (hard):** §7.4.1.
4. **Item recall:** the lower 95% paired bound on **source-normalised** supported-item recall is above
   `−5` percentage points. Source-normalised, not pooled: a pooled corpus recall would need truth-item
   denominators for unlabelled concordant sources, which do not exist.
5. **Review burden:** the share of plans routed to review does not change by more than 5 percentage
   points in **either** direction without a recorded acceptance. A decrease is not automatically
   favourable — it is how §7.4.1's failure mode would first appear.
6. **Cost:** measured cost per source no more than 10% above nano at the frozen snapshot. Prices are
   at parity, so a material regression here means the run is wrong, not the pricing.
7. **Latency:** p95 model-call latency no more than 25% above nano, with no increase in timeout,
   truncation or failure rate.

#### 7.4.1 Routing-safety adjudication

Two arms can return the **same wrong items with different confidence**, so Luna crosses the
auto-import threshold where nano was held. Extraction-only comparison is blind to this, and a
one-directional review-burden guard would read the resulting drop in review volume as an improvement.

Therefore:

- **Routing-category disagreement counts as raw discordance** for §6.1, not only extraction
  disagreement.
- **Every source where Luna becomes auto-importable while nano was held is adjudicated**, whether or
  not the extraction differs.
- **Hard guardrail: zero Luna-only auto-importable incorrect plan.** One suffices to fail the arm.

This is paired, targeted and fully supported by the labels already being gathered. It reintroduces no
absolute precision claim and no calibration fitting.

### 7.5 Optional Phase B

Only after Luna/none is adopted, and only against an observed Luna/none failure class. Reuse the
frozen source set and labels; create fresh parses. Luna/`low` must pass every §7.4 guardrail and
demonstrate a reduction in that specific failure class — unlike the migration this is not
price-neutral, since reasoning tokens bill against the same budget as the visible JSON. Truncation
from an inadequate ceiling is classified separately from model quality but still makes the
model/effort/budget combination unacceptable until fixed and rerun. No Terra or higher-effort arm is
implied by a Luna/`low` failure.

## 8. Executable procedure

One new command: a thin Artisan command around an injected runner service, so the orchestration is
testable without the console. It sets arm configuration inside a single process and reads it back
(§5.1), and it selects and certifies its own rehearsal connection (§5.2). It writes only to a private
`storage/scratch` run directory — `--output` is a name within that directory, not an arbitrary
absolute path.

```bash
# 1. Build a certified clean rehearsal database. This does NOT switch the app's connection —
#    the runner does that itself, in-process, and refuses if it cannot certify the target.
vendor/bin/sail artisan historic-import:provision-rehearsal-database

# 2. Baseline arm: config echo and refusal, connection certification, canary, stability
#    replicate, full corpus, raw-result projection export, telemetry, manifest, checksums.
vendor/bin/sail artisan service-tracking:run-oos-parser-arm \
  --arm=baseline-nano-none \
  --manifest=storage/scratch/oos-curation-manifest.json \
  --output=baseline-nano-none

# 3. Reprovision, then the candidate arm. Export first — provisioning DROPS the database.
vendor/bin/sail artisan historic-import:provision-rehearsal-database
vendor/bin/sail artisan service-tracking:run-oos-parser-arm \
  --arm=luna-none \
  --manifest=storage/scratch/oos-curation-manifest.json \
  --output=luna-none

# 4. Raw discordance M against the instability floor, plus the labelling threshold (§7.3).
#    Emits no decision label without --truth.
vendor/bin/sail artisan service-tracking:compare-ground-truth-arms \
  --baseline=baseline-nano-none --candidate=luna-none

# 5. After adjudicating every raw-discordant source, the decision.
vendor/bin/sail artisan service-tracking:compare-ground-truth-arms \
  --baseline=baseline-nano-none --candidate=luna-none \
  --truth=source-faithfulness-truth.json --output=adoption-comparison.json
```

Secondary workbook/OpenLP diagnostics stay with the existing ground-truth builder rather than
becoming runner flags, so the runner's contract is "produce one arm's raw evidence" and nothing more.

No `--resume`. A failed source makes the arm incomplete and it is rerun whole from a newly
provisioned database. Commands refuse to overwrite artifacts. Run directories are `0700`, artifacts
`0600`. Portable reports contain no raw email body, secret, local absolute path, user id or private
note. The provider-side spend cap is the hard financial stop; if it ends a run the arm is incomplete,
and missing results are never extrapolated into a pass.

## 9. Implementation and tests

### 9.1 Build

**Deterministic baseline**

- Rebuild the item ground-truth artifact against current code and catalogue state.
  `item-ground-truth-2026-08-16c-resolver-fixed.json` predates the latest resolver change and has
  neither the `scoring` block nor `curation_tier`; it is analysis history, not a runnable baseline.
- Multi-current-source tier semantics: one unambiguous tier or `mixed`, never scored, never
  defaulting to `full`.
- Recalculate `N_primary`, every tier split and every challenge cohort.
- Finish `SongTitleResolver` decoration normalisation with its regression tests — needed for the §3.3
  diagnostics, **not** a gate on the primary (§3.2).
- Create-once source-faithfulness label format with a version constant and runtime shape validation.
  "No schema validator" means no external JSON Schema dependency, not unvalidated artifacts.

**Arm runner** (`service-tracking:run-oos-parser-arm`, new — thin command, injected service)

- Apply the fixed arm mapping in-process, print the resolved `config()`, refuse on mismatch.
- Select, reconnect to and certify the named rehearsal connection; refuse if uncertified.
- Per-call `response_model` assertion; fail the arm if the returned model changes within it.
- Source-key list hash binding, expected-count coverage check, incomplete-arm marking.
- **Versioned, source-keyed raw-result projection export** (§3.2) before teardown.
- Per-arm log file; telemetry keyed by source **plus attempt and call role**; latency per call;
  `usage_missing` recorded rather than dropped.
- Live compatibility canary; within-arm stability replicate with the §6.2 consequences.
- Plain-JSON run manifest with a version constant: evaluation and arm ids, model and effort,
  ceilings, every input hash (source-key list, curation, hymn, OpenLP, catalogue, price snapshot),
  parser version, prompt/schema hash, parser-surface commit, application commit.

**Comparator** (`service-tracking:compare-ground-truth-arms`, existing — extend and prune)

- Consume the raw-result projection for the primary; staged data for diagnostics only.
- Add §7.2 fail-closed validation, **including its three-way one-sided rule** — union scoring for
  model-produced identities, fatal only for one-sided sources and curated drift.
- Add the source-level primary: paired transitions, the §7.3 Newcombe paired score interval against
  `−δ`, and the labelling threshold report.
- Add the §7.4 guardrail table, including §7.4.1's routing-safety adjudication.
- **Remove `holmAdjust()` and the per-dimension McNemar p-values.** Once §3.3 is descriptive there is
  nothing to correct, and leaving unadjusted p-values in place would be worse than either extreme.
  The docblock's "five arms across three dimensions is fifteen comparisons" is a fossil of the
  retired design.

Both evaluation-specific surfaces are one-shot tooling. Their class docblocks must declare deletion
after the report is accepted and no rerun remains, or at historic-import IC8 closeout at the latest.

### 9.2 Tests

1. **Wrong-model and wrong-database refusal** — declared arm versus resolved config; returned model
   changing mid-arm; uncertified or production-anchored connection refused.
2. **Incomplete coverage** — a failed source marks the arm incomplete; an incomplete arm is refused
   by the comparison; a one-sided *source* is fatal.
3. **One-sided identities are scored, not refused** — a candidate-only and a baseline-only
   model-produced service each score over the union with explicit absence; a one-sided *curated*
   allowance is still fatal. This is the regression test for the defect that would have hidden Luna
   inventing or dropping a service.
4. **Raw-versus-resolved boundary** — the primary reads the raw projection; a manifest-backfilled
   date or overwritten content scope must not make a wrong model identity or scope look correct.
5. **Drift** — evidence, tier, curation, catalogue, parser and prompt/schema hashes; unknown verdict
   or tier; a missing tier never defaulting to `full`; mixed-scope services.
6. **Telemetry** — usage and latency keyed by source plus attempt and call role, so a retry is not
   counted as a second source; `usage_missing` recorded not dropped; log isolation; truncation
   classified via `finish_reason` before empty content becomes a generic failure.
7. **Statistics** — the Newcombe paired bound against `−δ` at known counts, including a tie, a
   lopsided split and very small discordance; the threshold's boundary case at exactly 83; unusable
   parses counted as source-incorrect; `b + c ≤ M` never assumed equal.
8. **Routing safety** — a same-extraction, higher-confidence Luna plan that crosses auto-import while
   nano was held is adjudicated and, if incorrect, fails the arm; a review-share *decrease* beyond
   the band is not silently favourable.

All automated tests fake OpenAI and prevent stray requests. The only live calls are the operator
canaries and the arm runs. Quality gates remain the repository defaults: focused tests, PHPStan at
zero, Pint, then the full parallel suite.

## 10. Production migration and retirement

Passing authorises a separate reviewed configuration change only:

```text
OOS_EMAIL_PARSING_MODEL=gpt-5.6-luna
OOS_EMAIL_PARSING_REASONING_EFFORT=none
```

It alters no threshold, prompt, schema, retry policy, auto-import, finalisation or publication
boundary. Roll back to `gpt-5.4-nano` if a hard invariant fails; reprocess affected held evidence
rather than mutating published state.

**Post-adoption verification is an evidence gate, not a waiting period.** There is no soak window —
gating on "four weeks" would be a calendar timer in an evidence costume. Every source processed under
the new setting is checked until the evidence is in hand:

- zero strict source-line or phantom-line violations;
- zero unattended finalisations the previous configuration would have held;
- zero Luna-only false auto-imports (§7.4.1);
- usage, latency and review routing within §7.4.

If the historic corpus is being reprocessed the gate can clear the same day; otherwise it stays open
until enough live sources have passed, with no deadline either way. Once clear: retire nano as an OoS
production candidate, retain its baseline artifacts and price snapshot as evidence, decide separately
whether Phase B is worth running (§7.5), and delete the evaluation-only surfaces.

## 11. Review surface

| Change | Production effect before approval |
|---|---|
| Ground-truth rebuild, tier semantics, resolver normalisation | Deterministic measurement quality; resolver change separately tested |
| Arm runner: config echo, connection certification, model assertion, canary, raw projection, telemetry | Rehearsal-only |
| Comparator: fail-closed validation, union scoring, source-level primary, Newcombe bound, Holm and p-value removal | Read-only artifacts |
| Connect timeout and retry classification | **Changes the production parser** — independent PR, not part of this evaluation (§5.6) |
| Luna production configuration | **Separate reviewed change** (§10) |

The final report must answer, without leaving interpretation to the operator:

1. Was every arm complete, connection-certified and provenance-identical apart from the declared
   intervention?
2. What was raw discordance `M`, and how does it compare with each arm's disagreement with itself —
   symmetrically or asymmetrically?
3. Was the lower Newcombe paired bound on source-exact correctness above `−δ`, over the full
   adjudicated discordant set?
4. Did every guardrail pass, including zero Luna-only false auto-imports?
5. What changed in coverage, safety, review burden, cost and latency?
6. What did the workbook/OpenLP diagnostics show descriptively, without mislabelling service changes
   as parser errors?
7. Is the answer *adopt Luna*, *stay on nano because Luna regressed*, or *stay on nano because the
   corpus or the parser is too unstable to say*?

## 12. Review history: what was removed and corrected

Recorded so this plan is neither re-inflated nor re-broken.

### Round one — removals

An external review found the 733-line version demanded a superiority proof it did not need and
promised analysis its sampling design could not support. Both findings were correct.

| Removed | Reason |
|---|---|
| Superiority gate | Wrong question for a same-tier successor at parity price (§2) |
| Absolute item precision ≥0.98 | Computed on a disagreement-enriched set, so not a population rate |
| AUC, Brier, reliability bins, threshold fitting | Population quantities an enriched label set cannot produce; IC3 already measured AUC at 0.52–0.61; threshold policy is a separate decision (§3.5) |
| 20-source random concordant control | Superseded by §7.4.1's targeted routing adjudication, which is paired and label-supported |
| Clustered bootstrap; Holm collector | Nothing to correct once §3.3 is descriptive; Holm **and** the p-values are deleted from existing code (§9.1) |
| Cost per additional source-exact success | Presupposes a demonstrated gain |
| Source-set warehouse artifact | The curation manifest plus a hashed key list is sufficient (§4.1) |
| Four of five proposed commands | Preflight, canary and verification fold into the runner; disagreement reporting into the comparator (§8) |
| Retry/timeout PR as prerequisite | Both arms share the current policy, so it cannot contaminate the comparison (§5.6) |
| Milestone structure (M0–M2) | The contingent milestone existed to defer statistics now deleted outright |

### Round two — corrections

A second review found the first non-inferiority draft proportionate but not yet adoptable. All of the
following were verified against the code and accepted.

| Defect | Correction |
|---|---|
| Raw discordance `M` used as if it were correctness discordance `b + c` | Distinguished in §6.1; `b + c ≤ M`, unknowable before adjudication |
| "Label only enough to confirm the split" | **Removed.** Every raw-discordant source is labelled (§6.2); partial labelling would be optional stopping. Any future partial rule needs deterministic order plus worst-case-allocation stopping |
| Paired Wald interval as the decision rule | Newcombe paired score interval, or exact unconditional matched-pair test; Wald retained only as the planning heuristic. McNemar's exact test cannot test a non-zero margin (§7.3) |
| Threshold written `b + c < 83` | Off by one — the threshold is 83.16, so `≤ 83` passes (§7.3) |
| One-sided identities fatal | **The worst defect of the draft.** A missed or invented service is the model outcome; aborting would hide the regression. Three-way rule in §7.2, with a dedicated regression test |
| Primary read the resolved parse | `OosArchiveIdentityResolver` backfills dates and overwrites content scope from the manifest, and `evidence()` strips `raw_result`. The runner now exports a raw projection the comparator consumes (§3.2) |
| `N` = full + partial, margin applied to both | `N_primary` = full-scope only; partial is a separate paired diagnostic (§3.4) |
| Same-extraction, different-confidence risk unguarded | §7.4.1: routing disagreement counts as discordance, Luna-only auto-imports are adjudicated, zero incorrect ones permitted |
| Review-share guard one-directional | Now fails on a ±5pp change in either direction (§7.4 guardrail 5) |
| Instability reported and ignored | Material or asymmetric instability now forces replication or refuses inference (§6.2 step 2) |
| Procedure never pointed the run at the rehearsal database | The provisioner deliberately does not switch connections; the runner now certifies its own (§5.2). `config:clear` removed as a false guarantee (§5.1) |
| Item recall pooled | Source-normalised paired difference; pooled recall would need truth-item denominators for unlabelled sources (§7.4 guardrail 4) |
| Unusable parses unclassified | Count as source-incorrect (§3.1) |
| Telemetry keyed by source alone | Keyed by source plus attempt and call role, since one source produces many calls (§5.4) |
| "No schema validator" | Means no external JSON Schema; artifacts still carry a version constant and runtime shape validation (§9.1) |
| δ justified by "enough to notice" | Restated as an explicit acceptance statement of maintainer risk tolerance (§7.3) |

**One claim of mine withdrawn.** I argued that disagreement-enrichment necessarily biases measured
precision *downward* for either arm, making the old 0.98 bar merely conservative. That does not hold:
where the arms share errors identically, those sources are concordant and excluded, so the direction
of candidate-specific bias is not guaranteed. The metric is removed either way, but the reasoning was
wrong.

### Round three (2026-08-18) — first execution attempt: banked arms, a genuine stability-check
### defect fixed, and a deeper instability finding left open

Both arms were run for the first time. Four things happened, in order; the first two are closed, the
third is a real fix, the fourth is the open item.

**1. Rate limiting, diagnosed and resolved.** Four attempts at the Luna arm failed with OpenAI's
`RateLimitException` before one completed. Root cause: the account was on usage Tier 1 (2,000,000
TPD for `gpt-5.6-luna`) when the first attempt consumed ~1.63M tokens in 564 calls — almost the
entire daily budget — leaving near-zero headroom for the next two attempts regardless of the gap
between them (15 minutes, then several hours). A tier upgrade (more credit purchased) raised the
daily ceiling ~10×, after which the arm completed cleanly. Fixed alongside: the top-level exception
handler discarded OpenAI's rate-limit response headers entirely, so the first three failures gave no
diagnostic information beyond "rate limit exceeded." `App\Support\OpenAiRateLimitDiagnostics`
(new, tested) walks the exception chain for a `RateLimitException` and surfaces its
`x-ratelimit-*`/`retry-after` headers on any future failure of this kind.

**2. Both arms banked, provenance-identical.** `baseline-nano-none-2026-08-18/` and
`luna-none-2026-08-18-run4/` each hold a complete 554-source raw-result projection and run manifest.
`compare-ground-truth-arms` confirmed identical curation manifest hash, source-key list hash, price
snapshot hash, parser surface hash and application commit across both — the only declared difference
is the model. Raw discordance: `M = 460` primary-tier (`446` extraction, `14` routing-only), `536`
across all tiers, against a labelling threshold of `75.05` (`N_primary = 475`). **This M is real and
does not need to be recomputed** — it comes from `OosParserArmPrimaryComparison::extractionSignature()`,
a code path untouched by the round's fix. What blocked a decision was §6.2 step 2's separate stability
gate, printing `inference_refused`.

**3. A genuine stability-check defect, found and fixed.** `OosParserArmRunner::stability()` called two
replicate parses of the same source "disagreeing" if their `raw_result_hash` differed at all — and
that hash covered the *entire* raw result, including `confidence` (a continuous float) and
`validation_reasons`/`content_validation_reasons` (model-generated free-text explanation strings).
Neither of those reproduces verbatim across two independent calls even when the actual extraction is
stable, which is very likely why the baseline reported **100%** self-disagreement on its first run —
suspicious on its face for an established model. The fix (`OosParserArmRunner::stabilitySignature()`)
narrows the equality check to exactly what `OosParserArmPrimaryComparison::extractionSignature()`
scores as discordant — service, date, content scope, ordered items (position, type, section, title,
source title), source-line bindings, evidence line ids — plus routing category, so a source only
counts as self-disagreeing here if it would also count as discordant between arms. A regression test
(`OosParserArmRunnerTest::it_does_not_count_confidence_or_validation_reason_variance_as_self_disagreement`)
proves the fix: run against the pre-fix comparison (`raw_result_hash`) it fails 2/2, reproducing the
100% pattern exactly; against the fix it passes.

A `--stability-only` diagnostic mode was added to `service-tracking:run-oos-parser-arm` (canary +
30-source stability replicate only, ~60–70 calls, no corpus spend, no projection written) specifically
so the fix could be re-checked cheaply without re-running either full arm. It is a debugging aid for
this investigation, not part of the frozen §8 procedure, and should be deleted with the rest of this
one-shot surface.

**4. Open finding: the fix reduced instability but did not come close to explaining it.**

| | Raw hash (pre-fix) | Narrowed signature (post-fix) |
|---|---:|---:|
| Nano (baseline) self-disagreement | 100.0% | **90.0%** |
| Luna (candidate) self-disagreement | 63.3% | **56.7%** |

Both fell by a similar margin (nano −10pp, Luna −6.6pp), confirming the confidence/prose-noise
hypothesis was real — but both remain **far** above the 10% "material instability" threshold, and the
**established baseline is the less stable of the two** under a measure that now only counts fields the
primary comparison itself scores. This is not plausibly a further measurement artifact of the same
kind: the excluded fields were the two most obviously noisy ones, and what remains — service, date,
scope, item list, order, source-line bindings, routing — is exactly what §3.1 defines as the thing
being measured. When asked to parse the same email twice, in roughly 9 cases out of 10 something in
the actual extraction genuinely differs, for the established model as much as the new one.

**What this is not yet known to mean.** Candidates, not adjudicated between:

- Genuine model non-determinism in structured extraction at `effort=none` is simply this high for this
  prompt/schema combination, independent of which model — in which case the evaluation's real
  prerequisite question is whether `none` is a viable reasoning-effort setting for this task *at all*,
  prior to any model comparison.
- The narrowed signature is still stricter than what "the same extraction" should mean — e.g. item
  `title`/`source_title` compared as exact strings would count "Amazing Grace" and "Amazing Grace (My
  Chains Are Gone)" as different even if a human adjudicator would call them the same item, or
  `source_line_bindings`/`service_evidence_line_ids` could shift between calls without the substantive
  conclusion changing. Untested: no disagreeing replicate pair has yet been inspected field-by-field to
  see which specific fields are actually driving the 90%/56.7% figures.
- Something else specific to this prompt/schema/parser version not yet identified.

**§6.2 step 2's literal next step** for "material instability in both arms" is to run two full
replicates per arm and score only replicate-consistent outcomes — but at 90% baseline
self-disagreement that subset could be too small to power the comparison the plan was sized around,
and doing it (roughly doubling spend already made on both arms) is not worth committing to until the
above is disambiguated. **Recommended first step for the next session:** dump several disagreeing
replicate pairs from a fresh `--stability-only` run field-by-field (service/date/scope/each item's
position, type, title, source-line bindings; routing category) to see concretely what is changing
before deciding between running full replicates, further narrowing the signature, or treating
`effort=none` itself as the thing to re-examine.

**State left behind, all uncommitted on `master`:**

- `app/Support/OpenAiRateLimitDiagnostics.php` (new) + `tests/Unit/Support/OpenAiRateLimitDiagnosticsTest.php`
- `app/Console/Commands/RunOosParserArmCommand.php` — rate-limit header reporting on failure; `--stability-only`
- `app/Services/Email/OosParserArmRunner.php` — narrowed `stability()` signature; `stabilityOnly` early return
- `tests/Feature/Console/RunOosParserArmCommandTest.php`, `tests/Unit/Services/Email/OosParserArmRunnerTest.php` — new coverage for the above
- All four: Pint clean, PHPStan zero errors, full affected suite green (54 tests)
- `storage/scratch/oos-parser-price-snapshot-2026-08-18.json` — frozen, re-verified same-day against official pricing
- `storage/scratch/oos-parser-evaluation/baseline-nano-none-2026-08-18/` and `luna-none-2026-08-18-run4/` — both complete, both still valid (unaffected by the stability fix, which touches a separate code path)
- Needs a branch before any commit (changes are on `master` directly)

### Round four (2026-08-18) — external review of round three: one shared signature, and a bound on
### what the recheck can show

Round three's fix was real but incomplete, and its two headline figures were provisional in a way the
round did not record. External review found it, and this round closes it.

**1. The two comparisons still had two definitions.** `OosParserArmRunner::stabilitySignature()`
mapped over the model's emitted `service_plans` array *in the order it arrived*.
`OosParserArmPrimaryComparison` keys plans by `plan_key` and `ksort`s them before comparing. A
replicate pair that returned the same two services in a different order therefore counted as
self-disagreement in the gate while not counting as discordance in the thing the gate protects —
the exact failure mode round three set out to remove, reintroduced one level down by fixing the
narrowing by hand in one of the two places. **142 of the 554 banked sources emit their service plans
non-lexicographically**, so the divergence was reachable, not theoretical.

The fix is structural rather than another hand-matched copy: `App\Services\Email\OosParserExtractionSignature`
is now the only definition of "the same extraction", and both comparisons call it. It keys and sorts
plans by `plan_key`, **rejects** duplicate plan keys instead of silently letting the second overwrite
the first, retains item order within a plan (position is part of what an order of service is), and
excludes confidence, disposition, hold reasons and model-generated explanation prose.

**2. `90.0%` and `56.7%` are therefore provisional, and here is the bound on how far they can move.**
The stability sample is deterministic — 30 sources hash-ordered by manifest hash — so its composition
is computable from the banked projections without spending anything. **Only 11 of those 30 sources
produced more than one service plan.** Plan reordering can only affect a multi-plan source, so the
corrected figures cannot fall below:

| | Recorded (round three) | Floor, if plan reordering explained *every* multi-plan pair |
|---|---:|---:|
| Nano (baseline) | 27/30 = 90.0% | 16/30 = **53.3%** |
| Luna (candidate) | 17/30 = 56.7% | 6/30 = **20.0%** |

So the correction **cannot** rescue either arm past the 10% material-instability threshold, and
cannot explain nano's instability at all. What it *could* do is change which arm is less stable,
which is why the recheck is still worth its ~120 calls. Round three's qualitative conclusion — that
the extraction genuinely moves between two calls on the same email, for the established model as much
as the new one — survives this bound.

**3. The diagnostic now retains what it was supposed to produce.** `--stability-only` previously
printed a rate and discarded the replicate projections, so it could not perform the field-by-field
investigation §12 round three named as the next step. It now writes `stability-diagnostic.json` into
a private `0700`/`0600` run directory (`--output` is required for this mode too), carrying a
`field_decomposition` count of disagreeing pairs per field group — plan keys, service/date/scope,
item structure, titles, provenance, routing category — over *every* pair, plus the full field-by-field
diff of up to 10 of them.

**4. Rate-limit header lookup fixed.** `OpenAiRateLimitDiagnostics` indexed `getHeaders()` by
lowercase name and by `STRTOUPPER`. PSR-7 preserves the casing the server sent, and neither probe
matches the conventional `X-RateLimit-Limit-Requests` an HTTP/1.1 429 is most likely to carry — so
the class would have reported every header absent on exactly the failure it exists to diagnose. It
now reads through PSR-7's `hasHeader()`/`getHeader()`, which the interface specifies as
case-insensitive.

**5. `M` is unchanged, and re-verified.** `extractionSignature()` now delegates to the shared class
rather than implementing the rule itself; the two banked projections contain **no** duplicate plan
keys, so the new rejection cannot fire on them. Re-running `compare-ground-truth-arms` over both
banked arms after the refactor reproduces the banked figures exactly: `M = 460` full-scope
(extraction 446, routing-only 14), `536` all tiers, `N_primary = 475`, threshold `75.0542`. The
refactor is behaviour-preserving on real data, not just on fixtures.

#### Decision, unchanged by this round

- **Do not switch models.** Nano stays configured. `M = 460/475` is far beyond the bounded-labelling
  region the plan was sized around, there is no migration deadline and no material saving — official
  documentation still positions Luna as the cost-sensitive same-tier successor at identical input
  price, which supports revisiting later, not adopting now.
- **Do not re-run the full arms.** Both are banked, provenance-identical and valid.
- **Do not begin adjudicating 536 sources.**
- **Stability remains unresolved** — pending one corrected diagnostic per arm, not another corpus
  evaluation.

#### The one operator action left

Run exactly one corrected pass per arm, against the same deterministic 30 sources (~120 calls each,
no corpus spend):

```
sail artisan service-tracking:run-oos-parser-arm --arm=baseline-nano-none \
  --manifest=<approved manifest> --price-snapshot=<frozen snapshot> \
  --stability-only --output=stability-nano-2026-08-18
```

...and the same for `--arm=luna-none`. Then record the field-level decomposition from each
`stability-diagnostic.json` here and close the evaluation. If the decomposition shows titles or
provenance driving most pairs, the signature is a candidate for further narrowing; if item structure
or plan keys drive them, `effort=none` itself is the thing to re-examine, prior to any model
comparison.

### Round five (2026-08-18) — the corrected diagnostic, run: `effort=none` is the finding

Both corrected `--stability-only` passes were run on the same deterministic 30 sources. The
evaluation closes here.

**Comparability is proven, not assumed.** Both diagnostics record `manifest_hash 2c79b9a5…`,
`source_key_list_hash 8430b272…`, `price_snapshot_sha256 e100a298…`, `parser_surface 251673ca…` and
`application_commit 09d3fc4a…` — all identical to each other and, apart from the commit (which now
carries the shared signature), to the two banked arms. Both recorded the same 30
`sample_source_keys`, and those match the sample recomputed independently from the banked baseline
projection. This is the same corpus slice, re-parsed.

| | Recorded (round three) | **Corrected** | Round four's floor |
|---|---:|---:|---:|
| Nano (baseline) | 90.0% (27/30) | **80.0% (24/30)** | ≥53.3% |
| Luna (candidate) | 56.7% (17/30) | **63.3% (19/30)** | ≥20.0% |

Both land inside the predicted interval. **Both remain far above the 10% threshold.** Descriptively,
the established baseline disagreed more often in this sample, but the diagnostic did not retain the
paired stable/unstable overlap needed to establish a between-arm stability difference inferentially.

**Do not read the 90→80 movement as "the fix explained 10pp."** These are two independent draws from
a stochastic process. At `n = 30` the standard error near `p ≈ 0.85` is ~6.5pp, so a 10pp gap is
under 1.5 SE; the comparison fix and ordinary sampling noise are not separable at this sample size.
Luna moved *up* 6.6pp, which is the same story in the other direction. What the round-four bound
established — that no correction could bring either arm near 10% — is what actually survives.

#### Field decomposition — the two arms fail differently

Disagreeing pairs per field group; a pair may count in several, so the columns do not partition.

| group | nano (of 24) | Luna (of 19) |
|---|---:|---:|
| `item_structure` | **21** | 8 |
| `provenance` | 15 | **17** |
| `titles` | 11 | 7 |
| `service_date_scope` | 7 | **0** |
| `routing_category` | 5 | 4 |
| `plan_keys` | 2 | 2 |

**Nano's instability is structural.** `item_structure` drives 21 of its 24 disagreeing pairs, and the
concrete diffs are not normalisation artifacts: `2023-10-08` extracted **15 items on one call and 20
on the other**; `2020-11-01` 16 vs 13; `2018-11-18` 12 vs 11 with 8 titles changed; `2018-06-03` 2 vs
1. `2022-06-12` produced `morning:2022-06-12` on one call and **`morning:unknown`** on the other,
losing the date and flipping routing. `2020-10-11` and `2017-04-30` flipped `content_scope`
**partial ↔ full**.

**Luna's instability frequently includes provenance, but is not only bookkeeping.** `provenance` —
source-line bindings — changed in 17 of 19 disagreeing pairs, and it never once moved service, date
or scope (`service_date_scope` = 0, against nano's 7). That does not establish that all or most of
those 17 were provenance-only: only 10 detailed diffs were retained, and 3 of those 10 were
provenance-only. Nano's 10 retained diffs were all substantive. Luna also produced clear substantive
changes: `2018-02-04-details` returned **1 item on one call and 7 on the
other, plus an entire extra `evening:2018-02-04` service plan** that the first call never produced,
and `2021-10-31` returned `morning:2021-10-31` vs **`unknown:2021-10-31`**, losing the service.

Of nano's 10 retained diffs, 5 changed the item **count** (boundary/segmentation), 4 held the count
and reclassified `section_type`, 1 was identity-only. Every classification flip is *specific ↔
`other`*: `sermon`↔`other`, `other`↔`childrens_talk`, `other`↔`prayer`/`notices` — the model falling
back to `other` non-deterministically.

*Limitation:* `field_decomposition` counts all pairs, but only 10 diffs per arm are retained
(`MaxRetainedStabilityDifferences`), so the substantive-vs-provenance-only split above is a sample of
10, not a census of 24/19.

#### Conclusion: the prerequisite question, answered

§12 round three listed three candidate explanations. The decomposition rules out the proposition
that the result is *only* a still-too-strict signature: item structure alone changed in 21/30 nano
pairs and 8/30 Luna pairs, both above the 10% threshold before provenance is considered. Dates,
services, content scope and routing also moved — precisely what §3.1 defines as the thing being
measured, and what a further narrowing would have to start discarding to make the number look better.
Asked to parse the same email twice at `effort=none`, the current configuration returns a materially
different scored extraction four times in five for nano and nearly two times in three for Luna.

**So the current `effort=none` prompt/schema/parser configuration is not viable for source-exact,
repeatable extraction.** That statement is deliberately narrower than declaring `effort=none`
unusable for every operational form of the task: reviewed or safely held evidence may tolerate
variation that unattended source-exact extraction cannot. Nor does this experiment isolate effort
from the prompt, schema and parser surface, because no alternative effort was tested. The failed
stability prerequisite is still logically prior to this model comparison: a non-inferiority test
between two arms that each disagree with *themselves* on 63–80% of sources cannot answer the question
it was built to answer, whatever `M` turns out to be. Zero retries and no truncation in either run
(86 and 80 calls) rule out the ceiling defect §12 round two closed.

§6.2 step 2's literal next step — two full replicates per arm, scoring only replicate-consistent
outcomes — is now clearly not worth its spend: at 80% baseline self-disagreement the consistent
subset is roughly 6 of 30 sources, far too small to power the comparison, and would cost another full
corpus run on both arms to discover that.

#### Decision — final for this evaluation

- **Nano stays configured as the unchanged status quo, not as the evaluation winner.**
- **Luna is not adopted, and is not rejected either.** The evaluation did not reach a verdict on
  non-inferiority, and should not be recorded as having found against Luna. It was directionally
  more stable in this sample, including fewer disagreements in every recorded substantive field
  group, but that between-arm difference was not tested inferentially.
- **Both banked arms stay.** `M = 460` is real and re-verified; it is simply not interpretable while
  both arms are this unstable.
- **Do not adjudicate the 536 discordant sources.** Labelling work premised on a stable extraction
  cannot pay off here.
- **Any future work is a new configuration evaluation, not another step in this comparison.** It
  should first establish a prompt/schema/reasoning-effort configuration at which the parser
  reproduces its own source-exact output, then re-ask the model question only if that prerequisite
  passes. `reasoning_token_headroom` (§12 round two) already provides the ceilings a `low`/`medium`
  arm would need, but this closed evaluation does not authorise or require that spend.

Artifacts: `storage/scratch/oos-parser-evaluation/stability-nano-2026-08-18/stability-diagnostic.json`
and `stability-luna-2026-08-18/stability-diagnostic.json` (both `0600`, private).

### Round six (2026-08-18) — the effort arms: reasoning is not the lever, and it makes routing worse

Round five closed the model comparison and named its successor: establish a configuration at which
the parser reproduces its own output, *then* re-ask the model question. That successor ran. It
answers the prerequisite question in the negative, so the model question stays unasked.

#### What was held, and why it was nano rather than Luna

An external review proposed fixing the model to **Luna** and varying effort. That was rejected, on
three grounds:

1. It re-decides the model question round five deliberately left open. Luna is neither adopted nor
   rejected; making it the base of the successor experiment adopts it by default.
2. Its premise is a figure this document already says cannot carry that weight. Luna looked more
   stable (19/30 against nano's 24/30), but round five records that the between-arm difference was
   never tested inferentially — the SE on that difference at `n = 30` is ~12pp against a 16.7pp gap.
3. It varies two things at once. A passing Luna/low arm could not say whether effort or the model
   bought the stability, and adopting it would be a model migration rather than a config change.

**Nano is also where the mechanism pointed.** Round five's decomposition put nano's instability in
`item_structure` (21 of 24 pairs — 15 items on one call and 20 on the other), which is a reasoning
deficit and the thing more reasoning should fix. Luna's was mostly provenance line bindings, which
are arbitrary tie-breaks among near-equivalent source lines and have no reason to respond to effort.

#### The result

`nano-low`, `n = 100`, the same corpus and the same nested sample.

| Arm | Sample | Self-disagreement |
|---|---:|---:|
| nano / `none` (banked round five) | 30 | 80.0% (24/30) |
| **nano / `low`** — the identical 30 sources | 30 | **70.0% (21/30)** |
| **nano / `low`** — full draw | **100** | **77.0% (77/100)** |

Against the 10% ceiling. `low` did not move it.

**Comparability is proven, not assumed.** The diagnostic records `manifest_hash 2c79b9a5…`,
`source_key_list_hash 8430b272…`, `price_snapshot_sha256 e100a298…` and — the one that matters most
— `parser_surface 251673ca…`, all identical to both banked arms. The harness changes this round
required touch the runner, the arm definitions and the command, none of which are in the parser
surface, so the thing being measured did not move. The sample nesting was verified empirically
rather than argued: the first 30 of the 100 `sample_source_keys` are exactly the banked 30.

**The arm is valid, not misconfigured.** All 271 calls carried `effective_reasoning_effort: low`
against `gpt-5.4-nano-2026-03-17`; 211 of 249 sampled calls returned non-zero reasoning tokens
(median 339, max 1713), so the setting was doing work. Every telemetry record is `attempt: 1` —
**zero retries** — and peak output was 2964 tokens against a 12000 ceiling (6000 base + 6000
headroom), so **zero truncation**. The §12 round two ceiling defect is not in play.

**Read the `n = 100` figure, not the 30-source one.** On the shared 30 sources `low` reads 70.0%
against the baseline's 80.0%, which invites "low buys 10 points". It does not: at `n = 30` near
`p ≈ 0.75` the SE is ~8pp, so a 10pp gap is ~1.2 SE — the identical trap round five documented when
nano moved 90→80 and Luna moved *up* 6.6pp. This is the whole reason the sample was made to scale:
at `n = 30` the only observable result whose one-sided 95% bound clears a 10% ceiling is **0/30**
(2/30 bounds at roughly 20%), so an `n = 30` screen can refute a configuration but never clear one.

#### Decomposition — effort worked where predicted, and broke something else

Rates per *source*, so the two sample sizes are comparable (round five's table was per pair).

| group | nano / `none` (30) | nano / `low` (100) | |
|---|---:|---:|---|
| `item_structure` | 21 (70%) | 45 (45%) | **improved** |
| `titles` | 11 (37%) | 18 (18%) | improved |
| `provenance` | 15 (50%) | 33 (33%) | improved |
| `service_date_scope` | 7 (23%) | 17 (17%) | improved |
| `plan_keys` | 2 (7%) | 13 (13%) | **worse** |
| `routing_category` | 5 (17%) | 25 (25%) | **worse** |

The prediction held: reasoning bites on segmentation, and `item_structure` — the dominant failure at
`none` — fell by 25 points. But the aggregate barely moved because the failures redistributed, and
two groups went the wrong way.

**`routing_category` is the one that matters, and it got worse.** Routing decides whether a plan
imports unattended. Concrete flips between two parses of the same email:

- `2023-10-08`, `2019-04-21-pm`, `2025-03-09` — `review_required` on one call, **`auto_importable`**
  on the other. The same source is held for a human once and imported without one the next time.
- `2020-10-11` — `review_required` → `invalid_extraction`.

`plan_keys` moving means a whole service plan appeared, vanished or changed identity:

- `2020-10-11`, `2020-10-04`, `2025-08-10` — `morning:<date>` on one call, **`unknown:<date>`** on
  the other: the service identity lost.
- `2020-05-31` — `unknown:2020-05-31` against **`unknown:2020-06-29`**: the date moved by a month.

§7.4's guardrail already fails a ±5pp review-share change **in either direction** for exactly this
reason, and an 8pp routing move is the failure mode it was written to catch. So `low` is not merely
"no better"; on the axis with operational consequences it is worse than the status quo.

#### An incidental finding worth keeping: a third of extractions fail validation

The call decomposition is `extract: 200`, `correct: 67`, `adjudicate: 0`. The 200 are the 100 sources
× 2 replicates; the 67 are re-asks triggered by deterministic validation failure. So **33.5% of
first-pass extractions at `low` are structurally invalid on their own terms** — before any question
of stability. That is not a retry (every call is `attempt: 1`) and it is not new to this arm; the
banked `none` run shows the same shape (82 calls where 63 were nominal). It is a standing property of
the prompt/schema surface, and it is a much more promising target than the effort knob.

#### `nano-medium` was started and stopped

Launched on a freshly provisioned rehearsal database, then killed by the operator at 134 calls
(~$0.43) once `low` had returned. No diagnostic exists: the artifact is a create-once write that
happens only on completion, so the abort left no partial result that could later be misread. The
residue is a truncated `storage/logs/oos-parser-arm-nano-medium.log`.

Stopping was the right call. `medium` differs from `low` in degree, and `low` moved the aggregate by
3pp while moving routing 8pp in the wrong direction. No plausible `medium` result reaches a 10%
ceiling from 77%, and the decomposition already shows *why* effort cannot get there: it redistributes
instability rather than removing it.

#### Conclusion

**Reasoning effort is not the lever for this task.** `none` and `low` sit 3pp apart at 77–80%
self-disagreement against a 10% ceiling, and the stronger setting is operationally worse where it
counts. Combined with round five, the configuration space this evaluation has now measured is:

| model | effort | self-disagreement |
|---|---|---:|
| nano | `none` | 80.0% (n=30) |
| nano | `low` | **77.0% (n=100)** |
| Luna | `none` | 63.3% (n=30) |

**Do not run `medium`, `high` or `xhigh`, and do not open another model evaluation.** Both remaining
levers are in the prompt and schema surface, and the 33.5% first-pass validation-failure rate says
that is where the defect lives. The next intervention is prompt/schema decomposition or deterministic
post-processing — which is also where the external review landed, now with two measured effort points
behind it rather than an assumption.

Nothing here changes a production default. Nano at `minimal`/`none` remains configured, unchanged and
unendorsed.

#### Harness changes this round required

Three, all outside the parser surface, all tested:

1. **`nano-low` and `nano-medium` arms** on `OosParserEvaluationArm`, with the model held at nano and
   the reasoning for that recorded on the class.
2. **`--stability-sample=`** on `service-tracking:run-oos-parser-arm`. The sample was hard-coded to
   30 while already being seeded on the manifest hash, so the nesting property existed but was
   unreachable. The diagnostic now records `requested_sample_size` beside the drawn `sample_size`, so
   a corpus that ran short is distinguishable from a deliberately smaller run.
3. **Retained-diff cap 10 → 40**, plus `retained_difference_limit` in the artifact. Round five could
   report that Luna's provenance group moved in 17 of 19 pairs but not whether those pairs were
   provenance-*only*, because only 10 diffs survived. The per-diff bounds
   (`MaxReportedPlans`, `MaxReportedItems`) already cap artifact size, so only the count needed a
   limit.

#### A provenance gap this run exposed, and one deliberate non-fix

The `--stability-only` diagnostic carries no `ceilings` block — only the full-corpus report's
`run_manifest` does. So an *effort* arm's artifact cannot prove its own headroom was applied, which
is precisely the arm where the ceiling is load-bearing; it had to be verified from the isolated arm
log instead. The fix belongs in `OosParserArmRunner`, which is not fingerprinted.

Relatedly: the §5.1 pre-spend echo prints `max completion tokens 6000` when an effort arm actually
sends 12000, because the headroom is added inside the extractor. **This should stay unfixed.**
`OpenAiOosEmailItemExtractor` is in the parser surface, so editing it — even a comment — moves
`parser_surface` and orphans all four banked arms from any future one. A console nicety is not worth
that, and computing the ceiling a second time in the command would be the drifting-second-copy defect
this evaluation has already paid for twice.

Artifacts: `storage/scratch/oos-parser-evaluation/stability-nano-low-2026-08-18/stability-diagnostic.json`
(`0600`, private). Approximate spend: $0.56 for the completed arm, $0.43 for the aborted one.
