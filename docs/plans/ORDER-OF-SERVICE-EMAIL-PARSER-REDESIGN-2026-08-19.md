# Order-of-Service Email Parser Redesign

> **CURRENT STATUS (2026-08-20, later): Delivery 7 is DONE. The semantic parser is the only parser;
> the legacy whole-document path is deleted.** Read this block and §9.15 first; everything below is
> the record of how the plan got here and contains statements that were true when written and are
> now superseded. Where they disagree, this block wins.
>
> - **Maintainer approved the Delivery 6 artifact and the production default flip on 2026-08-20**,
>   explicitly accepting that §9.14's retry fix moved the parser surface after scoring, on the
>   grounds that the diff is transport-only and changes no prompt, schema or compiler semantics.
> - **The flip exposed a real production defect before it shipped.** `OosEmailParserService`
>   declared `?OosSemanticParserCandidate $semanticParser = null`, and Laravel's container declines
>   to build an *unbound concrete class* for a nullable parameter carrying a default — so every
>   container-resolved parser service held `null` and the semantic path would have thrown on the
>   first real email. Deliveries 1–6 never caught it because every semantic test builds the object
>   graph by hand. The dependency is now required; `tests/Feature/Services/Email/OosParserImplementationWiringTest.php`
>   is the regression guard, written failing first.
> - **The legacy path is deleted, not retained.** The plan's own deletion gate was already met, and
>   Delivery 7's acceptance required it. The "retain legacy for rollback" framing did not survive
>   review: Mailgun inbound routing has never been configured, the archive import is
>   operator-invoked, and invariant 9 means nothing publishes unattended — so no failure mode
>   existed that a config flip mitigated faster than a deploy. **Rollback is `git revert` plus a
>   deploy.** `OOS_EMAIL_PARSING_IMPLEMENTATION` is gone from config and `.env.example`.
> - **Verification:** 6,988 tests pass (one environmental skip), PHPStan zero errors, Pint clean,
>   Dusk 55 passed. Net −964 lines.
> - **Retained until IC8, deliberately:** the evaluation-arm machinery (`OosEmailExtractionPrompt`,
>   `OosParserEvaluationArm`, `OosParserArmRunner`, the scorer, freezer, adjudicator and
>   safety-fixture harness) and the `email_parsing` model/prompt/attempt config keys those read.
>   Legacy *arms* are no longer runnable, which costs nothing: the banked legacy baselines are
>   artifacts, and §9.14 already established they cannot be re-derived at HEAD.
> - **Nothing in this plan is open.** The §9.12 `outcome_rate` caveat (28.9%) is **closed by
>   maintainer exemption**, and its named remedy — the single-lever item-kind arm — was **declined**,
>   not deferred. The retained tooling exists so that a *future* arm run for some other reason can
>   re-read `outcome_rate` rather than treat it as settled; it is not a pending action. Full detail
>   in §9.15.
>
> - **Candidate:** `OosSemanticAnnotationPrompt::Version = 6`, `gpt-5.6-terra` / `low` / flex,
>   against corpus `14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`.
> - **Delivery 6 comparison artifact:**
>   `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-final.json`, `score_hash`
>   `6d8246a26ed3d4545f8a60de5849f3bd1531d2e9298b39da4ae892199c6d1791`, **`verdict: pass`**.
>   Both the primary and the replicate also pass when scored independently (§9.11).
> - **Gates:** all ten arm-scoped gates pass. Identity 35/38 on both draws against a legacy baseline
>   of 34 and a truth ceiling of 36; gate 10 first-pass rate 13.3% and 3.3% against a 40% baseline;
>   post-repair content findings empty on both draws.
> - **Entry-point parity is no longer gate 9.** It is a precondition recorded in the artifact and
>   evidenced by `tests/Feature/Services/Email/OosParserEntryPointParityTest.php` (§9.7, §9.10). The
>   scorer's format is now `Version = 2`; version 1 artifacts carry a gate 9 and are not comparable
>   by verdict alone.
> - **Open caveat, not a gate failure:** the stability figure is now split (§9.12). `outcome_rate`
>   — the figure the 10% ceiling is about — is **28.9%**, roughly 3× over; the raw `rate` including
>   provenance is 42.1%. `plan_key_disagreements` is 0, so no source's compiled identity changes
>   between draws, and gate 11 is soft and cannot override correctness. Ten of the eleven
>   outcome-moving sources move only on the item-kind axis, so one further single-lever arm has a
>   measured projection of `outcome_rate` → ~2.6%. **Accepted as-is by maintainer decision on
>   2026-08-20 — an explicit exemption, not a bar that was met; the remedy was available and
>   declined. Grounds and what is given up are recorded in §9.12.**
> - **Cheaper-model arm (Delivery 6 step 5), 2026-08-20:** `gpt-5.6-luna` / `low` was run over two
>   full-corpus draws against the same corpus, prompt v6 and parser surface. Both draws reach
>   `verdict: pass` on all ten arm-scoped gates at one tenth the cost — and both are **worse than
>   terra on stability**: `outcome_rate` 50.0% against terra's 28.9%, and `plan_key_disagreements`
>   **1** where terra scored 0. Decisively, the saving is immaterial at this workload: terra costs
>   **$1.42 a year** for weekly parsing and $15.13 for a one-off archive pass, so switching is worth
>   ~$20 over five years. **Closed on evidence — stay on terra**; `OOS_SEMANTIC_ANNOTATION_MODEL`
>   still defaults to `gpt-5.6-terra`. Full record and cost table in §9.13.
> - **Spend to date:** eight paid full-corpus arms, ≈$8.50 at the dated snapshot's projected rate,
>   plus two luna arms and five failed luna attempts at ≈$0.41 (§9.13).
>   No production mutation has occurred at any point; `OOS_EMAIL_PARSING_IMPLEMENTATION` is `legacy`
>   in production and in `.env.example`.
> - **Next action (§9.4 step 10):** maintainer review of the paired artifact, then an explicit
>   production-default approval. Delivery 7 replay, default flip and legacy deletion remain
>   unauthorised until that happens.

<details>
<summary><strong>Superseded status narrative (2026-08-19 → 2026-08-20)</strong> — retained as the record of how the plan reached the state above.</summary>

