# Order-of-Service Email Parser Redesign

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
9. weekly and historic entry points agree for the same source/fingerprint;
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

**Status 2026-08-19:** paid-call approval received. The raw-evidence runner now records annotation
and targeted-repair returned model, usage and latency, binds the dated price snapshot and parser
surface, and refuses overwrite. Its first real invocation stopped at the pre-network truth gate
because the frozen corpus reports 38 pending sources. No paid request was sent and no correctness,
stability or adoption verdict exists.

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

**Status 2026-08-19:** not started and not authorised. The required passing comparison artifact does
not exist; `legacy` remains the production default.

- Wire weekly and historic parsing to the same candidate configuration behind one rollbackable
  config value; legacy remains the default until approval.
- Run current-era replay and clean historic rehearsal through the shared path; historic RG-A and
  later rounds remain owned by the historic plan.
- Verify cache separation, weekly/archive parity, review evidence and no change to publication.
- Flip the production default. Rollback is a config reversal to the retained legacy parser, not
  reinterpretation of candidate cache rows.
- Delete whole-document corrective/adjudication code only after the approved candidate artifact,
  current-era replay and one deliberately processed production-shaped source all pass. This is an
  evidence event, not a calendar soak.
- Delete evaluation-only arm machinery at historic closeout/IC8; retain the permanent normalizer,
  annotator contract, compiler, validators and compact regression corpus.

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

## 9. Continuation handoff — 2026-08-19

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
- Delivery 7 is neither eligible nor authorised. It needs a signed passing comparison artifact and
  a fresh, explicit production-default approval after the maintainer has reviewed that artifact.

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

The private corpus contains verbatim email text and must remain uncommitted and mode `0600`. The
price snapshot records the official model page's displayed default rates and explicitly notes that
OpenAI's general pricing table exposes additional context/processing variants. Keep the snapshot
fixed for this arm; create a new artifact rather than editing it if the selected billing mode changes.

### 9.3 Implemented continuation surfaces

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

6. **Next step — still needs a fresh, explicit in-session maintainer go-ahead; not sought or given
   in the 2026-08-20 session.** Run one approved `gpt-5.6-terra` / `low` correctness arm against the
   **`-2026-08-20-adjudicated`** corpus (not `-2026-08-19-adjudicated`, which is superseded, and not
   the `-prefilled` one), writing to a fresh absolute path:

   ```bash
   vendor/bin/sail artisan oos:run-semantic-candidate-evidence \
     --corpus=storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json \
     --price-snapshot=storage/scratch/oos-semantic-price-snapshot-2026-08-19.json \
     --output=/var/www/html/storage/scratch/oos-semantic-candidate-terra-low-2026-08-20.json \
     --paid-model-approved
   ```

7. Score correctness before any replicate or full-corpus spend:

   ```bash
   vendor/bin/sail artisan oos:score-semantic-candidate \
     --corpus=storage/scratch/oos-semantic-evaluation-corpus-2026-08-20-adjudicated.json \
     --candidate=storage/scratch/oos-semantic-candidate-terra-low-2026-08-20.json \
     --baseline-stability=storage/scratch/oos-parser-evaluation/prompt-baseline-nano-none-2026-08-19/stability-diagnostic.json \
     --output=/var/www/html/storage/scratch/oos-semantic-score-terra-low-2026-08-20.json
   ```

   If any safety, title-binding or item precision gate fails, stop, retain both artifacts and change
   at most one of model, prompt, schema or compiler semantics in the next create-once variant.
8. Only for a correctness-passing candidate, run two deterministic stability replicates and score
   again with `--replicate=`, which fills in the §6.2 self-disagreement decomposition and completes
   the signed comparison covering all §6.3 gates, legacy total-system cost and drift checks. Gate 9
   stays `not_scored` until the weekly/archive parity contract test is named as its evidence.
9. Present that artifact to the maintainer. Do not start Delivery 7 replay, default flip or legacy
   deletion until the maintainer explicitly approves the artifact and production default change.

### 9.5 Verification banked at handoff

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
