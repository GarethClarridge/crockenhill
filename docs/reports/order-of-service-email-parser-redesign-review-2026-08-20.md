# Order-of-service email parser redesign review

**Recorded:** 2026-08-20

**Status:** Complete. **Findings actioned 2026-08-21** — all five verified against the code and
addressed; see
[`ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md` §9.16](../plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md)
for what was done and what the corrected evidence now says.

Two things turned out differently from this report's recommendations, both recorded in §9.16:

1. **Finding 3's recommendation was self-blocking as written.** The hashes are not merely unverified
   but *unverifiable*: the writers omitted `JSON_PRESERVE_ZERO_FRACTION` while the hasher includes
   it, so an integral float was persisted as an int and no artifact could reproduce its own hash.
   Enforcing verification alone would have refused the very artifact the rescore needed. The writers
   were fixed and the two consumed artifacts re-baselined, corroborated by a rescore that reproduced
   the prior metrics with zero differences.
2. **The corrected gate 5 fails.** Rescoring the banked v6 candidate under the REV-D2-aware gate
   gives 0 incorrect auto-imports but **8 misfiled evidence admissions**, all pure `content_scope`
   disagreements with service and date correct. So the acceptance claim was not merely under-evidenced
   — correcting it changes the verdict, and the v6 `pass` does not survive.