> **Status (2026-08-19): implementation in progress.** The deterministic source, annotation,
> validation, compiler, targeted-repair, risk-evidence and rollbackable candidate seams from
> Deliveries 1–5 are implemented behind the non-default `semantic_annotations` configuration.
> The refreshed private IC3 item truth now exists at
> `storage/scratch/item-ground-truth-2026-08-19-authority-refreshed.json` (canonical artifact hash
> `8c87a18889e9ed5dc97088a886113d0c14842d02dc6ef55eb59e69eb72284645`) against the
> exact 606-identity / 5,661-active-item rehearsal corpus and the authoritative 16 August hymn
> workbook. Delivery 0 now has a private, mode-`0600`, hash-bound 38-source worksheet at
> `storage/scratch/oos-semantic-evaluation-corpus-2026-08-19-prefilled.json`: the deterministic 30-source
> stability sample plus eight named hard cases, with banked legacy output/routing/validation/usage/
> latency and IC3 corroboration retained as explicitly non-truth machine prefill. Legacy provenance
> deterministically prefills 633 of 918 physical non-blank lines; 285 lines remain structurally
> unclassified rather than being guessed. Its internal corpus hash is
> `13abc76bdf5152c11f10938e3834e1f5e3f3dd0998b2bca910f73e60f7efb168`
> (raw-file SHA-256 `415afd5404ef6a02f357136cc7aa4e690b2e1cfb895bb2f66344eaf225f20439`).
>
> **Adjudication is now complete.** A new `AdjudicateOosSemanticEvaluationCorpus` service and
> `oos:adjudicate-semantic-corpus` command implement the hash-bound overlay called for in the prior
> handoff: it decodes maintainer decisions through the same `OosSemanticAnnotationDecoder` →
> `OosSemanticAnnotationValidator` → `CompileOosSemanticAnnotations` pipeline the live candidate uses,
> so `truth.expected_plans` is always regenerated from `truth.annotations`, never typed independently.
> All 38 sources were adjudicated by Gareth Clarridge on 2026-08-19 against the evidence hierarchy in
> §6.1, working from the verbatim source text first and treating legacy prefill, IC3/hymn/OpenLP
> corroboration and staged item counts as supporting evidence only. The resulting private artifact is
> `storage/scratch/oos-semantic-evaluation-corpus-2026-08-19-adjudicated.json` (mode `0600`, corpus
> hash `d499162c15e010105ded8e0f48087fdab6a14bb1342c1233661de0d9ebf5324a`); it reports
> `completeness.scoreable: true`, `fully_adjudicated_sources: 38`, `pending_sources: 0`, and
> `OosSemanticEvaluationCorpusGate::assertScoreable()` accepts it. The maintainer's working decisions
> remain at `storage/scratch/oos-semantic-adjudication-decisions-2026-08-19.json` (mode `0600`).
> Adjudication surfaced real defects beyond simple line labelling: two sources (2020-05-31,
> 2026-07-05) contain a source-stated date that contradicts the approved authority date — resolved by
> trusting the source text and tagging `unresolved_date` rather than silently substituting the
> authority date; 2020-12-20-carols' legacy prefill mis-tagged four consecutive narration lines as
> four separate `welcome` items rather than reading them as one scripted introduction, corrected to
> the true 18-item structure; two sources (2018-04-15-am, 2016-06-12) carry legacy prefill whose own
> line-ID references are offset by one from the current frozen source, discovered by content
> cross-check and not trusted for those sources; several duplicate "sermon/reading details" recap
> blocks that legacy had counted as second copies of an already-itemised sermon or reading were
> reclassified as `supporting_detail` to prevent double-counting.
>
> Adjudication also fed back into `OosSemanticAnnotationPrompt`, bumped to `Version = 2` on
> 2026-08-19, before any paid arm has run. The additions are narrow, evidence-backed corpus patterns
> the model has no way to infer from the schema alone (a repeated sermon/song recap is
> `supporting_detail` not a second item; numbered outline points and AV/slide notes are
> `supporting_detail`; a hand-off phrase is `transition_marker`; `NIP` marks a non-hymnbook song;
> `continuation` never targets a non-item line or a boundary) — not a general verbosity increase, and
> deliberately excludes anything the richer item-kind enum already makes self-evident to a
> schema-constrained model (e.g. "Communion" → `communion`), since the old parser's enum genuinely
> lacked those values and that alone explains its errors there. Every acceptance-gate call this made
> is auditable in this session's transcript.
>
> **The §6.2 correctness scorer and §6.3 safety fixtures now exist** (`OosSemanticCorrectnessScorer`,
> `OosSemanticSafetyFixtures`/`RunOosSemanticSafetyFixtures`, `oos:score-semantic-candidate`). The
> scorer refuses to emit any metric or verdict when truth is incomplete, the corpus hash has drifted,
> an arm bound a different corpus, an arm's parser surface differs from the scoring surface, the
> baseline diagnostic is not the one the corpus was frozen against, or the arm's source coverage does
> not match the corpus exactly. It reports all eleven §6.3 gates independently, and a gate it cannot
> establish is `not_scored` — which blocks a `pass` verdict without being reported as failure.
>
> Running the scorer against the adjudicated corpus with **truth replayed as the candidate** validated
> it end to end on real evidence and surfaced one architectural finding before any spend: gate 7
> fails, and fails for a deterministic reason no model can change. `OosServiceDateResolver` resolves a
> date for only 16 of 38 sources, where the banked legacy arm resolved 34; the adjudicated truth
> itself scores 16, so this is the compiler's ceiling and not a model result. 21 of the 22 misses have
> no resolvable date at all, and 20 of those 21 are the Sunday on or after the received date — the
> commonest form in this corpus ("details for sun", "Sunday morning", "order of services for Sunday").
> §5.3 step 7 already assigns relative-date resolution from *supplied calendar context* to PHP, and
> `OosEmailSourceDocument::calendarContext()` exists and is unused by the resolver, so this is an
> under-implementation against the plan's own contract rather than a new requirement. Adding that one
> rule would move identity accuracy to 36/38, above the legacy baseline. It is **not** done here: it
> changes `truth.expected_plans`, which are compiled, so the `-adjudicated` corpus would have to be
> regenerated from the retained decisions file, and that is a maintainer decision. The remaining miss
> (`2023-12-25`, Christmas Day, a Monday) and `2026-07-05` (a source-stated date that contradicts the
> authority date, deliberately preserved as `unresolved_date`) are correct as they stand.
>
> A second, smaller finding: `2020-12-20-carols` — an evening service adjudicated from a Carols by
> Candlelight order — trips the legacy compatibility validator's `missing_evening_service_evidence`
> content rule, because neither its evidence lines nor its subject carry an evening or PM token. That
> is the adjudicated truth failing a legacy rule, not the candidate failing. It is a hard case the
> 30-source baseline never parsed, so gate 10 reports it in the hard-case population rather than
> counting it as a regression.
>
> **2026-08-20: gate 7 fixed, per §9.4 step 5 option (a), and the truth corpus regenerated.**
> `OosServiceDateResolver` gained one further rung, below every explicit-date pattern so a
> source-stated date always wins: the Sunday on or after the received date. It is suppressed when the
> service's own evidence lines or subject name a special service, reusing
> `OosEmailExtractionValidator::SPECIAL_SERVICE_PATTERN` (now `public` for this reuse) rather than a
> third copy of the pattern. `oos:adjudicate-semantic-corpus` was re-run over the frozen `-prefilled`
> worksheet and the retained decisions file — the maintainer's line-level decisions were recompiled,
> never re-taken — producing
> `storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json` (mode `0600`, corpus
> hash `14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`), still 38/38 adjudicated and
> scoreable. The 2026-08-19 `-adjudicated` artifact is superseded by this one and retained for audit
> only; it must not be scored against again.
>
> A truth-replay self-check (a script written to and deleted from `storage/scratch/`, leaving no
> retained artifact, consistent with the 2026-08-19 self-check) confirmed the fix end to end against
> the new corpus: every candidate-vs-truth metric is exactly 1.0 (918/918 line identity, 51/51
> boundaries, 526/526 items at precision and recall 1.0, exact order 1.0, item kinds 1.0, 9/9
> continuations, 526/526 title binding, zero bookkeeping defects), and gate 7 now reports
> `authority_identity`: candidate 36, adjudicated-truth ceiling 36, legacy baseline 34 — **passing**,
> above the legacy baseline, exactly as predicted. Ten of eleven scoreable gates pass; gate 9
> (weekly/historic entry-point parity) remains `not_scored`, since it is a property of two code paths
> that one arm can never establish, so the verdict is `incomplete`, not `pass`, for that reason alone.
> The `2020-12-20-carols` hard-case finding is unaffected and still reported separately from the
> 30-source stability comparison.
>
> Paid-evaluation approval was received on 2026-08-19, against the corpus that existed at that time.
> The create-once private candidate-evidence runner and dated price snapshot are implemented; its
> first real invocation refused before the first API request because the truth gate found the 38
> pending records that existed then. No paid call has yet occurred anywhere in this plan. With
> adjudication complete, the resolver fixed and gate 7 now passing against the regenerated
> `-2026-08-20-adjudicated` corpus, Delivery 6 is unblocked on both the truth worksheet and the
> compiler; running the paid arm against that corpus still needs a fresh, explicit maintainer
> go-ahead in the session that invokes it — the 2026-08-19 approval was granted against a corpus that
> no longer exists in that form, and that go-ahead was deliberately not sought or given in the
> 2026-08-20 session either. Delivery 7 remains unstarted: no signed passing comparison exists, no
> production-default approval has been requested or granted, and production remains on `legacy`.
> This plan replaces the queued prompt/retry
> sequence in `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md` as the
> executable plan for the permanent shared email parser. The report and the archived
> [model evaluation](../archived-plans/OOS-PARSER-MODEL-EVALUATION-2026-08-17.md) remain evidence.
>
> The [historic incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
> remains the sole owner of corpus authority, IC3 ground truth, historic staging/apply rounds,
> evidence tiers, finalisation policy, release and historic closeout. This plan owns only the
> parser used by both weekly and historic email intake. It must not delay IC1, start IC5 contrary
> to REV-D4, weaken the identity/date manifest gate or add a second historic-import sequence.
>
> **Human authority required:** approve the paid live-model evaluation before Delivery 6; approve
> the production default flip before Delivery 7; and record a separate maintainer decision before
> any change to unattended finalisation for dimensions not covered by HIR-D8 corroboration.
> Implementation through deterministic fixtures and fake model responses needs no further product
> decision. No dependency change is authorised.

</details>

## 1. Outcome and value

Replace whole-document stochastic extraction with a compiler-style parser:

```text
raw email
   |
   v
lossless deterministic source document
   |
   v
model: one semantic annotation for every source line
   |
   v
deterministic annotation validation and plan compilation
   |
   +----> narrow patch request for named lines/fields only, when necessary
   |
   v
existing service-plan DTOs and deterministic import/review policy
```

The model decides only what code cannot reliably infer: whether a source line is a service
boundary, service item, transition, continuation, supporting material or context; which candidate
service it belongs to; its semantic kind; and whether that judgement is uncertain. PHP owns exact
text, source order, line accounting, grouping, date resolution, validation, risk signals and import
policy.

**Who benefits:** the operator, who receives fewer noisy reviews and fewer unstable reparses; site
visitors, whose historic and current service records more faithfully reflect the supplied orders;
and maintainers, who can change semantic extraction without coupling it to bookkeeping and policy.

**What observably improves:** missing, duplicated, reordered and ignored-and-claimed line defects
become structurally impossible; whole-document corrective retries disappear; parser changes are
judged against source-faithful ground truth; and every failed or repaired extraction retains enough
evidence for later adjudication.

## 2. Why redesign rather than tune the current prompt

`OosEmailParserService` and `OpenAiOosEmailItemExtractor` currently make one model response carry:

- service discovery and boundary detection;
- service slot, date and completeness;
- item inclusion, order and semantic type;
- exact title copying and continuation handling;
- exhaustive line allocation and ignored-line classification;
- confidence, correction and adjudication inputs; and
- data later used by deterministic routing policy.

That is several tasks with conflicting incentives. A model can improve semantic item selection by
omitting context yet fail the demand to account for every line; a correction aimed at one overlap
can regenerate unrelated services, dates or item types.

### 2.1 Measured baseline

The closed nano/Luna evaluation found source-exact self-disagreement far above its declared 10%
diagnostic ceiling. Reasoning effort did not fix it: nano `none` scored 80% disagreement at `n=30`,
nano `low` 77% at `n=100`, and Luna `none` 63.3% at `n=30`. The model-only comparison therefore
closed without an adoption verdict.

The 2026-08-19 prompt simplification screen then ran two 30-source arms against identical manifest,
source list, price snapshot and parser-surface provenance:

| Metric | Baseline prompt | Lean prompt |
|---|---:|---:|
| Any self-disagreement | 26/30 | 25/30 |
| Item-structure disagreement | 22/30 | 16/30 |
| Routing-category disagreement | 8/30 | 6/30 |
| First-pass validation failures | 24/60 | 31/60 |

The same baseline had scored 24/30 one day earlier on the same deterministic sample, so the
one-source aggregate movement is within observed run-to-run variation. Manual adjudication of the
12 sources whose item-structure status changed found seven substantive content/order/boundary
differences and five semantic type-label differences. Lean removed five substantive baseline
differences and introduced two, but the diagnostic did not retain stable-arm outputs, so it cannot
prove that the stable lean output was faithful.

The validation regression is clearer. Content-rule hits fell from 11 to 10 while bookkeeping-rule
hits rose from 32 to 42: `line_ignored_and_claimed` rose 11 to 14 and
`line_ignored_inside_item_span` rose 5 to 12. Corrections introducing new rule codes rose 3 to 8;
corrections changing unrelated fields rose 12 to 19. The lean prompt's initial calls were cheaper,
but seven additional corrections erased the token saving.

The original 21 first-pass transitions cannot be manually reconstructed because the stability
artifact retained aggregate rule counts and telemetry, not each first-pass extraction and rule
codes. That observability gap is an explicit acceptance requirement below.

### 2.2 External design constraint

OpenAI Structured Outputs enforce schema adherence, not source-faithful semantics. The official
[Structured Outputs guidance](https://developers.openai.com/api/docs/guides/structured-outputs)
explicitly notes that structured responses can still contain mistakes and recommends clearer
instructions, examples or splitting the work into simpler subtasks. This plan uses the schema to
make bookkeeping impossible to violate and narrows the model's semantic task; it does not treat
valid JSON as correct extraction.

## 3. Authority and boundaries

| Concern | Owner |
|---|---|
| Permanent weekly/historic email source normalisation, semantic annotation, compilation, targeted repair and parser evaluation | This plan |
| Approved Email/hymn/OpenLP/video manifests and raw-corpus custody | Historic incremental convergence |
| IC3 item-level ground truth and HIR-D8 corroboration implementation | Historic incremental convergence; this plan consumes the truth artifact as an evaluation oracle |
| Historic staging, production rounds, audit reports, releases and retirement of one-shot tooling | Historic incremental convergence |
| Canonical church-service projection/persistence ownership | Architectural maintainability AM10/EX-H and existing `ChurchServiceProjector`; this plan emits the existing input DTOs |
| Review UI and review-queue policy | Existing review workflow; this plan supplies typed uncertainty and validation evidence only |

This plan may change the shared parser because HIR-D7 permits extraction-accuracy work serving the
historic import and because the same code handles current weekly intake. It may not add features to
`ImportOosArchiveCommand` or `OosArchiveEvaluator`, refactor those deletion-scheduled one-shots, or
change which evidence is imported without the authority already recorded in REV-D2/HIR-D8.

## 4. Binding invariants

Every implementation PR must state which invariant it establishes.

1. **Lossless source identity.** Existing physical line IDs and gaps never renumber. Subject,
   receipt date, forwarding depth, separators and blank-boundary metadata remain available.
2. **Exactly one annotation per line.** The response schema requires every supplied line key and
   forbids additional keys. Missing, invented or duplicate line identities fail before compilation.
3. **The model never copies source text.** Titles and provenance come only from the source document.
4. **Semantic annotation is not storage projection.** The annotation ontology may distinguish
   call-to-worship, communion, benediction and interview even when the current canonical enum maps
   them to `other`.
5. **Compilation is pure and deterministic.** Identical source plus annotation produces identical
   plans, ignored complement, titles, order and provenance without database or network access.
6. **Repair is local.** A corrective response may patch only named line IDs and fields. Any
   unrelated mutation is rejected rather than selected because it reduced a rule count.
7. **Uncertainty is evidence, not a scalar guess.** The model emits typed uncertainty codes. PHP
   derives review risk from observable conditions; this plan does not invent a replacement
   confidence threshold.
8. **Policy remains separate.** Identity/date manifest corroboration, duplicate lookup, hold
   reasons, consensus, evidence tier, finalisation and release stay deterministic downstream rules.
9. **Fail closed.** Content-invalid extraction still imports nothing; adjudication never creates
   consensus; unattended publication remains impossible.
10. **Attempts remain adjudicable.** Every evaluation stores the initial annotations, rule codes,
    allowed patch, final annotations, compilation, model/prompt/schema/parser hashes, returned model,
    usage and latency in a private artifact.
11. **Fresh parser, fresh cache namespace.** No result produced by the old nested-plan parser is
    reinterpreted as an annotation result. Every behaviour-bearing class, prompt and schema joins
    `OosParserSurfaceFingerprint` and `OosArchiveParseCacheBinding`.
12. **Weekly/archive parity.** The same source and parser fingerprint compile to the same plan in
    weekly ingestion and historic rehearsal.

## 5. Target contracts

### 5.1 Lossless source document

Evolve `OosEmailSourceDocument` without breaking its existing portable assertion bundle. Each
physical non-blank source line keeps its current ID and exact text, with adjacent metadata rather
than destructive preprocessing:

- physical position and preceding/following blank-boundary information;
- forwarded/quoted depth and recognised header/separator markers;
- subject and receipt-date context held separately from body lines;
- stable input hash over every output-affecting source field.

Do not silently change historic line IDs. If richer normalisation changes the portable source
format, version it and make old assertion bundles fail with a clear incompatibility rather than
being reinterpreted.

### 5.2 Semantic annotation

Introduce a contract such as `OosSemanticAnnotator` returning readonly DTOs. The OpenAI adapter
receives the lossless source and returns:

- candidate service declarations: local group ID, proposed slot, boundary evidence and typed
  uncertainty;
- an annotation object keyed by every exact body line ID;
- for each line: `role`, zero or one primary service group, semantic item kind when relevant,
  optional adjacent continuation target, and typed uncertainty code.

The response JSON Schema is generated from the source line IDs: each is a required property,
`additionalProperties` is false, and every nested field is required with nullable values where the
answer may legitimately be absent. The schema must represent the two legitimate multi-role cases
explicitly—shared date/boundary evidence and a heading that is also a genuine order item—without a
general “may be claimed more than once” permission.

Initial semantic roles:

- `service_boundary`;
- `item`;
- `transition_marker`;
- `continuation`;
- `supporting_detail`;
- `notice_context`;
- `forwarded_context`;
- `greeting_or_signature`;
- `other_context`.

Initial item kinds include the current canonical concepts plus `call_to_worship`, `communion`,
`benediction`, `interview`, `missionary_focus` and `transition`. Delivery 3 maps them into today's
`OosEmailServicePlan`/section types and retains the richer subtype in provenance where the existing
metadata contract permits. Widening a persistent or public enum is a separate domain decision and
is not a prerequisite.

The model does **not** return copied titles, ignored-lines arrays, confidence, import disposition,
hold reasons or publication decisions.

### 5.3 Deterministic compiler

A pure `CompileOosServicePlans`-style action:

1. validates exact annotation membership and references;
2. validates candidate service boundaries and group membership;
3. copies exact source text for items;
4. joins only explicitly declared, physically adjacent continuation lines;
5. orders items by physical source position;
6. derives service evidence and the ignored/context complement;
7. resolves explicit/relative dates from identified evidence and supplied calendar context;
8. maps semantic kinds into the existing DTO contract; and
9. emits stable rule codes with affected line IDs for any unresolved semantic fault.

The first compatibility target is the existing `OosEmailItemExtractionResult` and
`OosEmailServicePlan` shape. Downstream import, projection and review code must not be rewritten as
part of the parser proof.

### 5.4 Targeted repair

When a semantic validator cannot compile safely, build one bounded question from its typed rule and
affected lines. Examples:

- Is “After the Lord's Supper:” a transition marker, a song or unrelated context?
- Is a URL after a song supporting detail for that song or a separate service item?
- Does an old forwarded order belong to the current subject date?

The response is a patch over an allowlisted set of line IDs and fields. PHP applies the patch only
after proving that it touches nothing else, revalidates once, and otherwise returns the original
failure for review. Transport retries are separate from semantic repair: use explicit connection
and request timeouts, and bounded backoff only for transient connection, rate-limit, 5xx or truncated
response failures. Never regenerate the entire email because one local rule failed.

### 5.5 Objective risk signals

Retain current confidence/consensus fields for compatibility until a separately approved policy
change, but stop treating a new model-generated scalar as the design target. Report observable
signals instead:

- implicit or ambiguous service boundary;
- unresolved slot/date/completeness;
- uncertain annotation count and codes;
- forwarded-current-message ambiguity;
- targeted repair required or failed;
- content validator findings;
- independent manifest/OpenLP/hymn corroboration or disagreement;
- catalogue resolution where relevant.

Delivery 5 records how these signals correlate with ground-truth correctness. It does not change
unattended routing by itself.

## 6. Golden corpus and evaluation contract

### 6.1 Private source-faithful truth

IC3 owns the authoritative item-level ground truth. Before measuring this parser, regenerate the
hymn reconciliation and item truth against the August 2026 authority as required by the historic
plan. Extend a bounded, stratified private evaluation set with per-line roles, service groups,
semantic kinds, continuations and expected plans. Do not commit private verbatim email bodies.

Start with the deterministic 30-source stability sample plus the known hard cases; expand only to
close a demonstrated coverage or precision gap. It must cover:

- full morning/evening orders;
- hymn-only partial orders and Lord's Supper transitions;
- sermon details that are not a running order;
- complete orders followed by media links and commentary;
- notices mentioning another service without containing its order;
- forwarded old content versus the current message;
- wrapped titles and continuations;
- separators, headings and communion/song ordering;
- subject-only and relative dates; and
- ambiguous `call_to_worship`/welcome/notices labels.

Human adjudication is reserved for genuine residual ambiguity. Corroborated item/date evidence and
deterministic structure should pre-fill the worksheet; corpus-wide manual line labelling is not an
acceptable prerequisite.

### 6.2 Metrics

Correctness is primary; self-agreement is diagnostic. Report separately:

- service-boundary precision/recall;
- service slot and date accuracy;
- item inclusion precision/recall;
- exact item-order rate;
- item-kind accuracy;
- continuation accuracy;
- exact title/source binding;
- content-invalid false accepts;
- routing category and incorrect unattended imports;
- initial calls, repair calls, tokens, latency and projected weekly/archive cost; and
- replicate self-disagreement decomposed by the same semantic groups.

### 6.3 Acceptance gates

The candidate cannot become the default unless all apply:

1. zero missing, invented or duplicate source-line identities;
2. zero compiler-produced out-of-order, duplicate-item-line, ignored-and-claimed or ignored-inside-
   span states;
3. exact title and source binding at 100%;
4. content-invalid safety fixtures remain held;
5. zero incorrect unattended imports on adjudicated truth;
6. item precision at least 0.98; recall at least 0.85, with lower-confidence/unresolved content
   routed to review rather than silently omitted;
7. no regression in identity/date accuracy or HIR-D8 dimension isolation;
8. targeted repair changes no unrelated field and introduces no new rule family;
9. ~~weekly and historic entry points agree for the same source/fingerprint;~~ **Reclassified
   2026-08-20 as a precondition rather than a gate** — it is the same value for every arm and no arm
   can establish or move it, so as a gate it was permanently `not_scored` and made `verdict: pass`
   unreachable. The requirement is unchanged and still `hard`; it is now recorded in the artifact's
   `preconditions` block and evidenced by
   `tests/Feature/Services/Email/OosParserEntryPointParityTest.php`. See §9.10. Gate numbering is
   left as-is so §6.3 and the artifacts stay comparable — there is simply no gate 9;
10. first-pass validation failure materially improves over the 24/60 baseline and does not regress
    any content-rule family; and
11. cost, latency and stability are reported, but cannot override a failed correctness/safety gate.

The old 10% self-disagreement ceiling remains a useful warning. It is not success by itself: two
identical wrong answers do not pass, and a source-faithful candidate is not rejected solely because
an irrelevant semantic subtype varies while deterministic projection remains correct and safe.

## 7. Delivery sequence

Each delivery is reviewable and leaves production behaviour unchanged until Delivery 7.

### Delivery 0 — consume truth and freeze compatibility contracts

**Status 2026-08-19:** source inventory and legacy compatibility evidence frozen for 38 sources;
semantic truth remains pending for all 38, so the acceptance gate is open. The freezer refuses
manifest drift, unknown hard cases and overwrite; the resulting private artifact binds the approved
payload, richer source-document hash, refreshed item truth, banked legacy projection and deterministic
stability diagnostic independently.

- Consume and verify the refreshed IC3 hymn/item truth produced by the historic plan against the
  August authority; this plan does not regenerate or approve that source evidence.
- Freeze the bounded private line-annotation corpus and its source/hash inventory.
- Record legacy outputs, routing, validation, usage and latency for the same sources.
- Add synthetic/derived fixtures for every listed failure family without committing private bodies.
- Specify the annotation DTO, rule-code and artifact formats with version constants.

**Acceptance:** the corpus is reproducible, private, hash-bound and sufficient to score every §6.2
metric; no runtime code path changes. **Met on 2026-08-19**: adjudication is complete, the fifteen
synthetic safety fixtures pass, and `OosSemanticCorrectnessScorer` computes every §6.2 metric from
the corpus and a candidate evidence artifact alone — verified by replaying the adjudicated truth as a
candidate against the real corpus.

### Delivery 1 — lossless source normalisation

- Extend `OosEmailSourceDocument` with blank-boundary and forwarding metadata while preserving
  current physical IDs and portable source compatibility.
- Put subject/receipt/calendar context in typed fields rather than prompt prose assembled ad hoc.
- Add unit tests for current historic examples, forwarded chains, wrapped lines and separators.

**Acceptance:** existing source hashes/IDs either remain identical or fail behind an explicit
version boundary; the legacy parser still produces its existing tested output.

### Delivery 2 — annotation contract and OpenAI adapter

- Add the semantic annotator contract, readonly DTOs and a fake implementation for tests.
- Add the OpenAI adapter using strict Structured Outputs and the repository's existing client
  conventions; do not add a package.
- Generate exact required schema properties from supplied line IDs.
- Use a concise prompt plus a small set of adjudicated real-pattern examples.
- Add explicit connect/request timeouts, bounded transient retry and telemetry.
- Add every behaviour-bearing file/prompt/schema to the parser fingerprint/cache binding.
- Keep the adapter unreachable from production parsing except through an explicit non-default
  evaluation configuration.

**Acceptance:** schema tests prove that every real line is required and missing/invented line keys
are rejected; all deterministic tests use fakes and prevent stray external requests.

### Delivery 3 — annotation validator and deterministic compiler

- Validate service groups, roles, continuation adjacency and permitted multi-role cases.
- Compile exact titles, service order, evidence and ignored complement into existing DTOs.
- Map the richer ontology to existing canonical types without a migration.
- Keep duplicate lookup, confidence compatibility and import policy outside the compiler.

**Acceptance:** the compiler passes the private golden corpus from banked annotations without a
network call and cannot emit any bookkeeping defect named in §6.3.

### Delivery 4 — narrow repair and thin orchestration

- Make `OosEmailParserService` a thin normalize -> annotate -> validate -> optionally patch ->
  compile -> existing-policy orchestrator using constructor injection.
- Replace whole-document correction for the candidate path with one bounded patch request.
- Allowlist patch line IDs/fields and reject unrelated changes.
- Persist initial/final attempt evidence in evaluation artifacts.
- Retain the legacy parser path unchanged for comparison and rollback.

**Acceptance:** a targeted repair can fix each synthetic local fault; malicious or accidental
unrelated mutation is rejected; transport failure, unusable output and unresolved semantics remain
distinct fail-closed outcomes.

### Delivery 5 — objective risk report

- Emit the §5.5 signals beside compatibility confidence/consensus fields.
- Measure each signal against private ground truth without choosing a new threshold.
- Prove HIR-D8 corroboration finalises only the independently proved dimension.
- Ensure no risk-report field changes import/finalisation/publication by omission.

**Acceptance:** the report is reproducible and explains every review/hold without relying on model
free-text reasoning; production routing is unchanged.

### Delivery 6 — prompt/schema/model evaluation

Requires approval for paid calls.

**Status 2026-08-20: complete, pending maintainer review.** Eight paid arms were run, each approved
individually in-session (v2, v3, v4, v5, v5-replicate, v6, v6-replicate, plus one schema-cap-rejected
attempt that billed nothing). The candidate is `OosSemanticAnnotationPrompt::Version = 6`. The signed
comparison artifact is `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-final.json`,
`score_hash` `6d8246a26ed3d4545f8a60de5849f3bd1531d2e9298b39da4ae892199c6d1791`, **`verdict: pass`**,
with both draws also passing when scored independently. The one caveat — `outcome_rate` 28.9%
against the 10% diagnostic ceiling, on a soft gate — was **accepted as an explicit exemption** by
maintainer decision on 2026-08-20 (§9.12), with the available remedy declined. Delivery 6 is
therefore closed on evidence. Full trail: §9.6 (arms v2–v5), §9.8 (the diagnosis), §9.9 (v6), §9.10
(gate 9 reclassification), §9.11 (the replicate), §9.12 (the stability split and the acceptance).

*Superseded 2026-08-19 status:* paid-call approval received; the raw-evidence runner's first real
invocation stopped at the pre-network truth gate because the frozen corpus reported 38 pending
sources, and no paid request had been sent.

1. Run one capable structured-output configuration against the frozen golden corpus. Start with
   `gpt-5.6-terra` / `low`, the balanced strong-model configuration already used for sermon
   analysis, rather than nano; freeze the exact returned model, reasoning setting and price snapshot
   in the run manifest.
2. Score correctness first. Do not run full-corpus or repeated arms for a candidate that fails a
   safety/precision gate.
3. For a passing candidate, run the same deterministic stability sample twice, then compare total
   calls/tokens/latency/cost with the legacy path including corrections.
4. Change one of model, prompt, schema or compiler semantics at a time. A new variant gets a new
   fingerprint and artifact; never overwrite the baseline.
5. Only after correctness passes may a cheaper model be tested for non-inferiority against the same
   truth. Repeated cheap calls are compared on total system cost, not first-call price.

**Acceptance:** all §6.3 gates pass in a signed comparison artifact, with no inferential label when
inputs drift or truth is incomplete.

### Delivery 7 — shared cutover and legacy retirement

Requires maintainer approval of the comparison artifact and production default flip.

**Status 2026-08-20: DONE.** The maintainer reviewed and approved the artifact and the default flip.
The semantic parser is now the only parser; the legacy whole-document path, its three contracts and
its correction/adjudication machinery are deleted, and the `OOS_EMAIL_PARSING_IMPLEMENTATION` config
key is gone rather than flipped — rollback is `git revert` plus a deploy, not a config reversal.
Flipping the default first exposed a latent wiring defect that would have failed every production
parse; it is fixed and guarded. **Full record in §9.15**, which supersedes the bullets below where
they disagree.

- Wire weekly and historic parsing to the same candidate configuration behind one rollbackable
  config value; legacy remains the default until approval.
- ~~Run current-era replay and clean historic rehearsal through the shared path~~ **Amended
  2026-08-20 — the current-era replay is neither achievable nor informative, and is replaced.** Three
  facts, all verified rather than assumed: Mailgun inbound routing has **never been configured**
  (`docs/reports/service-automation-opportunities-2026-07-05.md`), so no real weekly email has ever
  arrived to replay — locally, 655 of 656 `inbound_emails` rows are archive-synthetic; the "current
  era" the bullet points at is *already in the corpus*, which spans 2015 to `2026-08-02`, eighteen
  days before the candidate was scored, with 9 of 38 sources from 2025–2026; and the real difference
  between the corpus and a live email is **input shape, not era** — 31 of the 38 curated sources
  carry no reply header, quoted line, mobile signature or footer, because curation stripped them.
  The requirement is therefore replaced by
  `tests/Feature/Services/Email/OosProductionShapedSourceTest.php`, which asserts the deterministic
  front end against a noise-shaped source: every quoted/signature/footer line survives normalisation
  and stays individually addressable (so the *annotator* decides what it is, not the normaliser), the
  rarely-exercised HTML fallback yields the same lines as its plain equivalent, and a noisy source
  still generates a schema inside the provider limits. Both halves are mutation-verified. Historic
  RG-A and later rounds remain owned by the historic plan.
  *If inbound routing is ever configured, a real replay becomes both possible and worth doing — this
  amendment is about what is true now, not a claim that live evidence would be valueless.*
- Verify cache separation, weekly/archive parity, review evidence and no change to publication.
- Flip the production default. Rollback is a config reversal to the retained legacy parser, not
  reinterpretation of candidate cache rows.
- Delete whole-document corrective/adjudication code only after the approved candidate artifact and
  the production-shaped source coverage above both pass, plus one deliberately processed
  production-shaped source if inbound routing exists by then. This is an evidence event, not a
  calendar soak.
- Delete evaluation-only arm machinery at historic closeout/IC8; retain the permanent normalizer,
  annotator contract, compiler, validators and compact regression corpus. **Retention amended
  2026-08-20:** several of those surfaces declared deletion at "accepted Delivery 6 comparison **or**
  IC8, whichever comes first". Accepting the artifact (§9.12) fired that clause, which would have
  deleted the runner, scorer, freezer, adjudicator and safety-fixture harness on the same day the
  plan committed to re-reading `outcome_rate` on any future arm and named the item-kind arm as the
  remedy for the accepted 28.9%. The trigger on all six is now **IC8 closeout only**, so accepting a
  caveat does not destroy the means of acting on it.

**Acceptance:** one parser implementation serves weekly and historic intake; the legacy full-
document retry is gone; rollback remains possible through the preceding deployed release/config;
and no temporary evaluation hook lacks a deletion trigger.

## 8. Test and verification matrix

### Programmatic tests

- Unit: lossless source normalisation, generated schema, annotation membership, continuation rules,
  compiler ordering/text/provenance, semantic mapping, patch allowlist and cache fingerprint.
- Integration: `OosEmailParserService` orchestration with fake annotator/repairer; existing
  `OosEmailServicePlan` disposition and hold behaviour; duplicate lookup remains downstream.
- Feature: weekly `ProcessInboundOosEmail`, reparse/approve flow, and historic import consumption
  produce compatible metadata and fail closed on content-invalid sources.
- Contract: weekly and archive entry points compile byte-identical canonical projections for the
  same source/fingerprint.
- Evaluation: private golden runner reports every §6.2 metric and retains per-attempt evidence.

Use PHPUnit with `#[Test]`, `DatabaseTransactions` where database isolation is needed, factories for
persistent models, and fakes that prevent real OpenAI calls in the test suite. Preserve existing
tests; migrate them to the new seam rather than deleting coverage.

### Quality gates per implementation delivery

1. Focused tests for changed behaviour.
2. `vendor/bin/sail composer phpstan` at zero errors.
3. `vendor/bin/sail bin pint --dirty`.
4. `vendor/bin/sail artisan test --parallel --compact` for non-trivial deliveries.
5. No Dusk or Playwright run unless a later delivery changes UI behaviour or output; none is planned.

Live-model evaluation is an explicit operator-approved Artisan run against the private corpus. It
is not a CI test, never runs from the normal application suite and never writes production state.

## 9. Continuation handoff — 2026-08-20 (updated)

This section is the restart point for the next implementation session. Earlier sections remain the
design authority; this section records mutable execution state and does not weaken any gate.

### 9.1 Current repository and runtime state

- Deliveries 1–5 are implemented in the working tree behind the non-default
  `OOS_EMAIL_PARSING_IMPLEMENTATION=semantic_annotations` seam. The changes are not yet committed;
  preserve the dirty working tree and inspect it before editing.
- Production and `.env.example` still default to `legacy`. No cache row has been reinterpreted, no
  production-shaped source has been processed through the candidate and no publication policy has
  changed.
- Delivery 0 has frozen the source inventory and same-source legacy evidence, and adjudication now
  **passes**: all 38 line-truth records report `adjudication_state: adjudicated`, and the
  `-adjudicated` artifact's `completeness.scoreable` is `true`.
  `OosSemanticEvaluationCorpusGate::assertScoreable()` accepts it (verified by direct invocation, not
  merely by inspecting the JSON).
- The §6.2 scorer and §6.3 safety fixtures now exist and are covered by 24 new tests. The remaining
  Delivery 0 gap is closed; §9.4 step 5 was a maintainer decision about the date resolver and is now
  **done** (option (a) chosen and implemented on 2026-08-20).
- **2026-08-20: `OosServiceDateResolver` gained the Sunday-on-or-after-received fallback rung**,
  suppressed for a named special service via the now-`public`
  `OosEmailExtractionValidator::SPECIAL_SERVICE_PATTERN`, covered by a new
  `tests/Unit/Services/Email/OosServiceDateResolverTest.php`. Because `truth.expected_plans` are
  compiled, the truth corpus was regenerated by re-running `oos:adjudicate-semantic-corpus` over the
  retained decisions file (never re-adjudicated) into
  `storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json` — see §9.2. A
  truth-replay self-check against the new corpus confirmed gate 7 now passes
  (`authority_identity`: candidate 36, ceiling 36, legacy baseline 34) with every other
  candidate-vs-truth metric still exactly 1.0; only gate 9 remains `not_scored`, so the verdict is
  `incomplete`. No paid call was made to produce this result — see the status block above for the
  full figures.
- Delivery 6 spend approval was granted by the maintainer on 2026-08-19, against the corpus that
  existed then. The real command was invoked with its approval flag against the then-pending corpus,
  and `OosSemanticEvaluationCorpusGate` correctly rejected it before `OosSemanticParserCandidate::parse()`
  and therefore before any OpenAI request. Paid-call count is still zero. The truth blocker is now
  cleared and gate 7 now passes against the regenerated corpus, so Delivery 6 is unblocked on both the
  truth worksheet and the compiler — but the 2026-08-19 approval does not carry forward to the
  regenerated corpus; §9.4 step 6 needs a fresh, explicit in-session go-ahead before any spend, and
  none was sought or given in the 2026-08-20 session.
- The first attempted paid run was rejected by OpenAI's *request validation* — "Expected at most
  1000 enum values in total, but received 1004" — so no model ran and nothing was billed; the
  paid-call count is still zero. The cause was the generated schema, not the corpus or the gate:
  `continuation_target_line_id` enumerated every line ID in the document once per line, and the
  per-line role, item-kind and uncertainty enums were re-declared for every line. Measured over the
  frozen corpus, 23 of 38 sources exceeded the 1000-value cap, and restricting the continuation enum
  to the adjacent target alone would still have left 15 over it — the repetition mattered as much as
  the quadratic term. Both are fixed: the constant per-line field schemas live in `$defs` and are
  referenced, and `continuation_target_line_id` now offers only the single target
  {@see App\Services\Email\OosSemanticContinuationRule} permits, which is the rule the validator
  already enforced under §5.3 step 4 rather than a new one. The worst source in the corpus now
  declares 188 enum values, 550 properties and 8,431 characters of names and values against caps of
  1000, 5000 and 120,000. `App\Support\OpenAiJsonSchemaLimits`, called from
  `OpenAiChatPayload::forModel()`, now refuses any over-cap schema locally, for every
  structured-output call in the application, instead of paying a round trip to learn the arithmetic.
  Delivery 2's acceptance test had exercised a two-line document only, which is why a failure that
  scales with input length was invisible to it; the schema test now asserts the enum budget on long
  sources.
- One residual provider uncertainty is deliberately unresolved: OpenAI documents the cap over "a
  schema" and does not say whether a `$defs` entry counts once or once per `$ref`. Every count above
  assumes once. If the provider counts a fully expanded schema instead, the longest sources will be
  refused again — and again for free, as a request-validation 400 — in which case the fallback is to
  carry the per-line annotations as an array with a single `items` schema, which is unambiguous under
  either rule but gives up the schema-level guarantee in §5.2 that every real line is a required
  property. That guarantee is independently enforced by the decoder and the validator, so the
  fallback costs assurance, not correctness. Do not adopt it pre-emptively.
- **2026-08-20 (this session): the schema-cap fix held under real load, and Delivery 6 got its first
  actual paid correctness evidence — five arms, described in §9.6.** The enum/property/character
  budget was never threatened again across any of the five runs; every rejection or failure from here
  on was a correctness finding, not a request-validation 400. Paid-call count moved from zero to five
  full-corpus arms plus one replicate; total spend across all six calls was roughly $6.50 at the dated
  snapshot's projected rate (each run individually ≈ $1.05–$1.08, reported in each score artifact's
  `metrics.cost`, never a billing authority).
- ~~Delivery 7 is neither eligible nor authorised. It needs a signed passing comparison artifact and
  a fresh, explicit production-default approval after the maintainer has reviewed that artifact. The
  current best candidate (`OosSemanticAnnotationPrompt::Version = 5`) is close but not there.~~
  **Superseded 2026-08-20.** The signed passing artifact now exists at prompt `Version = 6` (§9.9,
  §9.11). Delivery 7 is still **not authorised** — the outstanding requirement is now purely the
  human one: maintainer review of that artifact, then an explicit production-default approval.
- Total paid spend across the plan is eight full-corpus arms at roughly $8.50, projected from the
  dated snapshot and never a billing authority. Every arm's approval was sought and given
  individually, in-session, immediately before that specific run; no approval was ever reused.

### 9.2 Private artifacts to preserve

| Artifact | Binding state |
|---|---|
| `storage/scratch/item-ground-truth-2026-08-19-authority-refreshed.json` | Refreshed IC3 item truth; canonical hash `8c87a18889e9ed5dc97088a886113d0c14842d02dc6ef55eb59e69eb72284645` |
| `storage/scratch/oos-semantic-evaluation-corpus-2026-08-19-prefilled.json` | Current private worksheet, mode `0600`; 38 sources, 918 non-blank lines, 633 deterministic legacy-provenance prefills and 285 unresolved lines; internal hash `13abc76bdf5152c11f10938e3834e1f5e3f3dd0998b2bca910f73e60f7efb168`; raw SHA-256 `415afd5404ef6a02f357136cc7aa4e690b2e1cfb895bb2f66344eaf225f20439` |
| `storage/scratch/oos-semantic-evaluation-corpus-2026-08-19.json` | Earlier create-once freeze retained for audit only; superseded by the `-prefilled` artifact, never overwrite or promote it |
| `storage/scratch/oos-semantic-adjudication-decisions-2026-08-19.json` | Maintainer's per-source adjudication decisions (services + line annotations, keyed by item_key), mode `0600`; the mutable working input to the overlay, not itself a create-once artifact; recompiled (not re-taken) into both `-adjudicated` corpora below |
| `storage/scratch/oos-semantic-evaluation-corpus-2026-08-19-adjudicated.json` | **Superseded 2026-08-20, retained for audit only.** Scored against the pre-fix `OosServiceDateResolver`; do not score against it again. mode `0600`; corpus hash `d499162c15e010105ded8e0f48087fdab6a14bb1342c1233661de0d9ebf5324a` |
| `storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json` | **Scoreable truth corpus — current.** mode `0600`; all 38 sources adjudicated by Gareth Clarridge, recompiled against the fixed `OosServiceDateResolver`; `completeness.scoreable: true`; corpus hash `14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`; this is the corpus Delivery 6 must be run against |
| `storage/scratch/oos-parser-evaluation/baseline-nano-none-2026-08-18/raw-result-projection.json` | Banked same-manifest legacy output/routing/validation/telemetry; canonical hash `75181b9f606e83ee51b1c40ee814a51edba80138293e61569ecccda0d18c0cc5` |
| `storage/scratch/oos-parser-evaluation/prompt-baseline-nano-none-2026-08-19/stability-diagnostic.json` | Authority for the deterministic 30-source sample; canonical hash `4be3122b4b8d8a8b71cdb4baf12bfd81b9ce31948d51707ce1850f18a8b85323` |
| `storage/scratch/oos-semantic-price-snapshot-2026-08-19.json` | Dated direct-API/model-page price input; raw SHA-256 `4ad19caca578776e11ef1b3a332fdfc3947ecd8d0045fe5bfe2e445a87d36ce0`; projection input only, never billing authority |
| `storage/scratch/oos-semantic-candidate-terra-low-2026-08-20-v6-replicate.json` | Second full-corpus draw of the unchanged v6 prompt (§9.11); scored alone it passes every gate. |
| `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-{replicate,final}.json` | The replicate scored independently, and the pair scored with `--replicate=`. `-final` is the Delivery 6 comparison artifact: `score_hash` `6d8246a26ed3d4545f8a60de5849f3bd1531d2e9298b39da4ae892199c6d1791`, `verdict: pass`. |
| `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-reclassified.json` | The v6 primary re-scored under format version 2 (§9.10); first artifact in this plan to reach `verdict: pass`. |
| `storage/scratch/oos-semantic-candidate-terra-low-2026-08-20-v6.json` | **The accepted Delivery 6 candidate — prompt v6, see §9.9.** Primary draw; all ten arm-scoped gates pass, gate 10 at 13.3% against a 40% baseline. Replicated (§9.11). |
| `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6.json` | The v6 primary scored under format **version 1**, before the gate 9 reclassification; verdict `incomplete` for that reason alone. Retained for audit; superseded by the `-reclassified` artifact below. |
| `storage/scratch/oos-semantic-candidate-terra-low-2026-08-20{,-v3,-v4,-v5,-v5-replicate}.json` | **Five paid candidate evidence arms, retained in full — see §9.6.** Unsuffixed = prompt v2 (pre-fix); `-v3`/`-v4`/`-v5` = successive prompt-only fixes at `OosSemanticAnnotationPrompt::Version` 3/4/5; `-v5-replicate` = second full-corpus run of the unchanged v5 prompt, for the §6.2 self-disagreement decomposition. Every arm is create-once and none was overwritten. |
| `storage/scratch/oos-semantic-score-terra-low-2026-08-20{,-v3,-v4,-v5,-v5-final,-v5-replicate}.json` | Scoring artifacts for each arm above; `-v5-final` is v5 scored *with* `--replicate=` (the complete comparison, `score_hash` `f66a5124cd872a74cb9152171980bb27dac20030745e2ca7e0d2007a19b74ceb`); `-v5-replicate` is the replicate scored independently against truth (its own verdict is `fail` on gate 10 — see §9.6, do not read only the `-v5-final` PASS list). |

| `storage/scratch/oos-semantic-price-snapshot-2026-08-20-luna.json` | Dated price input for the luna arms; `gpt-5.6-luna` at $0.20 input / $1.20 output per 1M. Figures carried unchanged from `oos-parser-price-snapshot-2026-08-18.json` — the model page was **not** re-read on 2026-08-20, and the artifact says so. `billing_mode` deliberately matches the terra snapshot so the two projections are same-basis. Projection input only, never billing authority. |
| `storage/scratch/oos-semantic-candidate-luna-low-2026-08-20-v6{,-replicate}.json` | **The two paid luna arms (§9.13).** `gpt-5.6-luna` / `low` / flex, prompt v6, corpus `14cba9a3…`, parser surface `7acc08a8…` — byte-identical surface to the terra v6 arms, so the model is the only variable. `evidence_hash` `7d837b18…` and `5a7b0c61…`. |
| `storage/scratch/oos-semantic-score-luna-low-2026-08-20-v6{,-replicate,-final}.json` | Luna scored as primary alone (`score_hash` `323c5df6…`), replicate alone (`d4a9611a…`) and paired with `--replicate=` (`b15bddfd…`). All three read `verdict: pass`. **A passing verdict here means "passes the absolute §6.3 bars", not "matches terra" — see §9.13 before quoting it.** |

The private corpus contains verbatim email text and must remain uncommitted and mode `0600`. The
price snapshot records the official model page's displayed default rates and explicitly notes that
OpenAI's general pricing table exposes additional context/processing variants. Keep the snapshot
fixed for this arm; create a new artifact rather than editing it if the selected billing mode changes.

### 9.3 Implemented continuation surfaces

- `OosSemanticContinuationRule` is the single definition of a legal continuation target — the
  immediately preceding physical line, which a blank line therefore breaks. The request schema and
  the validator both consult it, so what the model is able to say and what is accepted cannot drift
  apart. It and `App\Support\OpenAiJsonSchemaLimits` are both registered in
  `OosParserSurfaceFingerprint`, so the combined surface hash has moved; no paid arm artifact
  predates the move, so nothing is invalidated by it.

- `FreezeOosSemanticEvaluationCorpusCommand` and `FreezeOosSemanticEvaluationCorpus` create the
  private source/hash inventory, reject manifest drift and unknown hard cases, retain IC3 evidence,
  and label all legacy-derived fields `not_truth`.
- `OosSemanticEvaluationCorpusGate` recomputes the corpus and source hashes and refuses paid calls
  unless every source has typed services, annotations and expected plans with maintainer identity and
  time, and the completeness census agrees.
- `OosSemanticCandidateEvidenceRunner` and `RunOosSemanticCandidateEvidenceCommand` are create-once
  raw-evidence surfaces. They bind corpus, prompt, parser surface, configured/returned model,
  reasoning effort, service tier, price snapshot, usage and latency. They deliberately do not call a
  compilable response a correctness verdict.
- Targeted repairs now retain returned model, attempt, usage and latency inside the adjudicable
  attempt artifact; the earlier implementation only wrote that evidence to logs.
- Both one-shot commands declare deletion at accepted Delivery 6 comparison or historic IC8
  closeout, whichever comes first.
- `OosSemanticEvaluationSource` is the single rule for rebuilding a frozen corpus record's source
  document and proving the reconstruction reproduces the record's own `input_hash`. The freezer's
  consumers had three independent copies of it; the runner, the adjudication overlay and the scorer
  now share one.
- **`OosSemanticAnnotationPrompt` moved from Version 2 to Version 5 this session, in three separate,
  individually-tested and individually-scored edits** (§9.6 has the full evidence trail; do not
  collapse these back into one change if a future edit is needed — each one changed which defect
  dominated the remaining failures, which would have been invisible as a single diff):
  1. **v3** — told the model to always declare a service group and to infer `proposed_service` from
     context rather than omit the group when the body never says "morning"/"evening" outright. This
     was directionally correct (identity 29→33/38) but shipped with a real bug: an "other requires a
     named occasion" clause that fired on *any* festival word, wrongly reclassifying Christmas and
     Carols services that the truth corpus keeps at their ordinary morning/evening slot.
  2. **v4** — corrected that bug: a themed/festival service occurring in its normal Sunday slot keeps
     that slot's label; `other` is reserved for services outside the normal Sunday morning/evening
     pattern entirely (the corpus has exactly one such source, a Good Friday service).
     `service_accuracy` reached 1.0 on matched plans and the false-positive regression cleared, but
     identity stayed flat at 33/38 and gate 10 (first-pass validation rate) got worse, not better.
  3. **v5** — a distinct, code-verified fix unrelated to service labelling: `boundary_line_ids` empty
     was causing the compiler to discard entire correctly-annotated plans on sources with no explicit
     heading line. `OosSemanticAnnotationValidator` already supports a line being both
     `service_boundary` and an item via `boundary_also_item` (confirmed in code, not assumed, after
     the v3 lesson) — v5 tells the model to use exactly that mechanism, pointing `boundary_line_ids`
     at the first item's own line when no separate heading exists. This moved identity to 35/38
     (first version to exceed the legacy baseline of 34) and gate 10 to a pass on the primary run.
- `OosSemanticSafetyFixtures` holds fifteen wholly synthetic sources and deliberately defective
  parser outputs, one per §6.3 failure family, in two layers: `annotation` fixtures that
  `OosSemanticAnnotationValidator` must refuse before anything compiles, and `extraction` fixtures
  that hand the compatibility path an already-compiled defective plan. Every `extraction` fixture
  declares `confidence: 1.0` and a resolvable Sunday identity, so the *only* thing that can hold it is
  the rule under test rather than the compiler's fixed 0.75 sitting under the 0.90 threshold. A clean
  control fixture must come back auto-importable, which is what proves the harness is not simply
  refusing everything.
- `RunOosSemanticSafetyFixtures` drives each fixture end to end through the real
  `OosEmailParserService` with fixed fakes in place of the annotator and the legacy extractor, so
  "held" means `OosEmailServicePlan::isAutoImportable()` and `isEvidenceImportable()` answering no
  rather than a restatement of the disposition rule that could drift from it.
- `OosSemanticCorrectnessScorer` and `ScoreOosSemanticCandidateCommand`
  (`oos:score-semantic-candidate`) score a candidate evidence artifact against the adjudicated truth.
  Three choices are load-bearing: plans are paired by the source lines they claim rather than by
  service and date, so a wrong slot or date is scored as the identity error it is instead of
  destroying the item-level comparison underneath it; gate 2 and gate 10 are measured by running the
  candidate's compiled extraction back through the same `OosEmailExtractionValidator` the 24/60
  baseline was measured by; and gate 10's comparison is restricted to the 30-source deterministic
  stability sample the baseline actually parsed, with the eight hard cases reported separately, so a
  rule family the baseline never had the chance to hit is not read as a regression. Gate 5 bounds the
  unattended-import set from above by confidence eligibility — every other disposition condition can
  only hold a plan, so the bound can overstate the risk and never understate it — and records the
  threshold, so a config change that widened it fails the gate loudly.
- `AdjudicateOosSemanticEvaluationCorpus` and `AdjudicateOosSemanticEvaluationCorpusCommand`
  (`oos:adjudicate-semantic-corpus`) are the hash-bound adjudication overlay called for by the prior
  handoff. They validate the frozen `-prefilled` corpus's own hash before use, decode and compile each
  decided source through the existing live-candidate pipeline (so annotations and expected plans can
  never disagree), leave any undecided source untouched as `pending`, and recompute the completeness
  census and corpus hash. This is also a one-shot surface with the same Delivery 6/IC8 deletion
  trigger as the rest of the Delivery 0 truth tooling.

### 9.4 Exact next-session sequence

1. ~~Inspect the dirty working tree and reverify the artifact hashes above.~~ Done this session;
   hashes reverified, no create-once artifact was overwritten, the 38-source selection is unchanged.
2. ~~Build the hash-bound adjudication overlay.~~ Done this session as
   `AdjudicateOosSemanticEvaluationCorpus`/`oos:adjudicate-semantic-corpus` (§9.3), with unit and
   feature test coverage; Pint and PHPStan both pass.
3. ~~The maintainer must confirm or correct the 633 prefills and adjudicate the 285 unresolved
   lines.~~ Done this session: all 38 sources adjudicated by Gareth Clarridge, working from the
   verbatim source text and evidence hierarchy in §6.1, not from legacy prefill or IC3 corroboration.
   The resulting `-adjudicated` artifact reports `scoreable: true` and the real
   `OosSemanticEvaluationCorpusGate` accepts it.
4. ~~Add the §6.2 correctness scorer and synthetic safety fixtures.~~ Done this session (§9.3). All
   fifteen safety fixtures meet their expectation, and the scorer was validated against the real
   `-adjudicated` corpus by replaying truth as the candidate: every candidate-vs-truth metric came
   back exactly 1.0 (line identity 918/918, boundaries 51/51, items 526/526 at precision and recall
   1.0, exact order 1.0, item kinds 1.0, continuations 9/9, title binding 526/526, zero
   bookkeeping defects) and only gate 7 failed, for the resolver reason recorded in the status block.

5. ~~A maintainer decision, before any spend.~~ **Done 2026-08-20: option (a) chosen and
   implemented.** `OosServiceDateResolver` gained the Sunday-on-or-after-received fallback rung
   below every explicit-date pattern, suppressed for a named special service via the now-`public`
   `OosEmailExtractionValidator::SPECIAL_SERVICE_PATTERN`. The `-adjudicated` corpus was regenerated
   from the retained decisions file (decisions recompiled, not re-taken) into
   `storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json`, corpus hash
   `14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`. A truth-replay self-check
   confirmed gate 7 now passes (candidate 36, ceiling 36, legacy baseline 34) with every other
   candidate-vs-truth metric still exactly 1.0 — see the status block for full figures. The parser
   surface fingerprint moved, correctly, since this is a compiler change.

6. ~~Run one approved `gpt-5.6-terra` / `low` correctness arm against the `-2026-08-20-adjudicated`
   corpus.~~ Done this session, five times over (§9.6): the maintainer gave in-session go-ahead for
   each arm individually, never a blanket approval. v2 (pre-fix prompt) surfaced the service-labelling
   defect; v3/v4/v5 are three successive single-lever prompt fixes, each independently tested and
   scored (§9.3 has the full defect trail).
7. ~~Score correctness before any replicate or full-corpus spend.~~ Done for every arm. v5 is the
   first version to pass every hard gate on its primary run (identity 35/38, exceeding the legacy
   baseline of 34; gate 10 first-pass rate 36.7% vs baseline 40%).
8. ~~Only for a correctness-passing candidate, run two deterministic stability replicates and score
   again with `--replicate=`.~~ Done this session for v5: one replicate run
   (`-v5-replicate`), scored two ways — paired with the primary via `--replicate=`
   (`-v5-final`, `score_hash` `f66a5124cd872a74cb9152171980bb27dac20030745e2ca7e0d2007a19b74ceb`) and
   independently against truth on its own (`-v5-replicate` score). **The independent replicate score
   is a `fail`** — gate 10 lands exactly on the 40% baseline rather than under it, so the "beats
   baseline" result is not yet reproducible on every draw. §9.6 has the full comparison; do not read
   the `-v5-final` PASS list alone as evidence of stability, since gate 10 there is computed from the
   primary run's own first-pass rate, not the pair's.
9. ~~**Next step — not yet decided; needs a maintainer call, not another paid draw.**~~ **Resolved
   2026-08-20: option (b) was chosen and it found a single shared cause (§9.8), which prompt v6 fixed
   (§9.9) and a replicate reproduced (§9.11). Option (c), another draw at the same prompt, was
   correctly not taken.** The original framing is kept below because the reasoning against (c)
   still applies to any future marginal gate.

   The remaining gap
   is narrow (35 vs 34 identity, 36.7%/40.0% vs a 40% first-pass bar) and re-running more replicates
   hoping for a better draw is exactly the pattern the plan's stability check exists to catch, not a
   legitimate way to clear it. Three real options, in ascending order of effort:
   a. **Accept the margin as sufficient**, treat v5 as the Delivery 6 candidate, and move to gate 9
      (the weekly/archive entry-point parity contract test, the only other unscored gate) — a
      maintainer judgement call about how tight "improvement" needs to be, not an engineering one.
   b. **Investigate the item_structure/title disagreements** the replicate comparison surfaced
      (`field_decomposition`: `item_structure` 9/38, `titles` 3/38 — see §9.6) to see whether they
      share a cause the way the three prompt fixes did, before spending on a fourth prompt change.
   c. **Run a third full-corpus arm** to break the 2-of-2 tie and see whether v5 clears baseline more
      often than not — real additional spend (~$1) with no guarantee of a clean answer, since two
      results either side of a threshold this close may just mean the true rate sits near the boundary.
   ~~Whichever is chosen, gate 9 still needs the weekly/archive parity contract test named as its
   evidence before any artifact can reach `verdict: pass` — that test does not exist yet and is
   independent of the model/prompt question above.~~ **Built 2026-08-20 (later session) as
   `tests/Feature/Services/Email/OosParserEntryPointParityTest.php` — see §9.7.** The gate 9
   evidence now exists and cost nothing; the maintainer call between (a), (b) and (c) is the only
   thing still outstanding at this step.
10. ~~Present the resulting artifact to the maintainer. Do not start Delivery 7 replay, default flip
    or legacy deletion until the maintainer explicitly approves the artifact and production default
    change.~~ **Done 2026-08-20: presented, approved, and Delivery 7 executed in full — see §9.15.**
    The approval covered the artifact, the production default and, after the rollback rationale was
    re-examined, the deletion of the legacy path rather than its retention.

This sequence is complete. The next open item belongs to §9.12, not here: the accepted 28.9%
`outcome_rate` and its unrun single-lever item-kind remedy.

### 9.5 Verification banked at handoff

**2026-08-20 (final state of this session — supersedes the runs below):**

- Full suite: **7,006 tests passed, 83,953 assertions**; 140 existing PHPUnit notices; no failures.
  (+10 tests over the prior banked run: four from `OosParserEntryPointParityTest`, three from the
  scorer's precondition and stability-split coverage, three from `OosProductionShapedSourceTest`.)
- PHPStan: **0 errors**. Pint: passed. Dusk: **55 passed**, unchanged (no UI change; run to satisfy
  the standing four-check workflow).
- Eight paid full-corpus arms total, ≈$8.50 projected. No production mutation at any point.

**2026-08-19 (prior session):**

- Full suite: **6,981 tests passed, 83,842 assertions**; 140 existing PHPUnit notices; no failures.
- PHPStan: **0 errors** across 834 analysed files.
- Pint: passed.
- Dusk: **55 passed**. Not required by §8 — there was no UI change — but run to satisfy the standing
  four-check workflow.
- The scorer was additionally exercised against the real `-adjudicated` corpus with truth replayed as
  the candidate. That is a self-check, not evidence about any model, and it wrote no artifact.
- No paid model request and no production mutation occurred. Paid-call count remains zero.

**2026-08-20 (this session — §9.4 step 5 option (a), resolver fix and corpus regeneration):**

- Full suite: **6,988 tests passed, 83,849 assertions** (+7 tests / +7 assertions, from the new
  `tests/Unit/Services/Email/OosServiceDateResolverTest.php`); 140 existing PHPUnit notices,
  unchanged; no failures.
- PHPStan: **0 errors** across 834 analysed files.
- Pint: passed.
- Dusk: **55 passed**, unchanged. Not required by §8 — there was no UI change — but run to satisfy
  the standing four-check workflow.
- `oos:adjudicate-semantic-corpus` re-run over the frozen `-prefilled` worksheet and the retained
  decisions file: 38/38 adjudicated, `scoreable: true`, new corpus hash
  `14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`.
- The scorer was exercised against the new `-2026-08-20-adjudicated` corpus with truth replayed as
  the candidate (script written to and deleted from `storage/scratch/`, per the same self-check
  convention as 2026-08-19 — it is not evidence about any model and left no retained artifact).
  Gate 7 now **passes** (`authority_identity`: candidate 36, adjudicated-truth ceiling 36, legacy
  baseline 34); every other candidate-vs-truth metric is still exactly 1.0 (918/918 line identity,
  51/51 boundaries, 526/526 items precision/recall, exact order 1.0, item kinds 1.0, 9/9
  continuations, 526/526 title binding, zero bookkeeping defects); ten of eleven scoreable gates
  pass; gate 9 (entry-point parity) is `not_scored`; verdict `incomplete`.
- No paid model request and no production mutation occurred. Paid-call count remains zero.

**2026-08-20 (later same day — Delivery 6 first paid arms, §9.6):**

- Full suite: **6,996 tests passed, 83,873 assertions**, 140 existing PHPUnit notices, 1 skipped;
  no failures. (+8 tests / +24 assertions over the prior banked run, from
  `tests/Unit/Support/OpenAiJsonSchemaLimitsTest.php` and the schema/limits changes already reflected
  in the working tree at session start.)
- PHPStan: **0 errors** across 837 analysed files.
- Pint: passed.
- Dusk: **55 passed**, unchanged. Not required by §8 — no UI change — run to satisfy the standing
  four-check workflow.
- Five paid `oos:run-semantic-candidate-evidence` arms and one scored replicate pair, all against
  the `-2026-08-20-adjudicated` corpus, hash unchanged
  (`14cba9a3b97ef763e184d8b6a31cd41654054e2d6edfe31761dea9af2a910060`). Each arm's paid-call
  approval was sought and given individually, in-session, immediately before that specific run — no
  approval was reused across arms. Paid-call count: **zero → six full-corpus arms** (v2, v3, v4, v5,
  v5-replicate, plus the schema-cap-rejected attempt from the prior session, which billed nothing).
  Full detail, defect trail and honest net assessment in §9.6.
- No production mutation occurred at any point; `OOS_EMAIL_PARSING_IMPLEMENTATION` remains `legacy`
  in production and `.env.example`.

### 9.6 2026-08-20 session — Delivery 6's first paid evidence, five arms

This is the detailed record §9.1 and §9.4 point to. All artifacts are in §9.2's table; nothing here
duplicates what's already there.

**v2** (prompt unchanged from Delivery 2, the schema-cap fix only): identity 29/38 vs legacy's 34,
ceiling 36. Nine sources missed manifest identity, in three distinct shapes: two with a null
`service` field on an otherwise-correct plan; four with **zero plans produced at all**
(`candidate_plan_count: 0`) despite truth expecting 1–2; three with wrong values (one clean date
error — the source's own body says "5th June 2026" though received 2026-07-03, and truth's July 5 is
itself a typo-correction the legacy parser also misses; one `service: other` where truth says
`morning`, on a source with no explicit service label at all; one plan with a null date).
Investigation traced the four-zero-plan cluster and the null-service pair to one shared cause: none
of those sources states "morning" or "evening" anywhere in the body — the label is only implicit
(subject line, or absent entirely) — and the model's schema permits `proposed_service: null`, so
under-confidence surfaces as an omitted plan or a null field rather than a committed best guess.

**v3** (`OosSemanticAnnotationPrompt::Version = 3`): told the model to always declare a service group
and infer the label from context. Identity 29→33/38; `service_accuracy` on matched plans rose to
0.94; first-pass corpus failures fell 14→13. But the fix's `other`-triggering word list (Christmas,
Carols, Palm Sunday and similar) was wrong — checked against the truth corpus directly, only **one**
of the 38 sources is genuinely `service: other` (a weekday Good Friday service); Christmas Day and a
Carols evening service both keep their ordinary morning/evening slot in truth. The word list caused
a **new** regression: `2020-05-31` (no festival word, correctly fixed) traded places with
`2016-03-20` (Palm Sunday) and `2020-12-20-carols` (Carols), both newly misclassified as `other`. Net
gate 10 regression count stayed at 1 — same count, different source, which is why re-scoring rather
than re-reading the gate list mattered here.

**v4** (`Version = 4`): corrected the `other` rule — a themed/festival service in its normal Sunday
slot keeps that slot's label; `other` means "outside the normal Sunday morning/evening pattern
altogether," not "has a festival name." `service_accuracy` reached a clean 1.0 and the regression
cleared. But identity stayed flat at 33/38 (composition of the 5 remaining failures shifted between
v3 and v4 — `2018-06-03` was fixed then broke again, a new failure `2026-04-05-pm` appeared — the
signature of run-to-run LLM variance, not a stable property of the wording) and gate 10 got *worse*
(40%→46.7% first-pass rate on the stability sample), because eliciting more complete service
declarations gave the model more surface area to trip some other first-pass validation rule that
repair then cleans up (gate 2's post-repair `content_rule_counts` was empty both times — the
regression is in first-pass efficiency, not final correctness).

Digging into the one source that failed identically in **all three** of v2/v3/v4
(`2024-05-12`) found a fourth, distinct, code-verified defect, unrelated to service labelling: the
model correctly annotated all five items and the group's service/date, but left `boundary_line_ids`
empty because the source has no heading line at all (just "Good morning all / Please see below." then
a bare hymn list) — and `OosSemanticAnnotationValidator` treats an empty boundary array as
`service_boundary_missing`, which the compiler responds to by discarding the **entire plan**
(`confidence: 0`, `services: []`), even though every item was right. Traced through
`CompileOosSemanticAnnotations.php:103` and `OosSemanticAnnotationValidator.php:110`: a line can
already be both `service_boundary` and an item via `boundary_also_item`, which is exactly the
mechanism needed here and required no schema or compiler change.

**v5** (`Version = 5`): told the model to point `boundary_line_ids` at the first item's own line
(marked `service_boundary` + `boundary_also_item`) when no separate heading exists, rather than
leaving it empty. Identity rose to **35/38 — the first version to exceed the legacy baseline (34)**.
`service_accuracy` and `date_accuracy` both 1.0. Item precision/recall 99.6%/97.1%. Gate 10 passed on
the primary run (36.7% vs baseline 40%). All ten scoreable hard gates passed on the primary run; only
gate 9 (weekly/archive parity, needing a separate contract test) stayed `not_scored`.

**The replicate** (`-v5-replicate`, same v5 prompt, second full-corpus arm) is where the picture
gets more careful. Paired against the primary via `--replicate=`, the raw self-disagreement rate is
52.6% (20/38 sources) against the plan's own 10% diagnostic ceiling — but 19 of those 20 are in the
`provenance` field group (which line IDs were cited as evidence), not the compiled service/date/items
themselves; only 1 source shows an actual `plan_key` disagreement. Scored independently against
truth, the replicate's own identity is 34/38 (matches legacy exactly, doesn't exceed it) and its
first-pass rate is exactly 40.0% — tied with, not under, the baseline, so gate 10 fails on that run
in isolation even though gate 7 (identity) passes on both runs. `field_decomposition` beyond
provenance: `item_structure` 9/38, `titles` 3/38, `service_date_scope` 1/38 — none of these were
broken down further this session; that's the natural start for §9.4 step 9 option (b) if it's chosen.

