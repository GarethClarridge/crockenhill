# Order-of-Service Email Parser Redesign

> **Status (2026-08-19): proposed, not started.** This plan replaces the queued prompt/retry
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

- Consume and verify the refreshed IC3 hymn/item truth produced by the historic plan against the
  August authority; this plan does not regenerate or approve that source evidence.
- Freeze the bounded private line-annotation corpus and its source/hash inventory.
- Record legacy outputs, routing, validation, usage and latency for the same sources.
- Add synthetic/derived fixtures for every listed failure family without committing private bodies.
- Specify the annotation DTO, rule-code and artifact formats with version constants.

**Acceptance:** the corpus is reproducible, private, hash-bound and sufficient to score every §6.2
metric; no runtime code path changes.

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

## 9. Non-goals

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

## 10. Completion and archive conditions

This plan completes when Delivery 7 acceptance passes, the shared parser is the sole permanent
weekly/historic path, the legacy whole-document correction path is deleted, and all remaining
temporary evaluation surfaces have explicit IC8 retirement ownership. Move this plan to
`docs/archived-plans/` at that point and update the plans index and historic IC3 status; detailed
run artifacts remain private evidence rather than repository documentation.