**Scope:** The implementation and evidence behind
[`ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md`](../plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md),
reviewed against its invariants and acceptance criteria and against the
[`HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md`](../plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
programme of record.

## Conclusion

The redesign's central architecture is sound and appropriately bounded. The model annotates a
lossless, line-addressed source document; strict PHP code validates and compiles those annotations
into the established service-plan DTOs; repair is local and allowlisted; and the old whole-document
extractor, corrective retry and adjudication path have been deleted. The weekly and archive entry
points resolve the same semantic candidate through the container, and legacy cache rows cannot be
reinterpreted as semantic annotations.

The implementation is substantial (108 files changed from `b4d9ef232` through `f2d74b91f`), but
the permanent runtime path is narrower than the code it replaces. The larger additions are mostly
explicitly temporary corpus, scoring and comparison machinery retained for historic IC8. There is
no benefit in splitting that machinery for style, adding another model/prompt arm, reviving the
legacy parser, or inventing a new confidence system now.

One high-priority review finding affects the *claim made by the acceptance evidence*, not the
runtime quarantine or publication boundary. Two medium findings are small, bounded omissions.
None justifies rolling the semantic parser back: the archive path is operator-invoked, weekly
Mailgun ingress is not configured, evidence-tier imports remain unreviewed and unfinalised, and
publication still requires a separate signed release.

## What was reviewed

- The full redesign plan, Deliveries 0–7 and the later §9 evidence/decision record.
- Historic REV-D2, REV-D4 and HIR-D8; IC1–IC3 and IC8 ownership; and the release boundary.
- The runtime path from `OosEmailSourceDocument` through annotation, strict decoding and schema
  limits, semantic validation, deterministic compilation, date resolution, targeted repair and
  compatibility validation in `OosEmailParserService`.
- OpenAI request construction, timeouts and bounded retries; container wiring; archive cache and
  assertion-bundle separation; the evaluation corpus, candidate runner, scorer and safety fixtures.
- The implementation commits and relevant unit, integration and feature coverage.
- The banked private artifacts. The accepted v6 score agrees with the plan's reported 38-source
  run: item precision `0.998043`, recall `0.969582`, 36 candidate sources categorised
  `review_required`, two `invalid_extraction`, and no candidate source categorised
  `auto_importable`. The inspected semantic corpus/candidate/score artifacts are currently mode
  `0600`.

## Findings

### 1. High — Gate 5 does not score the unattended evidence-import path that production uses

`OosSemanticCorrectnessScorer::routing()` (`app/Services/Email/OosSemanticCorrectnessScorer.php`,
lines 743–809) treats a plan as unattended-eligible only when its confidence reaches the `0.90`
auto-import threshold. Its explanation says every other disposition condition can hold a plan but
cannot release one. That stopped being true when historic IC1 implemented REV-D2:

- `OosEmailServicePlan::isEvidenceImportable()` admits a `ReviewRequired` plan with trustworthy
  identity and non-unknown scope;
- `ProcessInboundOosEmail` offers either auto-importable *or evidence-importable* plans to the
  unattended import service; and
- `InboundEmailImportService::importPlan()` creates or merges either tier without an administrator.

The semantic compiler fixes extracted confidence at `0.75`, so normal semantic plans cannot satisfy
the scorer's `0.90` test. The accepted v6 artifact consequently reports **zero** unattended-eligible
plans and passes Gate 5, while reporting 36 review-required sources that the real REV-D2 route may
admit as evidence. The only scorer regression test raises a synthetic plan to `0.95`; it does not
exercise the evidence tier.

The accepted artifact therefore has not established its stated claim of “zero incorrect unattended
imports on adjudicated truth”. This does **not** show that an incorrect service was finalised or
published. REV-D2 deliberately permits uncertain content to exist as flagged evidence, preserves
the identity/content-invalid holds, and prevents that evidence from becoming release-eligible.
Gate 6 also supplies strong but deliberately non-perfect item accuracy. The defect is that Gate 5's
model and wording do not match that policy.

**Recommended resolution before RG-A:** make the scoring report distinguish evidence admission from
automatic finalisation, drive both classifications through the production DTO rules, and add a
regression test for a `0.75` evidence-importable mismatch. Decide explicitly what Gate 5 means under
REV-D2: it should protect the identity/content-invalid admission boundary, while item-level
imperfection remains governed by Gate 6 and quarantine; requiring exact content from an evidence
tier would contradict REV-D2. Verify the candidate's own evidence hash at the same time (finding 3),
then re-score the already banked v6 candidate in a worktree at its recorded parser commit. No new
paid model call or new arm is needed. Record the corrected interpretation and result in the parser
and historic plans before archiving the parser plan.

### 2. Medium — impossible explicit dates can normalise to a different valid date

`OosServiceDateResolver::date()` (`app/Services/Email/OosServiceDateResolver.php`, lines 97–104)
accepts the result of `CarbonImmutable::createFromFormat()` without checking that formatting it back
reproduces the input. Carbon can normalise an overflowing date rather than reject it. The same class
already applies the correct round-trip guard to `receivedDate()`, and
`OosEmailParserService::safeDateFromFormat()` applies it elsewhere, but the new explicit-date path
does not.

An impossible source value can therefore become a plausible different identity before downstream
validation sees it. Archive manifest corroboration should hold most such historic cases, but the
weekly evidence path has no manifest and could retain the wrong identity.

**Recommended resolution before RG-A:** add the missing round-trip comparison and one resolver
regression test for an impossible explicit date. This is a small fail-closed fix, not a parser
redesign.

### 3. Medium — candidate evidence hashes are recorded but not verified by the scorer

`OosSemanticCandidateEvidenceRunner` hashes the complete candidate artifact before adding its
`evidence_hash`. `OosSemanticCorrectnessScorer::armRefusals()` verifies corpus, source and parser
surface bindings, but never recomputes the candidate or replicate hash; `inputs()` merely copies the
declared value into the score. The scorer tests mutate candidate results without recomputing the
hash and are still scored.

This leaves a gap in the plan's create-once, hash-bound evidence chain: results, attempts, usage or
price data could change after generation without an integrity refusal. There is no evidence that the
banked artifact changed, and its other bindings remain useful, but the self-hash is not currently an
enforced guarantee.

**Recommended resolution before the Gate 5 rescore:** recompute the artifact hash with
`evidence_hash` omitted, compare it fail-closed for both candidate and replicate, and add a tamper
test. This is entirely offline.

### 4. Low — the archive stale-cache warning covers less than the authoritative parser surface

`OosArchiveParseCacheBinding::ParserSurfacePaths` omits behaviour-bearing files included by
`OosParserSurfaceFingerprint`, including the semantic decoder and continuation rule, semantic DTOs
and enums, OpenAI payload/schema-limit helpers, and transient-failure policy. A future change only
to one of those files would not move the Git-derived surface commit used by the warning.

The unconditional `semantic-annotations-v1` namespace correctly prevents reuse of legacy cache
rows, so this is not a current cutover defect. Reconcile the lists (preferably through one shared
source of truth) before a future semantic-parser change is expected to reuse archive cache. It need
not delay the first semantic RG-A run.

### 5. Low — retained-tooling and private-write details need one housekeeping pass

- `OosParserArmRunner`, `OosParserSurfaceFingerprint`, `RunOosParserArmCommand` and
  `CompareParserArmsCommand` still say they may be deleted once the Luna decision is closed. The
  decision is closed, but the parser plan was later amended to retain evaluation machinery through
  IC8. Change these deletion triggers to IC8 only so the weekly debt review cannot follow the stale
  earlier trigger.
- Corpus/candidate/score commands create files and only then apply mode `0600`. The current banked
  artifacts are `0600`, so no remediation of them is needed. Before another corpus or arm is written,
  use the repository's create-once `PrivateEvidenceFile` pattern (permission before content, durable
  write, cleanup on failure) or an equivalent local helper.