**Net honest read:** three real, code-verified, single-lever fixes landed and each is independently
defensible (service-context inference, the `other` scope correction, and the boundary fallback).
Identity moved from below legacy (29) to reproducibly at-or-above it (34–35 across two draws). But
the "beats baseline" claim for gate 10 specifically has not yet reproduced across both draws
available, and that gap should be closed or explicitly accepted by the maintainer before this is
called a passing Delivery 6 candidate — not smoothed over by another paid run chosen to get a better
number.

### 9.7 2026-08-20 (later session) — gate 9's parity contract test

Zero-spend work, deliberately chosen over another paid draw: gate 9 is the only remaining unscored
gate that is a property of two code paths rather than of the model, so it is settleable
deterministically while §9.4 step 9's (a)/(b)/(c) call is still open. It is required under every one
of those three options.

`tests/Feature/Services/Email/OosParserEntryPointParityTest.php` (four tests, group
`oos-parser-parity`) drives one declared source document through the real weekly job
(`ProcessInboundOosEmail`) and the real archive command (`oos:import-archive --evaluate`) with a
fixed recording extractor, and asserts:

1. both entry points hand the parser identical subject, body and received date;
2. their stored projections are byte-identical under `CanonicalJson`, excluding only timings and
   `source_message_id` — the latter asserted separately on each side so a projection that stopped
   recording it cannot pass by omission;
3. the archive's raw-payload encode/decode round trip is byte-preserving, so a cache reuse cannot
   diverge from a fresh parse;
4. archive identity resolution changes identity and not items.

Four design findings, each forced by a failure or a mutation rather than assumed:

- **The archive side must run `--evaluate`, not `--import`.** Both reach the same `parseResult()`,
  but `--import` writes a canonical service, which the *weekly* run's duplicate lookup then
  correctly reacts to — dropping confidence 0.95 → 0.74 and flipping the disposition to
  `review_required`. The first draft compared an archive parse made against an empty database with a
  weekly parse made against one the archive had just populated, and so measured the duplicate
  detector rather than parity.
- **The weekly email must be built from declared constants, not copied from the archive email.** The
  first draft copied the archive email's own fields, which made finding 1 a tautology: a mutation
  shifting the archive's received date by a day moved both sides and passed. The source document is
  now stated once (including an explicit `source_date`, rather than left to the curation factory's
  "N days before" fallback) and given to each path independently.
- **`OosArchiveIdentityResolver` fills gaps in identity; it does not overrule identity the parser
  supplied.** Read in code after a fixture built on a manifest/parser *disagreement* left the
  resolver a complete no-op: it returns the parse untouched once a plan has both a date and a
  service, and again when the plan's service is absent from the manifest's. The fixture therefore
  has the extractor return a dateless plan and the manifest supply the date, which is the shape that
  actually exercises resolution. The test asserts that gap was real and was filled, so it cannot
  degrade back into a tautology silently.