These are bounded hygiene items for retained evaluation tooling, not reasons to refactor the large
IC8-deletion-scheduled scorer or historic one-shots.

## Positive evidence

- Source text and physical line identity are preserved; compilation takes item titles from the
  addressed source line instead of model-authored text.
- Strict schemas require all line annotations, prohibit extra fields and respect provider limits;
  decoder and validator checks fail closed.
- Targeted repair can only touch fields named by allowlisted validation failures and cannot introduce
  a new failure family.
- OpenAI calls use explicit global connect/request timeouts, typed transient classification, bounded
  retries and queue backoff. Tests fake the provider rather than making live calls.
- The production container resolves the semantic candidate, and a dedicated regression test protects
  the wiring issue found during Delivery 7.
- Production-shaped noise, strict-schema size, parity, source binding, repair locality, cache
  namespace, safety fixtures and compatibility validation all have focused coverage.
- Legacy extraction and corrective/adjudication code is genuinely deleted. There is no dormant
  runtime switch presenting a second parser as rollback theatre.
- Evidence admission, finalisation, convergence and release remain outside the parser, preserving the
  historic plan's ownership boundary.

## Verification limitation

This review could inspect the repository, tests, commit history and banked artifacts, but could not
independently rerun Sail commands: Docker socket access from the Codex app required escalation, and
the environment rejected that escalation because its current execution-credit limit had been
reached. The plan's banked final verification records 6,988 passing tests (one pre-existing
environmental skip), PHPStan at zero, Pint clean and 55 Dusk tests. Those results are consistent with
the inspected evidence but were not reproduced by this review. No runtime code was changed during
the review.

## Historic-import next steps

The redesign should now become an input to the existing historic programme, not a new parallel
programme or another model experiment.

1. **Close the small review slice first.** Correct Gate 5's REV-D2 model, verify candidate hashes,
   add the impossible-date guard, and rescore the banked v6 candidate offline. Also align the IC8
   deletion comments. Do not run a new paid arm unless the corrected offline result exposes a
   genuinely new parser question.
2. **Reconcile the plans to landed code.** Historic IC1 and IC2 are already implemented
   (`e5a81d191`, `aeeed8332`, with follow-up `a4be644fd`), although the historic plan and plans index
   still describe IC1 as the next implementation package. After the review slice is recorded, move
   the completed parser plan to `docs/archived-plans/`, update the plans index, and update historic
   IC3 to say the shared semantic parser has landed.
3. **Run a fresh Email RG-A on a certified clean rehearsal database.** Use the semantic-only parser,
   the approved manifest and current IC3 truth. Treat this as the new operational baseline; do not
   carry forward the legacy v12 expectations of roughly 20 identity holds, 33 content-invalid plans
   or 14 manual adjudications as facts. The semantic namespace prevents legacy raw-cache reuse, so
   budget and approve the model calls explicitly.
4. **Measure the residue before assigning human work.** From RG-A, produce the per-plan census and
   hand the operator only the newly observed identity/content-invalid cases. Confirm the 82 previously
   converged Email/OpenLP services remain `already_present`, and verify evidence-tier services stay
   unreviewed, unfinalised and absent from release eligibility.
5. **Finish IC3's remaining HIR-D8 work.** The authoritative item truth exists and the policy decision
   is recorded; implement dimension-specific cross-source corroboration and capture regression
   evidence that only corroborated dimensions finalise. Do not turn source absence into disagreement
   or manufacture parser consensus.
6. **Advance the existing lane gates.** Once the semantic RG-A shows only the designed residue, the
   operator resolves that measured residue, and IC3 truth is in place, REV-D4 permits IC5. Start with
   denominator reconciliation and OpenLP curation before video. IC4 current-era source recovery can
   proceed independently whenever the operator has the three missing sources.
7. **Keep production and publication separate.** First mutation remains an RG-B round with exact
   round approval, verified backup and post-round audit. Publication remains a later RG-C signed
   release after the unresolved editorial/consent policy decision. Retain all parser evaluation
   machinery until IC8, then delete it with the other disposable historic surfaces.

That sequence uses the finished parser, refreshes the stale operational numbers once, and returns
attention to historic convergence without further speculative parser work.