- **Verified by mutation, not by passing.** Against the final fixture, shifting the archive's
  synthesised received date fails test 1, and reintroducing the pre-HIR2 defect of caching the
  *resolved* result as the raw payload fails tests 2 and 4. Both mutations passed unnoticed against
  earlier drafts, which is why they were rewritten. Both were reverted; `ImportOosArchiveCommand`
  is unchanged by this work.

**One blocker this surfaced, needing a maintainer decision rather than more engineering.**
*(Resolved the same day — see §9.10, which records the decision taken. Kept here because the analysis
is what produced the decision.)* The
contract test now exists and passes, but `OosSemanticCorrectnessScorer` deliberately does not read
suite results, so its gate 9 stays `not_scored` — and `not_scored` blocks a `pass` verdict by
design. As it stands, therefore, *no* comparison artifact can ever reach `verdict: pass`, and
Delivery 7's precondition of "a signed passing comparison artifact" is unreachable on the scorer
alone. Two legitimate routes, both maintainer calls about acceptance criteria rather than code:
accept the suite run as gate 9's evidence *outside* the artifact and treat `incomplete`-except-gate-9
as the passing shape; or give the scorer an explicit attested input naming the test and the commit
it passed at. The scorer was left unchanged apart from recording the test's path and this note,
because flipping gate 9 to `pass` would make it assert something it never checked.

Verification: full suite **7,000 tests, 83,893 assertions** (+4 tests / +20 assertions over the
prior banked run, all four from this file), 140 existing PHPUnit notices unchanged, no failures.
PHPStan 0 errors across 837 files. Pint passed. Dusk 55 passed. No paid model request and no
production mutation; paid-call count unchanged at six.

### 9.8 2026-08-20 (later session) — option (b) decomposition, and the single cause behind gate 10

§9.4 step 9 option (b) was taken: decompose the replicate's disagreements before spending on another
draw. It produced a larger answer than the option was scoped for, and no paid call was made.

**The `item_structure` 9/38 does share a cause, but it is not the failing gate.** Reusing
`OosParserExtractionSignature` so the grouping cannot drift from the scorer's own figure, 7 of the 9
are *item-kind* disagreements on the same item at the same position — not missing or extra items.
Two axes recur: `other/other` against a specific kind (interview, prayer, childrens_talk,
call_to_worship) on four sources, and `welcome` against `notices` at position 1 on two. Only two
sources (`2018-11-18`, `2022-08-14`) differ structurally in item count or alignment. This is a
stability finding; it is not what gate 10 measures.

**Gate 10's failure has one dominant cause, and it reproduces on both draws.** Gate 10 counts
*first-pass* (pre-repair) validation failures, and the whole difference between the primary's pass
and the replicate's fail is a single source: 11/30 against 12/30, either side of a 12/30 baseline.
Reading the first-pass rule codes out of both candidate artifacts, `non_item_has_kind` fires on
**13 of the 13** first-pass-failing sources in the primary and 13 of 14 in the replicate. Every other
code is a one-off. Counted with the validator's own `$isItem` rule — role `item`, or
`boundary_also_item` — the offending lines are 34 in the primary and 33 in the replicate, in the same
three patterns and the same proportions both times:

| Pattern | Primary | Replicate |
|---|---|---|
| `supporting_detail` carrying kind `sermon` | 17 | 17 |
| `transition_marker` carrying kind `transition` | 11 | 11 |
| `continuation` carrying kind `song` | 6 | 5 |

All three are one confusion: the model treats `item_kind` as *what the line is about* rather than
*what the line is*. The prompt never said otherwise — `item_kind` was the one field with no stated
rule, so a line giving the sermon's passage got kind `sermon`, a `transition_marker` mirrored its own
role name into a kind, and a wrapped song title repeated its parent's kind.

Checked and excluded rather than assumed: the 7 `service_boundary` lines that also carry a kind all
have `boundary_also_item: true`, so the validator counts them as items and they are legal. They are
v5's boundary guidance working, not a defect, and are not part of the 34.

**Projected effect, not a measurement.** Removing `non_item_has_kind` leaves one residual failing
source in the primary (`2018-02-04-details`) and three in the replicate (plus `2026-04-12` and
`2015-10-18`); all are in the 30-source stability sample, none are hard cases. That would move gate
10 from 36.7% to **3.3%** on the primary draw and from 40.0% to **10.0%** on the replicate, against
the 40% baseline — a pass on both draws with a wide margin instead of the current knife-edge. Only a
paid run establishes this; it is a projection from banked artifacts.

**`OosSemanticAnnotationPrompt::Version = 6`** makes the single corresponding change: `item_kind`
says what a line is and not what it is about, so it is set only on a line that is itself an ordered
item, and left null on every other role even when the line plainly concerns an item of that kind,
naming the three observed patterns. Nothing else was changed — in particular the `other/other` and
`welcome`/`notices` stability axes above are deliberately left alone, so that if a v6 arm is run its
effect on gate 10 is readable as one lever, per the discipline that made v3/v4/v5 legible.

Verification: full suite **7,000 tests, 83,893 assertions**, 140 notices, no failures; PHPStan 0
errors; Pint passed.

### 9.9 The v6 arm — the projection held, and gate 10 stops being marginal

One paid arm, approved in-session by the maintainer immediately before the run, against the
`-2026-08-20-adjudicated` corpus (hash unchanged), same `gpt-5.6-terra` / `low` / flex dimension as
v5 so the prompt is the only moving part. 41 calls (38 annotation, 3 repair), 250,723 tokens,
$1.037456 at the dated snapshot's projected rate. Paid-call count **six → seven**. Artifacts:
`oos-semantic-candidate-terra-low-2026-08-20-v6.json` and
`oos-semantic-score-terra-low-2026-08-20-v6.json`.

**The diagnosed defect is gone outright.** `non_item_has_kind` offending lines 34 → **0**; the code
does not appear in the arm at all. First-pass failing sources 13 → **4**.

| | v5 primary | v5 replicate | v6 |
|---|---|---|---|
| Gate 10 first-pass rate (sample of 30) | 36.7% | 40.0% | **13.3%** |
| Corpus first-pass failures | 13/38 | 14/38 | **4/38** |
| Gate 7 identity | 35/38 | 34/38 | **35/38** |
| Item precision / recall | 0.9961 / 0.9715 | — | **0.9980** / 0.9696 |
| Semantic item-kind accuracy | 0.9667 | — | **0.9725** |
| Post-repair content findings | 1 | 1 | **0** |

All ten scoreable gates pass. As scored on the day, gate 9 was still `not_scored` for the
scorer-semantics reason in §9.7, so this arm's *first* scoring artifact reads `incomplete`; §9.10
reclassified that gate and the re-scored artifact reads `pass`. Gate 10's margin is 13.3% against a 40%
baseline rather than the 36.7%/40.0% knife-edge that turned on a single source.

**The projection was directionally right and numerically optimistic, which is worth recording.**
§9.8 projected 3.3% on this draw's shape by assuming the fix would only *remove* failures. It also
introduced one: `2020-11-01` now fails `item_semantics_incomplete` — an item line left with a null
kind, the exact opposite error, and a mild overcorrection of v6's wording. The other three residual
failures are not v6's doing (`2026-04-12` and `2026-07-05` on `shared_boundary_role_invalid`, which
the v5 replicate also hit; `2018-02-04-details`, which every arm has hit). A single-lever prompt fix
trading 13 sources for 1 is a clear win, but "removal only" is not a safe assumption for the next
projection.

### 9.10 Gate 9 reclassified as a precondition — the first passing artifact

**Maintainer decision, 2026-08-20.** Entry-point parity is no longer §6.3 gate 9; it is a
precondition recorded in the scoring artifact, and the verdict is computed over the arm-scoped gates
only. The reasoning is that it never behaved like a gate: it is the same value for every arm, no arm
can move it, and the scorer therefore reported it `not_scored` on every artifact — which, because
`not_scored` blocks a pass, made `verdict: pass` structurally unreachable and Delivery 7's "signed
passing comparison artifact" precondition unsatisfiable.

This does edit acceptance criteria that were deliberately fixed before any spend, which was raised
as a concern and accepted by the maintainer. Recorded plainly so the change is visible rather than
implicit: the criteria moved *after* good numbers were seen, and the reader should weigh it
accordingly. What did **not** change is which conditions must hold — parity is still required, still
`hard`, and is still evidenced by a real contract test. Only where it is recorded moved.

`OosSemanticCorrectnessScorer::Version` is now 2. A version 1 artifact carries a gate 9 and no
`preconditions` block and is not comparable by verdict alone; the banked v2–v6 version 1 artifacts
are retained exactly as scored.

The `preconditions` block records a **checkable fact, not a claim**: the contract test's path, its
presence in the scored tree, and its SHA-256 — never an assertion that it passed, because the scorer
does not read suite results and must not assert what it has not checked. Together with the
`application_commit` already in `inputs`, that lets any reader re-run exactly that test at exactly
that commit.

Re-scoring the unchanged v6 arm under the new format gives
`storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-reclassified.json`, `score_hash`
`46bd60c483c097606efb8ec6f16519d600dbecf8a41b1cf82489f0d7d9e1281f`, **`verdict: pass`** — the first
passing comparison artifact in this plan's history. Ten of ten arm-scoped gates pass. Gate 11 is
soft and reports `stability: null`, correctly: a correctness-first artifact has no replicate, and
`pass` here means correctness passed, which is exactly what §9.4 step 8 treats as the trigger for
running replicates.

### 9.11 The v6 replicate — §9.4 step 8 satisfied, with one honest caveat

One further paid arm, approved in-session immediately before the run: the unchanged v6 prompt, same
dimension, second full-corpus draw. 38 calls and **zero repair calls** — no source needed repair on
that draw — 239,865 tokens, $1.008995. Paid-call count **seven → eight**.

**Both draws pass independently.** This is the property v5 never had: its replicate scored `fail` on
gate 10 in isolation, so "beats baseline" rested on one draw.

| | v6 primary | v6 replicate |
|---|---|---|
| Verdict scored alone | **pass** | **pass** |
| Gate 7 identity | 35/38 | 35/38 |
| Gate 10 first-pass rate | 13.3% | **3.3%** |
| Corpus first-pass failures | 4/38 | **1/38** |
| Item precision / recall | 0.9980 / 0.9696 | 0.9922 / 0.9715 |
| Semantic item-kind accuracy | 0.9725 | 0.9785 |
| Post-repair content findings | none | none |
| Cost | $1.0375 | $1.0090 |

Paired artifact: `oos-semantic-score-terra-low-2026-08-20-v6-final.json`, `score_hash`
`6d8246a26ed3d4545f8a60de5849f3bd1531d2e9298b39da4ae892199c6d1791`, `verdict: pass`.

**The caveat, stated plainly rather than left in a soft gate's detail block.** Replicate
self-disagreement is **42.1%** (16/38) against this plan's own 10% diagnostic ceiling. It improved
from v5's 52.6% and, importantly, `plan_key_disagreements` is now **0** where v5 had 1 — the
compiled service/date identity is now perfectly stable across draws, and every source's *verdict*
is unaffected. The residual is annotation-level: `provenance` 15 (which line IDs were cited as
evidence), `item_structure` 10 and `titles` 4. That is the same item-kind classification axis §9.8
identified — `other/other` against a specific kind, `welcome` against `notices` — and deliberately
did not fix, so that v6 would read as one lever. It is unchanged to slightly worse (9→10, 3→4),
exactly as expected from a change that did not target it.

Gate 11 is soft and reports stability "for information only; it cannot override a correctness or
safety gate", so nothing fails on this. But a 4× overshoot of a ceiling the plan set for itself is a
maintainer decision to take explicitly — accept it as out of scope for Delivery 6 (the compiled
output is stable and every gate passes), or spend a further single-lever arm on the item-kind axis
before closing. It should not be waved through on the strength of the passing gate list.

**§9.4 steps 6–8 are now complete**, and step 9's open caveat was settled in §9.12. What remains
before Delivery 7 is step 10: the maintainer's explicit production-default approval, which is a
separate act from accepting the artifact and has not been given.

### 9.12 Splitting the stability figure — and a correction that changes the caveat

The §6.3 10% self-disagreement ceiling is inherited from the whole-document parser, where a
self-disagreement *was* a changed answer. Under the annotation architecture the two came apart, and
§6.3 already anticipated this in prose: a source-faithful candidate "is not rejected solely because
an irrelevant semantic subtype varies while deterministic projection remains correct and safe". The
metric did not implement that distinction. It now does.

`OosSemanticCorrectnessScorer` (format **Version = 3**) reports two figures. `rate` is unchanged —
any signature movement, provenance included. `outcome_rate` counts a source only when something a
consumer can act on moved: service, date, scope, item structure or titles. `ceiling_applies_to`
names `outcome_rate` explicitly. Both are kept, with the full decomposition, so a provenance
regression stays visible rather than being defined away.

Re-scored v6 pair: `storage/scratch/oos-semantic-score-terra-low-2026-08-20-v6-accepted.json`,
`score_hash` `181241300caca65d202567277ee41b17526dc970e374df1867f4caea174119ed`, `verdict: pass`.

**A correction, because the split changed the answer.** §9.11 and the session discussion leading to
it reasoned that an item-kind arm "achieves nothing", on the grounds that every source moving on
`item_structure`/`titles` also moves on `provenance`, so a perfect item-kind fix leaves the raw rate
at 42.1%. That arithmetic is right and the conclusion drawn from it was wrong: it measured against
the *raw* rate, which is exactly the figure this section has just established is the wrong one to
judge by.

Against `outcome_rate` the picture reverses:

| | value | vs 10% ceiling |
|---|---|---|
| `rate` (raw, provenance included) | 42.1% | not the figure to judge by |
| `outcome_rate` (v6 as it stands) | **28.9%** | ~3× over |
| `outcome_rate` if the item-kind axis were perfect | **2.6%** | comfortably under |
| `plan_key_disagreements` | 0 | identity never moves |

Eleven of the 38 sources move on something consumer-visible. Ten of those eleven move only on the
item-kind axis — the `other/other`-versus-specific-kind and `welcome`-versus-`notices` disagreements
§9.8 identified and deliberately left unfixed. The eleventh (`2026-08-02-pm`) moves on
`service_date_scope`.

An item-kind arm is the one change with a measured, specific projection: `outcome_rate` 28.9% →
~2.6%, which would take the candidate under the plan's own ceiling on the figure that ceiling is
actually about. That is a materially different proposition from the one rejected earlier in the
session, and the earlier rejection should not be relied on.

### Maintainer decision, 2026-08-20: the 28.9% is accepted as-is

**This is an explicit exemption, not a bar that was met.** `outcome_rate` is roughly 3× the §6.3
diagnostic ceiling, a remedy with a measured projection was available for about $2 (one single-lever
arm plus a confirming replicate), and the maintainer declined it in favour of accepting the figure.
The assistant's recommendation was to spend it. Recorded this way deliberately so that a later reader
sees an exemption rather than a passing number.

The grounds for accepting are real and are these:

- **Gate 11 is soft by design.** §6.3 states that cost, latency and stability "cannot override a
  failed correctness/safety gate", and the converse holds too — no hard gate fails. All ten
  arm-scoped gates pass on both draws.
- **`plan_key_disagreements` is 0.** No source's compiled service or date differs between draws, so
  nothing about which service an order belongs to, or whether it imports, is unstable. The variance
  is confined to how an item is *categorised*.
- **§6.3 anticipated exactly this shape.** "A source-faithful candidate is not rejected solely
  because an irrelevant semantic subtype varies while deterministic projection remains correct and
  safe." The remaining variance is `other/other` against a specific kind and `welcome` against
  `notices` — subtype choices on items that are otherwise identically bound.
- **Practical exposure is low.** A weekly email is parsed once; the archive reuses a cached raw
  extraction rather than reparsing, and §9.7's contract test pins that round trip as byte-preserving.
  Re-parse is an operator action, not a routine one, so the instability is rarely observable in
  production.

What is knowingly given up: an item's displayed category can differ between two parses of the same
source, and the plan no longer has a stability figure under its own ceiling. If item categorisation
is later found to matter more than this decision assumed — a review-queue complaint, a public-surface
inconsistency — the item-kind arm in §9.8/§9.12 is the identified remedy and its projection stands.
`outcome_rate` should be re-read on any future arm rather than treated as settled.

Note the projection's own history: §9.9 recorded that the v6 projection was directionally right and
numerically optimistic because it assumed removal-only. The same caution applies here — 2.6% is a
ceiling on the improvement, not a forecast.

Verification: full suite **7,003 tests, 83,906 assertions**, 140 notices, no failures; PHPStan 0
errors; Pint passed. No paid model request; paid-call count unchanged at eight.

### 9.13 The cheaper-model arm — `gpt-5.6-luna`, Delivery 6 step 5

Delivery 6 step 5 permits a cheaper model to be tested for non-inferiority **only after correctness
passes**, which it now has. Two full-corpus draws were run on maintainer instruction, each approved
in-session. Nothing about the switch required new code: the model is read from
`config('service-tracking.email_parsing.semantic.model')` at runtime, while
`OosParserSurfaceFingerprint` hashes files on disk, so the arms carry parser surface
`7acc08a86780d25b…` — byte-identical to the terra v6 arms. The env var was passed straight into the
container (`docker compose exec -e OOS_SEMANTIC_ANNOTATION_MODEL=gpt-5.6-luna`), which works because
Laravel's dotenv loader is immutable and a real process env var wins over `.env`; `.env` was never
edited and no default changed.

**Both draws pass all ten arm-scoped gates.** That is the correct and unhelpful headline: every §6.3
gate is an *absolute* bar against the legacy parser, so an arm can pass all ten while being worse
than the incumbent candidate. Luna does exactly that.

| | Luna primary | Luna replicate | Terra v6 primary |
|---|---:|---:|---:|
| Verdict scored alone | pass | pass | pass |
| Identity (of 38) | **34** | 35 | **35** |
| Item recall | 0.9316 | 0.9658 | **0.9696** |
| Items missed | **36** | 18 | **16** |
| Item precision | 0.9959 | 0.9980 | 0.9980 |
| Exact order rate | 0.7660 | 0.8542 | 0.8542 |
| First-pass rate (30) | **0.0667** | **0.0667** | 0.1333 |
| Boundary precision / recall | **0.94 / 0.92** | — | 0.82 / 0.80 |
| Corpus cost | $0.1042 | $0.1029 | $1.0375 |
| Latency per source | 43,074 ms | — | **7,858 ms** |
| Projected archive cost | **$1.52** | — | $15.13 |

**Where luna is genuinely better:** first-pass validation failures are half terra's rate on both
draws, service-boundary precision and recall are clearly higher, and content-scope accuracy is
0.9149 against 0.8333. These are real and were not expected.

**Why the recommendation is nonetheless to stay on terra**, in ascending order of weight:

1. **Identity 34/38 on the primary draw is exactly the legacy baseline**, not above it. Gate 7 passes
   because it tests for no regression against legacy, and 34 ≥ 34. But moving identity *above* 34 is
   what the entire v3→v6 prompt sequence was for, and terra holds 35 on both draws where luna manages
   it on one.
2. **Recall.** Luna's primary omits 36 of 526 true items against terra's 16. Gate 6's floor is 0.85
   so 0.9316 passes comfortably; in plain terms it silently drops twenty more genuine order items
   across 38 emails, and exact order falls from 0.854 to 0.766 with it.
3. **Stability, which is the decisive one.**

| | Luna | Terra v6 |
|---|---:|---:|
| `rate` (raw, provenance included) | 0.6842 | 0.4211 |
| `outcome_rate` (the figure the 10% ceiling is about) | **50.0%** | 28.9% |
| `plan_key_disagreements` | **1** | **0** |
| Decomposition | structure 16, titles 6, provenance 24 | structure 10, titles 4, provenance 15 |

`plan_key_disagreements: 1` is the line. §9.12's acceptance of terra's 28.9% rested explicitly on
that figure being zero — "No source's compiled service or date differs between draws, so nothing
about which service an order belongs to, or whether it imports, is unstable. The variance is
confined to how an item is *categorised*." Luna breaks precisely that property. One source resolves
to a different service/date identity depending on the draw, which feeds duplicate lookup and import
disposition rather than a display label. The terra exemption does not transfer to it, and would have
to be re-argued on worse facts.

**The draw-to-draw spread is itself a finding.** Luna's replicate is markedly *better* than its
primary — 18 missed items against 36, identity 35 against 34. Which luna you get is luck of the
draw, and a single arm would have supported either "close to terra" or "clearly worse" depending
which draw ran first. This is the case the plan's replicate discipline exists to catch, and it caught
it.

**Adoption would not be a config change.** Delivery 7's precondition is a signed passing artifact
*for the configuration being flipped*. Switching would make the luna artifact the one presented for
production-default approval, carrying a 50% `outcome_rate` and a non-zero `plan_key_disagreements`
into that decision, in exchange for roughly $13.60 on a full archive pass. `OOS_SEMANTIC_ANNOTATION_MODEL`
therefore still defaults to `gpt-5.6-terra` in `config/service-tracking.php` and `.env.example`.

**Operational findings, which cost more than the arms did.** Seven runs were needed to obtain two
artifacts; five failed, wasting ≈$0.20 of the ≈$0.41 total.

- **Luna is ~5.5× slower per source** (43.1s against terra's 7.9s), so a full-corpus arm takes ~33
  minutes against terra's ~5. That is invisible for weekly intake (one email, one call) and material
  for archive replay, which would go from roughly an hour to five and a half.
- **Rate limits killed two runs outright.** `OpenAI\Exceptions\ErrorException` for a 429 arrives at
  the very end of a 32-minute run and the runner writes its artifact only after all 38 sources
  succeed, so the whole run's spend is discarded. `retry_delays_ms` is `[100, 500, 1000]`, so three
  transport attempts exhaust in 1.6 seconds — a sensible curve for a dropped connection and useless
  against a rate limit. Terra never exposed this because its arms finish in five minutes.
  **A related defect exists on the legacy path, which *is* production:**
  `OpenAiOosEmailItemExtractor` retries under `catch (RuntimeException)`, and `ErrorException
  extends Exception`, so a 429 is not retried there at all; `ProcessInboundOosEmail` has `$tries = 3`
  and no `backoff`, so the queue re-runs it immediately into the same window. The practical risk to
  weekly intake is negligible (one email is one call, and Mailgun inbound has never been configured),
  but bulk archive replay is exposed. **Not fixed here** — it is unrelated to the model question and
  is recorded so it is not rediscovered.
- **Two process errors of the assistant's own, recorded so the run log reads honestly.** A healthy
  run was killed on a fabricated "stall" diagnosis caused by comparing a BST host clock to UTC
  container logs — `laravel.log` is UTC because the container's timezone is, and the one-hour offset
  looks exactly like a hang. And an unjustified `OPENAI_REQUEST_TIMEOUT=240` was introduced on the
  strength of that bad diagnosis, then reverted before the arms that produced these artifacts; both
  retained arms ran at stock settings.

**Repeated luna draws were considered and rejected on measurement, not principle.** Since nine luna
arms ($0.93) cost less than one terra arm ($1.04), the obvious question is whether several draws
could be combined to beat terra cheaply. The annotation architecture is unusually well suited to it —
invariant 2 guarantees exactly one annotation per stable line ID, so a per-line vote across draws is
well defined in a way it would not be for a whole-document parser. The two banked draws answer it at
zero further spend, by decomposing luna's missed item lines into variance (a different draw gets it
right) and bias (no draw does):

| | item lines |
|---|---:|
| Truth item lines | 535 |
| Missed by luna primary | 41 |
| Missed by luna replicate | 21 |
| **Missed by both draws** | **21** |
| Recoverable by combining the two | 20 |
| Missed by terra v6 | **19** |

The replicate's misses are a strict subset of the primary's. So an *optimistic* two-draw ensemble —
taking the union, accepting any item either draw found — lands at 21 missed lines against terra's 19:
it buys back luna's variance and arrives approximately where terra already is. Three caveats make the
real figure worse, not better. The union is the optimistic bound; a sane design is a per-line
majority vote, which needs three or more draws and is *stricter* than a union, so it recovers fewer
than 20. The union also maximises recall at precision's expense, admitting every draw's false
positives. And 21 is only the two-draw estimate of the bias floor — more draws can lower it, but only
among lines some draw already gets right.

The costs that are not dollars decide it. Nine draws is nine times 33 minutes — about five hours per
corpus pass against terra's five minutes — and nine times the rate-limit exposure that already
destroyed two runs at 41 calls. More importantly the voting layer would be new permanent code between
annotation and compilation: a behaviour-bearing surface that must join
`OosParserSurfaceFingerprint`, carry its own gates and be evaluated from scratch, since an ensembled
parser cannot be scored as a luna arm. That is a standing maintenance obligation and sits close to
§10's "multi-agent extraction" non-goal, incurred to reach parity with a model already configured, to
save ~$13.60 on a full archive pass.

**The decisive fact is that there is no meaningful premium to justify, and it should have been
computed before any arm ran.** The 10× price ratio is real and, at this workload, misleading:

| | Luna | Terra |
|---|---:|---:|
| Per source | $0.0027 | $0.0273 |
| Weekly parsing, per year (52 emails) | $0.14 | **$1.42** |
| Archive pass (554 sources, one-off) | $1.52 | **$15.13** |

The entire financial case for luna is **$13.61 once plus $1.28 a year — about $20 over five years**.
Terra's annual cost for weekly parsing is $1.42. The five failed luna runs on 2026-08-20 burned
$0.20, or roughly two months of that.

This cuts symmetrically against the quality argument as well as for it. A 41-versus-19 missed-line
gap out of 535 would not on its own justify paying 10× — but when the saving is $20 across five
years, *no* regression is a good trade, and luna's are not nothing: identity on the legacy bar rather
than above it, `outcome_rate` 50% against 28.9%, `plan_key_disagreements` 1 against 0, 5.5× the
latency and demonstrated rate-limit fragility. Switching also is not free in effort, since Delivery 7
would then be approving the luna artifact and its non-zero `plan_key_disagreements` after §9.12
accepted terra's 28.9% specifically on the grounds that terra's was zero.

The general lesson, recorded because it generalises past this plan: model price only drives a
decision when it is a meaningful fraction of total system cost. This workload is 554 archive sources
once and ~52 emails a year, so the model bill is rounding error against any human time spent on it.
Compute absolute annual spend before comparing ratios.

**The question is closed on evidence, not left open.** Neither a third luna draw nor an ensemble is
worth running: both re-roll the same dice against a $20 prize. Should the workload ever change shape
— a continuously reparsed corpus, or per-source costs orders of magnitude higher — the two banked
luna arms and this cost table are the starting point for reopening it.

### 9.14 The rate-limit retry fix, and the parser surface move it causes

The §9.13 operational finding was fixed rather than left recorded. Four defects, one shared cause —
the code had no notion of "ask again later" as distinct from "this answer is no good":

1. **`OpenAiOosEmailItemExtractor` never retried a rate limit at all.** It retries under
   `catch (RuntimeException)`, and the OpenAI client raises every HTTP failure as
   `ErrorException extends Exception`. A 429 therefore aborted the parse on the first response. This
   is the **production** path (`OOS_EMAIL_PARSING_IMPLEMENTATION` is `legacy`).
2. **The semantic path classified 429 correctly but waited 1.6 seconds.** `retry_delays_ms` of
   `[100, 500, 1000]` exhausts three attempts faster than any rate-limit window.
3. **`isTransient()` existed verbatim in two classes**, the annotator and the repairer.
4. **`ProcessInboundOosEmail` had `$tries = 3` and no `backoff`**, so the queue re-ran it immediately
   into the same window.

`App\Support\OpenAiTransientFailure` is now the single rule for both the classification and the
wait, because getting one right and the other wrong fixes nothing — defect 2 is precisely that case.
A provider-supplied `Retry-After` always wins; otherwise a rate limit waits 2s/8s/20s while a dropped
connection or 5xx keeps its prompt 100/500/1000ms retry. The extractor gained a transport budget
(`OOS_EMAIL_PARSING_TRANSPORT_ATTEMPTS`, default 3) held **separately** from `extraction_attempts`,
so a 429 no longer consumes a semantic re-ask that exists for a different purpose. The job backs off
30s then 120s.

**The parser surface fingerprint has moved: `7acc08a86780d25b…` → `5fe49c252e7795b4…`.** This is
unavoidable — `OpenAiOosEmailItemExtractor`, `OpenAiOosSemanticAnnotator` and
`OpenAiOosSemanticRepairer` are all in the fingerprint's file list, so any fix to them moves it — and
`OpenAiTransientFailure` was added to that list under invariant 11, since a retry that does not
happen changes what an arm extracts as surely as a prompt does.

**The consequence, stated plainly because Delivery 7 approval is pending.** Every banked arm (terra
v2–v6 and both luna arms) recorded `7acc08a8…`, so `OosSemanticCorrectnessScorer` now **refuses** to
re-score any of them — verified by running it, which reports "The candidate arm ran a different
parser surface from the one scoring it". The already-written scoring artifacts are unaffected and
remain valid: they were computed when the surfaces matched, and each records the hash it was computed
against. What is no longer possible is *re-deriving* them from the current tree. If a future
maintainer needs to reproduce the accepted terra v6 comparison rather than read it, the route is to
check out commit `bd355bc84` or earlier, not to re-run the scorer at HEAD. Re-running a paid arm at
the new surface would also restore comparability, at ~$1.04, and is not required by anything
currently open.

### 9.15 Delivery 7 — the flip, the wiring defect it exposed, and the legacy deletion

**Approval.** The maintainer reviewed the Delivery 6 artifact on 2026-08-20 and approved both it and
the production default flip, choosing explicitly to accept the §9.14 parser-surface move rather than
spend ~$1.04 re-running a confirming arm at the new surface. Recorded as a judgement call, not a bar
that was met: the accepted evidence and the shipped code do not share a fingerprint, and the reason
that is tolerable is that the diff is transport-only.

**The flip was not a config change.** Flipping the default surfaced a defect that had been latent
since Delivery 2. `OosEmailParserService::$semanticParser` was declared
`?OosSemanticParserCandidate $semanticParser = null`. Laravel's container resolves a *bound
interface* for such a parameter but declines to build an *unbound concrete class*, silently using the
default instead — so `app(OosEmailParserService::class)` held `null`, and every production entry
point (the weekly job, reparse, approve, the archive command) would have thrown
"Semantic OoS email parser is selected but not configured." on the first real parse. Verified by
direct container resolution, not inferred.

Two things made it invisible. Every semantic test constructs the parser by hand and passes the
candidate by name, which proves the candidate works when supplied but never that production supplies
one. And `OosSemanticRepairer` — an interface with an explicit binding in `AiServiceProvider` —
resolved correctly under an *identical* declaration, so the two dependencies of the same shape
behaved differently with nothing to show it. The dependency is now required, which moves the
guarantee into the signature where the container honours it, and the `not configured` throw is gone
as unreachable.

**Legacy deleted, and why the rollback flag went with it.** The plan had said "rollback is a config
reversal to the retained legacy parser". That did not survive review. A config-flag rollback buys
speed-without-a-deploy, which is worth its complexity only when something runs unattended; here
Mailgun inbound routing has never been configured (so no live weekly traffic exists), the archive
import is operator-invoked, and invariant 9 means nothing publishes unattended. The deletion gate in
Delivery 7 was already satisfied — approved artifact, passing production-shaped coverage, and the
"one processed production-shaped source" clause vacuous because inbound routing does not exist — and
Delivery 7's acceptance criteria actively require the legacy retry to be gone. Keeping it was
therefore not even plan-compliant.

Deleted: `OpenAiOosEmailItemExtractor` (638 lines), `OosEmailItemExtractor`,
`CorrectiveOosEmailItemExtractor`, `AdjudicatingOosEmailItemExtractor`, the correction/adjudication
block and its six private helpers in `OosEmailParserService`, the `AiServiceProvider` binding, and
the `email_parsing.implementation` and `transport_attempts` config keys. Net −964 lines.

**Judgement calls worth not re-litigating:**

- **`consensus` and `adjudicated` stay on `OosEmailParseResult`, permanently `false`.** They are
  downstream *policy* inputs, not parser outputs: `OosArchiveIdentityResolver` gates unattended
  import on `consensus`, and `OosArchiveAssertionBundle` writes both into a portable format older
  bundles are read back from. §3 puts that policy and those deletion-scheduled one-shots outside this
  plan's authority and §5.1 forbids silently changing a portable format, so retiring the fields
  belongs to IC8 alongside the bundle format.
- **The archive cache namespace suffix is now unconditional.** It used to depend on the
  implementation config. Legacy cache *rows* are database state and outlive the code, so under
  invariant 11 the legacy namespace must stay permanently unreachable rather than become reachable
  again the moment the key selecting it disappeared.
- **`OosArchiveAssertionBundle` now fingerprints `OosParserSurfaceFingerprint`'s hash** where it used
  to hash the single legacy extractor file, and reads `email_parsing.semantic.model` for `model_id`.
  The key answers the same question — which extraction code produced these assertions — but the
  semantic equivalent is a surface, not one file.
- **The evaluation-arm machinery is retained to IC8**, along with the `email_parsing`
  model/prompt/attempt config keys it reads. Legacy arms can no longer be *run*; that costs nothing,
  since the banked legacy baselines are artifacts and §9.14 already established they cannot be
  re-derived at HEAD anyway.

**Test migration.** `tests/Support/FixedOosSemanticParserCandidate.php` replaces the fake legacy
extractor everywhere a test needs a deterministic parse rather than a real one, by overriding
`OosSemanticParserCandidate::parse()`. It is deliberately not a fake *annotator*: that would make
forty pipeline tests author line-by-line annotation maps and would put the real compiler in their
path, so a compiler change would fail tests that are not about the compiler. Tests that do mean to
exercise annotation and compilation still bind `FakeOosSemanticAnnotator` and run the real candidate.

Seven tests were deleted as testing behaviour that no longer exists — six asserting legacy
corrective-retry and adjudication mechanics, plus the legacy half of the rate-limit retry test. One,
`it_holds_a_parse_when_a_corrective_retry_still_merges_separate_item_lines`, was **kept and rewritten**
as `it_holds_a_parse_that_merges_separate_item_lines`: the retry framing is gone but what it guarded
is not, since a `continuation` the source does not support is a content defect the deterministic
validator must hold whatever produced it.

**Verification:** 6,988 tests pass (one pre-existing environmental skip in `TempDiskSpaceCheckTest`),
`composer phpstan` zero errors, `pint --dirty` clean, `artisan dusk` 55 passed.

**What remains open: nothing.** The §9.12 `outcome_rate` caveat — 28.9% against a 10% diagnostic
ceiling — is **closed by explicit maintainer exemption**, and its named remedy, the single-lever
item-kind arm, was **declined at the same time**. Delivery 7 does not reopen it and no decision about
it is outstanding.

Do not read the retained tooling as a pending action. §9.12's requirement is *conditional*:
`outcome_rate` must be **re-read on any future arm** rather than assumed settled. The §9.13 retention
amendment preserved the runner, scorer, freezer, adjudicator and safety-fixture harness so that
accepting the caveat did not destroy the means of honouring that condition — not because acting on it
was scheduled.

What would legitimately reopen it, all external to this plan: an arm run for some other reason (a
model change, a prompt change), evidence that the instability actually bites — an archive pass
diverging materially on reparse, or unstable items reaching the review queue — or inbound routing
being configured, which would make weekly parses real and repeated for the first time. None of those
hold as of 2026-08-20.

## 9.16 Post-acceptance review slice — 2026-08-21

An external review of the delivered parser
([`docs/reports/order-of-service-email-parser-redesign-review-2026-08-20.md`](../reports/order-of-service-email-parser-redesign-review-2026-08-20.md))
raised one high and two medium findings. All five findings were verified against the code before
any change. This section records what was done and, where the review's own recommendation could not
be followed as written, why.

### Gate 5 now scores both unattended tiers (review finding 1)

`OosSemanticCorrectnessScorer::routing()` admitted a plan to the unattended set only at
`confidence >= 0.90`, justified by a docblock claiming every other disposition condition could hold
a plan but never release one. Historic REV-D2 ended that when IC1 landed: `isEvidenceImportable()`
releases a `ReviewRequired` plan with trustworthy identity, and both `ProcessInboundOosEmail` and
`InboundEmailImportService::importPlan()` admit that tier unattended. Because the semantic compiler
fixes extracted confidence at `0.75`, the old test was not merely incomplete — it was *unreachable*,
and the accepted v6 artifact reported zero eligible plans while 36 review-required sources could
enter the real evidence path.

Both tiers are now answered by the production DTO. The candidate artifact records the compiled
extraction rather than the disposition assigned afterwards, so each tier's disposition-dependent
condition is bounded above rather than guessed; both approximations err towards *more* eligibility,
which is the safe direction for a safety gate. The rule text is recorded in the artifact.

**Acceptance-criteria decision (maintainer, 2026-08-21).** Gate 5 asserts *identity and scope* for
the evidence tier, and continues to require exact content for the auto-import tier. Requiring exact
content from an evidence tier would contradict REV-D2, which admits uncertain content on purpose;
item-level accuracy stays with gate 6 and with the quarantine that keeps the tier unreviewed,
unfinalised and outside release eligibility. What the evidence tier must never do is file
trusted-looking content against the wrong service, date or scope.

**Measured result.** Rescoring the banked v6 candidate under the corrected gate: 47 evidence-tier
eligible plans, 0 auto-import eligible, 0 incorrect unattended imports, and **8 misfiled evidence
admissions**, all of them pure `content_scope` disagreements — service and date are correct in every
one. Gate 5 therefore **fails**, and the v6 artifact's `pass` verdict does not survive the
correction. Direction matters for triage:

- **3 over-claims** (truth `partial`, candidate `full`): `2023-10-15-hymns`, `2026-08-02-pm`,
  `2024-05-12`. These are the risky direction — an incomplete order presented as complete.
- **5 under-claims** (truth `full`, candidate `partial`): `2018-11-18`, and the *evening* plan of
  `2017-04-30`, `2016-04-03`, `2016-03-20`, `2018-04-15`. Four of the five are the second service of
  a two-service email, which looks like a systematic annotation behaviour rather than noise and is
  the first thing a future scope-focused arm should target.

A related fail-open was fixed alongside: `typedPlan()` defaulted a plan that stated no scope to
`full`, scoring an unstated scope as a confident claim. It now defaults to `unknown`. No plan in the
current corpus is affected.

### Explicit dates that do not exist are refused (review finding 2)

`OosServiceDateResolver::date()` accepted `CarbonImmutable::createFromFormat()` without checking the
result, and Carbon *normalises* an overflowing date rather than rejecting it, so `31 February 2018`
became `3 March 2018`. The archive path has manifest corroboration to catch that; the weekly evidence
path has none and would keep the wrong identity. The guard compares the parsed day and year against
the digits the source stated, rather than reformatting the string, because the calling patterns admit
separators and month spellings a round-trip would not reproduce.

Two deliberate limits: the `.` separator the numeric pattern admits but the format has never parsed
is left alone, because normalising it would newly resolve dates the corpus was measured without;
and the corpus contains **zero** impossible explicit dates, so this fix is provably inert on it and
does not disturb any banked measurement.

### The evidence chain was broken at the writer, not only at the scorer (review finding 3)

The review recommended recomputing the artifact hash and comparing it fail-closed. Implemented
literally, that refuses **every artifact ever produced**, including the accepted v6 — so the review's
step 1 was self-blocking. The cause is not tampering but an encoder mismatch: `CanonicalJson::hash()`
encodes with `JSON_PRESERVE_ZERO_FRACTION`, while the artifact writers omitted it. Any float landing
on a whole number — `rate()` returns `float`, so `service_accuracy: 1.0`, `title_binding.rate: 1.0` —
was persisted as `1`, re-read as an `int`, and could never reproduce the hash taken over it. Corpus
artifacts verified only because they carry no computed rates, which is why this survived undetected.
A sweep of all private artifacts found 24 affected, all from the two OoS writers; five further
apparent hits were `manifest_hash` fields that hash an *input*, not the artifact.

Three things were done:

1. **The writers were fixed** through one shared helper, `CanonicalJson::encodeReadable()`, so the
   readable and hashed forms cannot drift apart again. Every self-hashing writer now uses it.
2. **Verification is enforced** fail-closed for both candidate and replicate, with tamper tests. The
   scorer test helper now rehashes a mutated candidate, mirroring the corpus path — leaving the stale
   hash in place had silently made every mutation test a tamper case, which is exactly why the
   scorer's failure to verify went unnoticed.
3. **The two artifacts the accepted score consumes were restamped** by
   `evidence:rebaseline-hash`. This is a **one-time re-baseline forced by an encoder defect, not a
   fresh attestation**: it cannot distinguish encoder loss from an edit. Superseded artifacts (v3–v5,
   luna) were deliberately *not* restamped — restamping them would falsely imply they had been
   verified.

**What corroborates the re-baseline.** Every other binding is untouched and still checked: corpus
hash, parser surface hash, per-source input hashes. Beyond those, rescoring the banked candidate at
its recorded parser surface reproduced the previously reported metrics with **zero differences**,
and the safety-fixture results hash reproduced exactly — independent evidence that the content did
not move, which is the guarantee the self-hash was supposed to provide.

**How the rescore was run.** The banked candidate predates Delivery 7, so five surface files plus the
fingerprint's own file list differ from HEAD. The rescore therefore pins those files to
`c4455b897` — the candidate's recorded `application_commit` — reproducing surface hash
`7acc08a86780…` exactly. This is safe for the disposition question because `planDisposition()` and
`planHoldReasons()` are **byte-identical** between `c4455b897` and HEAD, so the corrected gate
measures today's rule. The scorer itself is not part of the parser surface, so scoring changes do not
disturb the binding.

### Housekeeping (review findings 4 and 5)

- `OosArchiveParseCacheBinding` kept its own shorter copy of the parser surface paths, omitting the
  semantic decoder and continuation rule, the semantic DTOs and enums, the OpenAI payload and
  schema-limit helpers and the transient-failure policy. Both now read one list,
  `OosParserSurfaceFingerprint::Files`.
- The stale "delete once the Luna adoption report is accepted" trigger was retargeted to IC8-only in
  **six** files — the four the review named plus `OosParserArmPrimaryComparison` and
  `OosSourceFaithfulnessLabels`.
- Private-write ordering was **not** changed. The banked artifacts are already `0600` and the fixed
  writers still chmod after creation; converting them to `PrivateEvidenceFile` is create-once
  semantics that the rebaseline command deliberately needs to bypass. Left as noted debt.

### Consequences for the plan

The Delivery 6 acceptance recorded in §9.6 stands for gates 1–4 and 6–11 but **not for gate 5**,
which failed on 8 scope misfilings. That was an accuracy finding against the arm, not a safety
breach: nothing was finalised or published, the archive path is operator-invoked, and weekly Mailgun
ingress is still not configured. §9.17 resolves it.

## 9.17 `content_scope` was an undefined label — 2026-08-21

**The 8 misfilings were not one finding.** Adjudicating each against its source text splits them:
the **3 over-claims are real parser defects** — `2023-10-15-hymns`, `2026-08-02-pm` and `2024-05-12`
are "here are the hymns for tomorrow" emails, songs and at most a reading, called `full`. The **5
under-claims are truth-label errors**: `2018-04-15-am` evening is the single line "Final hymn – 436",
labelled `full`; `2017-04-30`, `2016-04-03` and `2016-03-20` evening are second-service stubs; and
`2018-11-18` is a bare hymn list of exactly the kind truth *did* flag elsewhere.

**The root cause is that the term was never defined.** `CompileOosSemanticAnnotations` derived scope
as `IncompleteService flag ? 'partial' : 'full'`, and `incomplete_service` appears nowhere in
`OosSemanticAnnotationPrompt` or `OosSemanticAnnotationSchema` — it is a bare enum value among
eight. Truth `full` was therefore the *absence of a flag*, not an assertion, the same fail-open shape
fixed in `typedPlan()` one layer down. It is the only uncertainty code used anywhere in the corpus:
7 of 51 service groups. Annotator and model were each guessing at the same undefined term, and their
guesses were self-consistent but different.

**The rule now written down.** A plan is `full` only when its items include at least one *structural
frame* item — welcome, notices, prayer, children's talk, call to worship, communion, benediction,
transition (`OosSemanticItemKind::structuralFrame()`). A plan of nothing but songs, readings and a
sermon heading is a contribution to an order, not the order itself. The `incomplete_service` flag is
still honoured first, so the model may only ever *narrow* the claim. The rule downgrades and never
upgrades, so a wrong answer holds a plan rather than misfiling one — which matters because
`ChurchServiceProjector::projectionRecords()` filters on `payload_complete` with no review gate, so
a hymn list marked `full` becomes the projected running order.

Alternatives measured and rejected: "no sermon item" couples a *scope* gate to item recall, and
"songs only" misses `2024-05-12`, which carries a reading.

**Why no arm was needed.** Candidate artifacts retain `attempts[].final_annotations`, so a
compiler-side rule can be replayed against real model output at zero spend. Across all nine banked
runs — terra and luna, prompt versions v3–v6, both replicates — raw scope disagreements of 3–9 fall
to 0 or 1 under this rule. **Caveat: the rule was fitted on terra-v6 and the corroboration reuses the
same 38 sources**, so this is a domain rule justified by its definition, not an independent measurement.

**Recompilation, not re-running.** `oos:recompile-semantic-candidate-evidence` replays a banked
candidate through the current compiler from the annotations it already stores. This is not a second
`evidence:rebaseline-hash`: that re-derived a hash from whatever a file held and could not tell
encoder loss from an edit, whereas here the model's own output — annotations, patch, telemetry,
usage, price bindings — is carried across byte-identically and only the *derived* compilation moves,
deterministically and reproducibly by anyone holding the same annotations. It fails closed on an
artifact that does not reproduce its own evidence hash, verifies each result's `source_hash` against
the supplied corpus before recompiling, and refuses an artifact already recompiled. `parser_surface`
and `inputs.corpus_hash` are restamped because the scorer reads them as "what compiled these plans";
both originals are preserved in a `recompilation` block. This also retires §9.16's pinned-surface
rescore recipe: a recompiled artifact is stamped at HEAD and scores there directly.

**Measured result.** Truth regenerated by `oos:adjudicate-semantic-corpus` over the frozen
`-prefilled` worksheet and the banked decisions (corpus hash
`f4bfb40c79942fd30dc3247f8641e0966be493811903c929ea8fb05556ba5b26`) moved **exactly 6 plans**, all
`full`→`partial`, with every service, date and item title unchanged: the 5 adjudicated above plus
`2015-10-18` evening, where truth was wrong and the candidate wrong the same way, so it had never
surfaced as a disagreement. The v6 candidate and its replicate recompiled with **3 and 2 sources
changed** respectively — only the over-claims. Scoring the pair:

- **gate 5 passes**: 47 evidence-tier eligible plans, **0 incorrect unattended imports, 0 misfiled
  evidence admissions** (was 8);
- `content_scope_accuracy` 0.833 → **1.0**;
- stability improved as a side effect — `field_decomposition.service_date_scope` 1 → 0,
  `self_disagreements` 16 → 15, `outcome_rate` 28.9% → **26.3%** (still above the §9.12 diagnostic
  ceiling, and still covered by that section's exemption);
- every other numeric metric — items, kinds, title binding, identity, repair, cost — is **unchanged**.

**Verdict `pass` on all ten scored gates.** Note that the §9.6 accepted artifact's gate-5 `pass` was
vacuous — it reported 0 eligible plans because the old rule could not reach the evidence tier. This
is the first gate-5 pass that measures anything.

Gate 5 is closed and Email RG-A is unblocked.

## 10. Non-goals

- fine-tuning before the annotation architecture and golden evaluation establish a residual need;
- an agent framework or multi-agent extraction;
- corpus-wide manual adjudication;
- changing the identity/date manifest gate;
- widening unattended evidence import, finalisation or publication;
- adding parser features or refactors to deletion-scheduled historic one-shots;
- changing canonical/public section enums before the parser proves the richer private ontology;
- building a permanent runtime shadow subsystem; offline replay and a rollbackable config seam are
  sufficient; or
- optimising token cost before correctness passes.

## 11. Completion and archive conditions

This plan completes when Delivery 7 acceptance passes, the shared parser is the sole permanent
weekly/historic path, the legacy whole-document correction path is deleted, and all remaining
temporary evaluation surfaces have explicit IC8 retirement ownership. Move this plan to
`docs/archived-plans/` at that point and update the plans index and historic IC3 status; detailed
run artifacts remain private evidence rather than repository documentation.
