# Historic Import: Incremental Convergence Plan

> **Status (2026-08-14): plan of record for the whole historic-import programme.** This plan
> supersedes the executable content of the three prior authorities, now archived:
> [final import readiness](../archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md)
> (findings F1, F29–F66 and decisions FR-D1–FR-D10),
> [readiness remediation](../archived-plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md)
> (blockers B1–B21, WP0–WP10, gates G0–G9) and
> [safety remediation](../archived-plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md)
> (HIR0–HIR8, HIR-D1–HIR-D8). Their findings and decisions remain the evidence record; their
> *sequences and gates* are replaced by §3–§7 here. The change of direction is the reviewed
> maintainer decision set REV-D1–REV-D4
> (`docs/reports/historic-import-plan-review-2026-08-14.md`), restated authoritatively in §2.
>
> **Production mutation is permitted only as a §7 round**: approved manifests, pre-round backup,
> incremental apply, audit report — and never publishes anything; release is always a separate
> signed act (§8). The one-shot windowed operation, its ingress freeze and its G-gate ladder no
> longer exist.
>
> **Cannot proceed without a human:** the song-identity decision HIR-D8 corroboration now depends
> on (§2.5 — resolve song titles through the catalogue in the normalizer or in the projector, both
> of which change a hash-bound contract); disposition of the catalogued rehearsal's 26 held source
> entries (§6 IC1 — reason categories overlap, and the earlier "~14" and "41" are superseded); the
> video
> curation worksheet adjudication and manifest freeze (IC5); recovery of source material for the
> three unevidenced current-era production services (IC4); every release batch (§8); and the
> editorial/consent policy decision before large public releases (§8.4).

## 1. Goal and value

Backfill the website from the historic content — emailed orders of service, OpenLP archives,
livestream/sermon recordings, and the hymn workbook — so that production reaches the durable state
each service would have if its sources had arrived through the current application at the time.
Visitors gain a browsable public service history linking sermons, children's talks, songs and
scripture back across the archive; song usage history extends to 2004; historic sermons gain the
current pipeline's transcripts, sections and media.

Two standing requirements shape everything here:

1. **Minimal manual review.** Residual human work is a designed quantity: cross-source
   corroboration and automatic finalisation do the clearing; the operator handles genuine
   ambiguity, era release decisions and spot-checks. Repeated refinement rounds are expected and
   cheap; per-item human adjudication at corpus scale is a design failure.
2. **Bulk is the weekly path.** Historic processing runs the same code as weekly processing —
   `UnifiedMediaProcessor`, `OosEmailParserService`/`InboundEmailImportService`,
   `ImportChurchServiceFromOpenLp`, `ChurchServiceProjector`, the proposal/review inbox. Every
   parser, projector or review improvement made for the import must land on the shared path, and
   single-use machinery is kept to the promotion/transport layer and retired at closeout (§11).

## 2. Decision record

### 2.1 REV-D1 — incremental convergence replaces the one-shot operation

Production apply is a repeatable, per-service, idempotent convergence round behind the
quarantine/release seam — not a windowed atomic operation. Rounds run as lanes mature; a defective
round is fixed forward per service (or superseded) rather than rolled back wholesale; completeness
is a report driven to 100% across rounds, not a precondition. Retired with the operation model, as
*gates*: the ingress freeze, deploy/config freeze, window budgets and split thresholds, the exact
whole-operation closeout, the provably no-op second import, timed restore/RPO/RTO drills, and
forced-crash recovery proofs. Retained: everything in §3.2.

### 2.2 REV-D2 — held email extractions import as flagged evidence

The unattended boundary has three tiers:

- **Existence widens.** A parsed plan whose *identity* (date + service slot) is corroborated by the
  approved curation manifest imports as source evidence regardless of content confidence, flagged
  for review instead of being held outside the service graph. Identity failures (the
  manifest-corroboration gate, which caught 8 of 8 date errors with zero false passes — do not
  weaken it) and known-bad extractions (`InvalidExtraction` for *content* reasons) still hold.
- **Unattended finalisation does not widen.** Automatic finalisation still requires the existing
  bar — confidence ≥ 0.90 with two-attempt consensus — or cross-source agreement established by
  convergence. Widening beyond that waits on the item-level ground truth (IC3) exactly as HIR-D8
  requires. Adjudication still never sets `consensus` (HIR-D6).
- **Unattended publication stays zero.** Release is a signed operator act; a service carrying
  unreviewed, unfinalised email evidence is not release-eligible.

This is the recorded maintainer decision that HIR-D6/HIR-D7's "Axis B" (never change what imports
unattended without one) requires, taken 2026-08-14 at evidence level only.

### 2.3 REV-D3 — staging rehearsal and backups replace the infrastructure rehearsal

Gating evidence per lane is: a clean staging rehearsal on `crockenhill_rehearsal` with its audit
report; a verified pre-round production database backup; the landed create-only object path; and
the post-round audit report. The archived HIR8 §14 rehearsal steps 1, 2, 4 and 6–11 (production-
shaped deploy, acquisition host custody v2, freeze-window webhook, disposable restore targets,
interleaved release attempts, timed rollback repetition) stop gating. All landed HIR0–HIR7 code is
kept and in service.

### 2.4 REV-D4 — video is sequenced after the email lane settles

Maintainer's call, against the review's recommendation to parallelise; do not re-propose
parallelism. The email lane **settles** when: (a) IC1 is implemented and a staging round shows
holds reduced to the identity/content-invalid residue; (b) the genuinely-manual adjudications
measured by the first semantic RG-A are done; and (c) IC3's ground truth exists. That state triggers
IC5. The earlier "~14" figure is a superseded legacy-v12 estimate, not a release condition.

### 2.5 HIR-D8 — cross-source corroboration may finalise unattended

**Decided 2026-08-19 by the maintainer: yes.** Independent cross-source corroboration may replace
the 0.90 model-confidence threshold for unattended finalisation, but only for the dimension the
source actually proves. A hymn-workbook agreement proves actual song usage, never count or sequence;
an OpenLP agreement proves planned song membership, count and sequence. Source absence is neutral,
not disagreement, and a cross-source mismatch routes the affected dimension to review rather than
finalising it. The
identity/date manifest-corroboration gate remains unchanged, adjudication still never manufactures
`consensus`, and unattended publication remains impossible. For uncorroborated dimensions, the
existing confidence-plus-consensus path remains until separately changed on evidence.

**Implementation status 2026-08-21.** IC3 now records whether Email evidence cleared the existing
confidence-and-consensus route and the shared projector fail-closes evidence-tier Email plans by
dimension: OpenLP corroborates planned song membership, count and order; livestream corroborates
observed song order; missing evidence stays in review; and a mismatch creates an explicit review
conflict. The existing confidence-and-consensus route is unchanged. Hymn-workbook evidence remains
the source for actual song usage when IC6 binds that source lane. Focused projector and ingestion
regressions pass.

**Recovered corpus measurement 2026-08-21.** The deleted semantic evaluation was recovered from
the local MySQL binlog and replayed from all 554 exact semantic-cache entries with zero fresh model
calls. The corrected clean import created 438 entries, retained 75 as evidence, held 41 for review
and failed none. OpenLP then staged all 427 approved archives without failure. Of 627 staged Email
service identities, 298 have OpenLP evidence: 29 automatically converged and 269 produced pending
cross-source mismatches (song membership 269, order 269, count 55). Of the 329 without OpenLP, 262
produced uncorroborated-dimension proposals and 67 currently have no pending proposal.

This established the first-pass residue, but it was **diagnostic evidence, not an accepted
HIR-D8/RG-A certificate**: the rehearsal had no seeded song catalogue (the import report therefore
resolved 0 of 2,639 song items) and every pending proposal also carried
`incomplete_projection_audit`. It is superseded by the catalogued replay below. Its "29
automatically converged" figure in particular is **withdrawn** — see the corrected reading.

**Catalogued replay 2026-08-21: HIR-D8 finalised nothing, and the 29 were misread.** The recovered
evidence was replayed on a certified-clean rehearsal carrying the song catalogue, again with zero
model calls (IC1). With the catalogue present, song-title resolution moves from 0 to 2,378 of 2,639
items, but **every projection figure is unchanged**: 756 services, 627 Email identities, 427 OpenLP,
298 overlaps, 531 pending proposals, 158 finalised. Dimension outcomes are `corroboration_mismatch`
on song membership 269, song order 269 and song count 55, plus `uncorroborated_content_dimension`
across all three dimensions for 263 services.

All 650 staged Email revisions carry `unattended_content_finalization: false`, and all 29 services
previously described as "automatically converged" carry `payload_complete = 0`. They are *partial*
Email evidence, which {@see InboundEmailImportService::retainPlanEvidence()} deliberately keeps out
of the projection; they finalised on **OpenLP alone**. So cross-source corroboration finalised
**zero** services on this corpus, and the earlier 29 must not be cited as evidence that the HIR-D8
path works.

The cause is measured, not inferred. `ChurchServiceProjector::songDimensionValue()` compares
`song_canonical_key ?? normalized_title`, but neither `EmailSourceAdapter` nor `OpenLpSourceAdapter`
resolves songs against the catalogue, and `ChurchServiceAssertionNormalizer` only carries a
pre-declared key or a supplied `song_id` — so every historic assertion has a null key and the
comparison degrades to raw title equality between two independently authored sources. Measured over
the 298 overlaps: raw comparison agrees on song membership 0/298 and order 0/298; resolving both
sides through the catalogue would agree on 162/298 and 154/298. Count is 238/298 either way, since
it does not depend on titles. Of the song assertions, 104 of 1,315 Email titles fail to resolve
against 4 of 1,346 OpenLP titles — the Email side is the noisy one.

**This is an open maintainer decision, not a defect that was fixed here.** Until it is decided,
HIR-D8's corroboration path cannot finalise a service on this corpus and its acceptance criteria
cannot be met.

**Framing corrected 2026-08-21 against the code; the decision stays open.** The earlier reading —
that both placements change a hash-bound portable contract, and that the normalizer placement
offends invariants 3 and 10 — is wrong on both counts, and the two options are not symmetrical.

*The comparison-time placement does not touch any hash.* `project()` computes the projection hash
from `portableHashItems($items)` and `$serviceContent` only; `$conflicts` is assembled afterwards
and is not an input. `songDimensionValue()` has exactly two callers, both inside
`contentCorroborationConflicts()`, so resolving titles there changes the conflict set and nothing
else. `proposed_hash` is byte-identical before and after. What it does change is finalisation,
because {@see IngestChurchServiceSourceRevision} finalises only when the hash agrees *and* the
conflict set is empty — which is the whole 0/298 → 162/298 membership and 0/298 → 154/298 order
movement. The dependence should be declared by bumping `policyVersion`, which
{@see ChurchServiceConvergenceBundleImporter} already enforces on both import paths.

*The normalizer placement does change the hash* — via `strongIdentity()`, which turns a filled
`song_canonical_key` into the grouping key and therefore into `canonical_identity`, which
`portableHashItems()` retains. It also regroups assertions into items.

*The invariants cited were the wrong ones.* Invariant 3 binds parsing to an immutable byte
snapshot and invariant 10 governs source-key identity; neither concerns song identity. The
invariant actually engaged is **4** (source evidence is immutable and single-origin). Nor is
catalogue-dependent evidence forbidden in general: {@see CurrentEraChurchServiceReprojection}
already carries `song_canonical_key` on projected items. The distinction is provenance, not
presence — a current-era key records a link someone actually made at the time, whereas a historic
key would record a fuzzy match inferred now against a catalogue assembled later.

Three consequences follow if the inference is stored as evidence: stored assertions become
time-varying under catalogue edits though the source documents do not change (invariant 4);
already-approved proposals and exported bundles change hash and are then refused on import, so a
routine catalogue tidy-up becomes an invalidation event; and — weightiest — the catalogue was
itself built from these sources (IC3), so normalising both sides through it makes part of any
Email/OpenLP agreement an artifact of shared normalisation rather than two independent authors
recording the same service, which is precisely what HIR-D8 corroboration claims to prove. That
third consequence is contained but not eliminated at comparison time: routing can be re-derived,
the dependence is announced by the policy version, and no stored row or hash carries the inference
forward.

### 2.6 Carried and lapsed prior decisions

FR-D1–FR-D10, HIR-D1–HIR-D7 and the archived §5 decision table remain binding wherever their
object survives — notably FR-D2 (release via signed `historic-import:release-batch`, never as an
import side effect), FR-D4 (accuracy floors: precision ≥ 0.98 stops a batch, recall ≥ 0.85 routes
to review, identity/hash/supersession/visibility at 100%), FR-D8 (source custody duration and
return of the drive), FR-D9 (fail-closed manifest adjudication with written reasons), D10
(one-person project; no multi-human control, ever — also recorded in `AGENTS.md`), HIR-D1
(Spaces semantics: conditional create honoured, conditional delete ignored → never delete by
path), and HIR-D5/D6/D7 (investment discipline and Axis B, now amended by REV-D2). Decisions whose
object was the one-shot operation — the 480-minute ingress window, checkpoint window splits,
freeze/approval protection (FR-D5/D6/D7 in part) — **lapse with the machinery rather than being
relitigated**. HIR-D8 is decided and its projector implementation has landed (§2.5); the corrected,
catalogued corpus certificate remains IC3 work.

## 3. Safety model

### 3.1 Why incremental convergence is safe here

Three properties, all landed, bound the blast radius of any import mistake to "wrong but private,
fixable per service":

1. **Quarantine-until-release.** Imported sermons/talks carry
   `SermonPublicationState::Quarantined` and every public surface excludes them — archive pages,
   podcast/feeds, sitemap, direct asset delivery — via `app/Services/Public/SermonRepository.php`,
   `SitemapService.php`, `PreacherListCache.php`, `PublicChurchServiceArchiveService.php` and
   `PublicServiceContentEligibility.php`. Services are additionally dark behind the
   `church.services.public_from` era boundary (`config/church.php`). Publication happens only
   through `historic-import:release-batch` (`HistoricSermonPublicationService`, HIR7).
2. **Create-only object writes.** A durable ownership claim precedes any byte;
   writes are conditional creates; compensation never deletes by path (HIR-D1: Spaces ignores
   conditional delete); failures orphan and are reconciled by a human.
3. **Idempotent per-service classification.** Every apply classifies each target as
   `already_present` / `create` / `safe_enrichment` / `blocked_difference` / `conflict` before
   writing; re-running a round is safe by construction. `apply` is an operation, not a
   classification; manifest `included`/`excluded` is corpus curation, not target classification.

### 3.2 Hard invariants

Every one is enforced by landed code and named tests; none may be weakened by any IC package.

| # | Invariant | Enforcing seam |
|---|---|---|
| 1 | No public surface exposes quarantined or pre-boundary content (audience preservation, was F29) | The §3.1 read-side services and `SermonExposurePolicy`; release only via signed batch |
| 2 | Exact approved manifests — never globs, rescans or ad-hoc subsets — are the sole mutation authority (was F37/F48) | `OosCurationManifest`, `OpenLpCurationManifest`, `HistoricVideoCurationManifest`, plan-hash checks in every import command |
| 3 | Parsing, provenance and mutation bind to one immutable byte snapshot whose hash equals the approved manifest hash (was F49/F50, B15) | `OosArchiveParseCacheBinding` (HIR2), snapshot checks in the OoS/OpenLP importers |
| 4 | Source evidence is immutable and single-origin; supersession is explicit lineage; source silence never removes another source's occurrence (was F30, B3, B5) | `IngestChurchServiceSourceRevision`, source adapters, `ChurchServiceProjector` |
| 5 | Manual final authority is never silently overwritten by machine evidence (was F40) | Projector/review-state services |
| 6 | Object storage is create-only under a prior durable claim; no DB transaction spans object I/O; cleanup deletes only exact owned receipts or not at all (was F45-objects, HIR7) | `HistoricSermonPublicationService`, `HistoricReleaseObjectStore` implementations |
| 7 | Historic bulk work emits no external notification, model/domain event or after-commit authoritative side effect; ordinary weekly processing still alerts (was F51/F52) | `ProcessingNotificationRouter` historic containment, convergence event audit |
| 8 | Every Scripture passage settles as linked-or-approved-absent before export/apply (was F59, HIR3) | `HistoricScripturePassageRequirements`, `EnrichHistoricScripturePassagesCommand` |
| 9 | Portable artifacts carry no raw email body, secret, local path, user ID or private note; private reports are `0700`/`0600` (was F33/F34) | `HistoricImportArtifactRedactor`/`ArtifactWriter`, bundle exporters |
| 10 | One source-key identity across PHP, bundles and MySQL; provenance recorded even for unchanged normalised content (was F54/F55) | Shared key value objects and their parity tests |
| 11 | Per-service apply runs under lock against current state; stale plans rebind or refuse (was F41-lock) | `ConvergeHistoricChurchService` |
| 12 | The date/identity manifest-corroboration gate on email extraction stays fail-closed (8/8 recall measured) | `OosArchiveEvaluator` gate reasons |
| 13 | Approved video bytes are hash-verified immediately before dispatch and outputs hash-verified after (was F31) | `HistoricVideoImporter`, `HistoricVideoCurationManifest::verifiedPath()` |
| 14 | Same-day special-service identity collisions fail closed; curated occasion/title facts are carried (was F44) | Identity resolver + manifest curation fields |

The permanent regression canary (`HistoricNormalOutputContractTest` and the WP0/B-series red-test
suite) is retained forever as the portable-processing contract, exactly as the archived plan's
WP10 already required.

### 3.3 Round gates

Each lane round passes three gates; there is no other gate ladder.

- **RG-A (staging):** the lane's corpus stages cleanly on a certified-clean `crockenhill_rehearsal`
  (`historic-import:provision-rehearsal-database`), and the audit report (§7.3) reconciles:
  exact membership against the approved manifest (was F53), zero unexplained identities (F1 rule),
  held residue enumerated by reason, proposal census run (§9). Focused tests, Pint, PHPStan green.
  The manifest reconciliation is produced, not configured: run
  `oos:generate-corpus-expectation --manifest=<approved manifest>` and feed the artifact to
  `services:proposal-census --expectation=` (or `church.historic_corpus.expectation`). Setting
  `HISTORIC_CORPUS_EXPECTED_SERVICES` by hand no longer certifies anything and is ignored while an
  expectation is present.
- **RG-B (production apply):** approved manifest + plan hash presented; pre-round verified
  database backup taken; `HistoricImportProductionGuard` satisfied for the named round (IC2
  re-scopes it from one-shot GO to per-round approval); apply executes only §3.2-compliant writes;
  post-round audit report generated and reviewed.
- **RG-C (release):** §8 — era accuracy evidence at the FR-D4 floors, spot-check against the truth
  set, editorial QA, acceptance journeys; signed release batch.

**Historic closeout** (supersedes G9 wherever other plans reference it): every lane's audit report
at 100% disposed membership, final releases done, drive returned per FR-D8. It triggers IC8
retirement, and is when other plans' "post-G9" work (architecture AM8–AM10, simplification R8
deletions) unblocks.

## 4. Finding and gate dispositions

Superseded documents keep the finding text; this table is the binding disposition. "Kept" items
appear in §3.2; "report" items live in the §7.3 audit report; "lapsed" items impose no further
work or evidence.

| Disposition | Findings |
|---|---|
| **Kept as hard invariant** | F29, F30, F31, F33, F34, F37, F40, F41 (lock half), F44, F48, F49, F50, F51, F52, F54, F55, F59; HIR1–HIR3, HIR6–HIR7 code |
| **Kept as open work** | F60 (IC6), current-era back-fill (IC4), video manifest population (IC5), OpenLP v2 curation fields (IC5), HIR-D8 implementation (IC3) |
| **Reframed as report** | F32 (per-source accounting; exit contract changes in IC2), F53 (exact membership), F57 (round audit completeness), per-round cost/throughput accounting (was F58's measurement half) |
| **Closed with evidence** | F1 completeness (Email lane, 2026-08-16 — see below), F2, F3, F4, F42, F43, F46's guard code, F61, F62, F63, F64, F65, F66; B1–B21 (all repaired; red tests retained); HIR0–HIR7 landed |
| **Lapsed with the one-shot model** | F35 (journal-resume proof), F36 (forensic two-copy custody ceremony — read-only original, one verified working copy and hash inventory remain required practice), F38 (checkpoint *exactness* gating — checkpoints stay as tooling), F39 (fingerprint *binding* — fingerprints stay as recorded provenance), F45 (timed restore/RPO/RTO drills — verified backups remain mandatory), F46 (freeze/watchboard/change-control window), F47 (forced-crash recovery proof), F56 (freeze-sweep semantics — ingress lock tooling retained for optional brief pauses), F58 (window budget); HIR4/HIR5 evidence obligations; HIR8 steps 1, 2, 4, 6–11; safety invariants 4, 5 and 9 of the archived safety plan |

**F1 completeness, closed 2026-08-16 (Email lane).** The census could not fail for an approved
entry that never staged: `services:generate-corpus-membership` built the expected membership by
querying `church_service_source_records`, so a held entry was absent from both sides of the
comparison, and `church.historic_corpus.expected_services` was a scalar an operator typed — on the
2026-08-15 round, from what that round had just staged. `oos:generate-corpus-expectation` now
derives the expectation from the approved manifest alone (`OosApprovedCorpus`), which is possible
because every field a staged revision carries is already a function of the manifest: `batch_hash`
is the curation plan hash, `input_hash` is the entry's approved `sha256`, and the source key is
`OosCurationEntryFactory::sourceKey()`. `ChurchServiceCorpusExpectation` reconciles it against the
staged corpus and the census gate refuses without it. Extra identities are admitted only where an
approved entry's origin explains them on that entry's approved date — the hash-covered
`service_beyond_manifest` rule — because one email legitimately stages both that Sunday's orders,
which is exactly why the scalar comparison was the wrong instrument and is now suppressed whenever
an expectation is present. Intentional holds carry an operator's written reason through
`--accepted-holds`, on FR-D9's fail-closed-with-reasons pattern. **The OpenLP lane still has no
producer**; populating the v2 curation fields (IC5 step 2) is what gives it one.

G0–G9 as a ladder is retired; their still-live content maps to RG-A/RG-B/RG-C and §3.2. The
archived per-gate audit table's open items are absorbed as follows: G1/PR5's manifest-field
population → IC5; G2 census/completeness → RG-A; G3 round trips → closed; G5 rehearsal → RG-A per
lane; G7/G8 window approvals → RG-B per round; G9 → historic closeout.

## 5. Corpus facts and evidence regimes

Locations: Email at `storage/scratch/oos` + `oos-verbatim` (local; authority batch
`oos-curated-2026-08-16`: 555 entries, 552 included, 3 excluded, **538 identities**. The 2026-08-16
inbox sweep folded in 20 further verbatim files — 18 included as morning full orders and partials,
2 excluded — superseding `oos-curated-2026-08-12`'s 535/534/**521**; every figure measured against
521 below predates it); OpenLP archives local at
`storage/scratch/ServiceRecords` (427 included entries, staged); video on the Sonnics drive,
`Services/` root only (1.0 TB, 506 recordings, 462 identities; `_Rejected/` holds real service
*segments*, not duplicates — 55 morning services degrade to `short_partial` without them, accepted
scope cost); hymn workbook crosstabs 2004–2018 + 2023 (5,759 known-service occurrences over 1,306
identities; 888 identities have no other source; date-only lane implemented and quarantined).

**Eras are defined by which sources survive** (measured 2026-08-14; denominators 193-vs-220 and
**538**-vs-662 still need reconciling before release decisions — IC5 prep; the Email denominator was
521 when this was written and moved on 2026-08-16):

| Era | Surviving sources | Accuracy method |
|---|---|---|
| 2004–2008 | Hymn date-only | Song membership by date; no service split |
| 2009–2013 | Hymn only | Song membership; no cross-check exists |
| 2014–2018 | Email + Hymn | Cross-source song membership |
| 2019 → 2020-03-21 | Email only | Hand-verified truth set — nothing else exists (zero video: recording starts 2020-03-22) |
| 2020-03-22 → 2022 | Email + video (OpenLP from 2021) | Video corroboration, **graded by recording completeness** (much of 2020 is sermon-only and cannot corroborate song membership); evenings weak until 2022 and never inventoried by the drive's morning-only CSV |
| 2023–2026 | Email + OpenLP + Hymn | Count, sequence and membership all checkable |

Cross-source shape: **375 of 521 email identities (72%) carry at least one corroborating source**;
the three-source union is ~1,594 service identities. (Measured 2026-08-14 against the then-current
521-identity corpus. The denominator is now 538 and the 17 added identities have not been tested for
corroboration, so the 72% is stale in the optimistic direction — re-measure before it informs a
release decision.) OpenLP is the only source proving item *order/count*; the hymn lane proves song
*membership* only — any corroboration rule must say which dimension it relies on.

Email lane baseline (archive-v12, fresh parse, report SHA-256 `acf6b18a…36a55`; measured against the
521-identity corpus and **not** restated for the 538 one — the report hash pins these figures to that
run): 370/534 sources held; 157/521 identities staged; ~95% of sources take a corrective second call
(~1,100 model calls per full re-run — budget accordingly, and do not trim the retry: it produces the
consensus that lets 0.75–0.89 plans finalise); self-reported confidence is weakly calibrated (78.7%
identity-exactness in the 0.90–1.00 band); the genuinely manual residue is ~14 items (6 identity
disagreements, 8 date corrections).

## 6. Work packages

Ordered; **IC1 and IC2 landed on 2026-08-15** (`e5a81d191`, `aeeed8332`, with follow-up
`a4be644fd` closing the review gaps found in both). IC3's shared semantic-parser handoff is
complete. The corrected Email rehearsal has now run without fresh model calls; its next slice is
the catalogued, portable-audit-complete RG-A correction described under IC1, not another semantic
evaluation. IC4 runs any time, and IC5 starts on the §2.4 trigger.

### IC1 — Email evidence-tier import (implements REV-D2) — **IMPLEMENTED 2026-08-15**

**Behaviour contract.** For an archive or weekly email plan with disposition
`ReviewRequired` (`OosEmailParseDisposition`) whose identity is manifest-corroborated (archive) or
unambiguous (weekly): import it as source evidence — source record, assertions, service create or
merge via the existing `importPlan()`/`mergeOrCreatePlan()` path — leaving the service's review
state `NotReviewed` and the plan marked unfinalised. Plans holding for `MissingIdentity`,
identity-gate reasons, or `InvalidExtraction` with *content* reasons (`ContentInvalid`,
`items_out_of_source_order` genuine cases) still take `HeldForReview`/`InvalidExtraction` exactly
as today. Nothing about `AutoImportable` changes.

**Seams.** `OosArchiveEvaluator` (which plans are offered to import), `InboundEmailImportService`
(`importPlan`, `planImportMetadata` — record the evidence tier and hold reasons on the plan
metadata), `ImportOosArchiveCommand` (dispositions/report), `ProcessInboundOosEmail` (weekly
parity), release eligibility in `HistoricSermonReleaseAuthorisation`/release command (exclude
services with unfinalised email evidence), review inbox filters (surface "imported, unreviewed"
without flooding: they enter the §9 census, not the weekly attention queue).

**Red tests first** (`ImportOosArchiveCommandTest`, `InboundEmailImportServiceTest`,
`OosArchiveEvaluatorTest`): a corroborated `ReviewRequired` plan creates evidence + service and is
not finalised and not release-eligible; a date-gate-held plan still imports nothing; a content-
invalid plan still imports nothing; convergence with an agreeing OpenLP archive finalises without
human review (B11 behaviour); a second import round is an exact no-op per service.

**Acceptance.** A staging round (RG-A) shows held residue ≈ the identity + content-invalid
families; staged identity coverage from 157 toward the 393-of-394 the held backlog covers; the 82
already-converged Email/OpenLP services unchanged (`already_present`). Then hand the operator the
residue the round actually measures.

**Status 2026-08-15:** the behaviour, seams and red tests are implemented and merged. The RG-A
staging round itself has **not** been run against the semantic parser, so this package's acceptance
evidence is outstanding, not its code.

**RG0A status 2026-08-21: passed.** The semantic Email corpus was evaluated, its measured
identity/date residue was adjudicated, and the cache-backed replay reduced the honest residue to 19
accepted partial-evidence holds. This is the completed analysis and authority checkpoint, not a
clean-staging RG-A certificate. The corrected semantic cache and its evidence were subsequently
recovered, so the claim that a clean restage requires a new 554-source model evaluation is
withdrawn; the 19-hold figure is also superseded by the corrected rehearsal below. Do not describe
RG-A itself as passed or use RG0A to authorise production mutation.

**Corrected recovered rehearsal 2026-08-21: ran; RG-A remains open.** MySQL binlog recovery
restored all 554 `archive-v13:semantic-annotations-v1` cache entries. A fail-closed cache-only
evaluation selected all 554 approved sources without a model call. Its clean import report (SHA-256
`40d7c046fbfa3c0ff5d28c2011f4d3a44c253ec0841560ed034065e81f46177d`) records 438 `created`,
75 `evidence_retained`, 41 `held_for_review` and zero failures. Its overlapping held-reason counts
are: low confidence 41, source gate 24, no items 23, missing identity 11, unknown content scope 10
and content invalid 2. The portable assertion bundle is retained at
`storage/scratch/semantic-rg-a-recovered-assertions-20260821.json` (SHA-256
`7189a6b5a1fb6e06463626dd4b0203f0295cc23197d7f3336d3ee0025ab61061`); the recovered cache-only
report is SHA-256 `477adb042e3f43d7babc79f4fc6ff84cb31eeca680ed10ace2352a46c31cd4f1`.

The Email-lane census after staging OpenLP is
`storage/scratch/semantic-rg-a-email-census-after-openlp-20260821.json` (SHA-256
`9ae87fd32e0350bfe9f06c8a7ec70e3cbe23d719e87ab04b7ca3ee8c076b825e`). It contains 248
unclassified classes covering 531 pending proposals. The gate blockers are `membership_mismatch`
(`source_item_projection_stale`) and `expectation_mismatch`: 30 approved sources / 29 manifest
identities did not stage, while 116 beyond-manifest identities are explained and zero are
unexplained. Only 158 of 756 staged services carry projection policy v3 and 531 proposal services
are stale for census purposes. These are measured repair inputs, not operator decisions and not a
reason to buy another whole-corpus model run.

**Catalogued RG-A replay 2026-08-21: still open, with the blockers now attributed.** The recovered
evidence was replayed on a fresh `crockenhill_rehearsal_catalogued`, provisioned and certified
clean, then seeded with the song catalogue before staging. Zero fresh model calls: the run is
`--cache-only`, which refuses unless every selected source has a reusable raw parse cache, and all
554 did. Reproducible seed and artifacts:

| Artifact | SHA-256 |
|---|---|
| Song catalogue source `storage/scratch/songs.sqlite` (`service-tracking:sync-songs`) | `d02a274df8a70a2e8f048061fd7890b592d932c960c617c01f173cbb7023a791` |
| Seeded reference tables dump (1,159 songs, 594 authors, 2 songbooks, 1,386 author links, 754 book links) | `791ddab2da5060aee6a7ecb830de6687db2877523c11b438cc04ba4608dda5d9` |
| Import report `rga-catalogued-import-20260821.json` | `009e8ca4a9a824ba4825313f9dc3e3fa561b879cd5f0a44fc849bb094ee64358` |
| OpenLP dry run `rga-catalogued-openlp-dryrun-20260821.json` (plan hash `7acb266f…`, unchanged) | `041d5b12ef4979a33d4846182bc8db08c2296de61d31368aa2a082e7ff9bca27` |
| Email membership `rga-catalogued-email-membership-20260821.json` (membership hash `d8abdc92…`, byte-identical to the recovered run) | `07c577269e7b13a0bfc110b02e40173eb09a2e309628ba9df247e987238193c3` |
| OpenLP membership `rga-catalogued-openlp-membership-20260821.json` | `7eeff761d1b9002e157c3dda18249f97b34a4016bed4f826bd1dd476c4230523` |
| Corpus expectation `rga-catalogued-expectation-20260821.json` (expectation hash `d9702172…`) | `3742a1d8311d4301f85239707d68c4de49b9eb7737532538ba5df0b0e0ea9a4e` |
| Proposal census `rga-catalogued-census-20260821.json` | `3674a53a5053acd8dc1b420e034f55ea693bb381d95f58051b25f4a4c751fa1e` |
| Portable structural holds `rga-catalogued-portable-structural-holds-20260821.json` | `c953c40c7418cbc69904085c2fe0687060c221a7ce2f630b5f23896495c4e0db` |
| Staged rehearsal dump `rehearsal-catalogued-staged-2026-08-21.sql` | `598caa9b0fa141db95389b901c89b411b6e4dc459e79d63ddd4b5afdf3328af4` |

Every private artifact is `0600`. The recovered inputs and the recovered rehearsal database are
retained untouched; the diagnostic database is dumped at
`rehearsal-recovered-diagnostic-2026-08-21.sql` (`b4fbf829f9f01f4dfb6be41cf376e95edd31b0cf88e91a661d3a91f029e6ac30`).

**What the catalogue changed.** Song-title resolution moves from 0 to 2,378 of 2,639 items (90.1%),
and plans with every song resolved from 0 to 472 of 657. Dispositions become 438 `created`,
74 `evidence_retained`, 16 `merged`, 26 `held_for_review`, zero failures; hold-reason categories are
low confidence 26, source gate 24, no items 23, missing identity 11, unknown content scope 10,
content invalid 2. OpenLP staged all 427 again (129 created, 298 updated, 0 failures).

**What the catalogue did not change: anything the gate measures.** 756 services, 627 Email
identities, 427 OpenLP, 298 overlaps, 531 pending proposals, 158 finalised — identical to the
recovered run, for the reason recorded in §2.5. The census gate still **fails** on
`membership_mismatch` (`source_item_projection_stale`) and `expectation_mismatch`, with 248
unclassified classes over 531 proposals. **RG-A is not passed and HIR-D8 is not accepted.**

**The four accounting items, resolved.**

1. *The 67 staged Email identities with no pending proposal are correct, not a gap.* Every one has
   `payload_complete = 0`: `retainPlanEvidence()` ingests them with `project: false`, so they hold
   no canonical items and stage no proposal by design, and `ChurchServiceCorpusMembership` already
   exempts them from `source_item_projection_stale`. No change is required and none was made.
2. *The 30 unstaged approved sources / 29 identities are honest hold residue.* They resolve to 26
   `held_for_review` entries plus four whose manifest-expected plan key held while a sibling plan
   imported. `oos:generate-corpus-expectation --accepted-holds=` exists precisely for these and
   takes an operator ruling per `{item_key, reason}`. **No accepted hold was invented**, so
   `accepted_holds` stays 0 and `expectation_mismatch` stays a blocker. This is the §10 operator
   item, now reduced from 41 to 26 held sources.
3. *`incomplete_projection_audit` on every pending proposal was a defect, now fixed.*
   `ChurchServiceProjector::hasCompleteAudit()` returns false on *any* conflict, and
   `IngestChurchServiceSourceRevision::stagingReasons()` called it and then also appended
   `$projection->conflicts` — so the reason appeared on 100% of the 531 proposals while naming
   nothing an operator could act on, and masked the class each proposal belonged to.
   `hasCompleteFieldDecisionAudit()` splits the explanation half out; `hasCompleteAudit()` and its
   bundle callers are unchanged. The catalogued census carries the reason on **zero** proposals.
4. *`source_item_projection_stale` is not an independent failure.* `projection_policy_version` is
   written only by `ChurchServiceProjectionPersister::apply()`, which the staging branch skips, so
   stale services and pending proposals are the same 531 set by construction. It clears when the
   proposals clear — which, per §2.5, currently requires the corroboration decision.

**Correctness fixes made under the HIR-D5/D7 exception**, each with a red test first:

- **Create/merge classification asymmetry** (`InboundEmailImportService`). `mergeOrCreatePlan()`
  returns `Created` for a new service whether or not the projector staged a proposal, but the merge
  arm returned `HeldForReview` for exactly that state — so an entry whose source revision *was*
  written reported as unimported and stopped later entries superseding it. The projector arm now
  reports `Merged`; the compatibility-merge arm keeps its older meaning, because it stages a
  structure merge without ingesting a projected revision. This is what the two focused failures at
  `ImportOosArchiveCommandTest.php` 1179 and 1344 were reporting: **the expectations were right and
  the implementation was wrong.** Holds fall 41 → 26 with no change to what staged.
- **Human approval was treated as unattended evidence** (`EmailSourceAdapter`). The adapter set
  `unattended_content_finalization` from `$plan->isAutoImportable()` alone, so an admin-approved
  import entered the evidence tier and §2.5's dimension corroboration reopened a service a person
  had just approved — invariant 5 read backwards. The adapter now takes `reviewedByPerson`.
- **A partial review closed itself** (`ChurchServiceProjectionPersister`, `IngestChurchServiceSourceRevision`).
  The persister retracted `needs_review` whenever an automatic projection landed, and the ingest
  action marked *every* pending proposal stale before projecting. Together they dropped proposals a
  reviewer had deliberately left pending out of the attention inbox. The retraction now requires no
  pending proposal to remain, and the stale sweep no longer applies to a `Manual` revision, which
  `ReviewChurchServiceEvidence` owns explicitly.

These three restored six pre-existing failures in `EvidenceReviewTest`,
`ApproveInboundEmailImportTest`, `ChurchServiceProposalRuleServiceTest`,
`ChurchServiceConvergenceBundleRoundTripTest` and `ChurchServiceConvergenceBundleImporterTest`. None
of them can affect the staged corpus: it holds 0 Manual source records, 0 reviewed imports and 0
review sessions, and all 650 Email revisions carry `unattended_content_finalization: false`.

**The portable bundle's structural re-validation is incomplete — open.** Replaying
`semantic-rg-a-recovered-assertions-20260821.json` through `preflightPortable()` refuses **403** of
554 entries, not 24; the enumerated, reasoned holds are in the artifact above and fail-closed
`apply()` was left exactly as it is. The dominant reason (2,121 occurrences) is "Source line N was
not classified as evidence, an item, or ignored context", and the cause is that the bundle never
ships the ignored-line half of the provenance: `OosArchiveAssertionBundle::export()`'s `Arr::only()`
allowlist omits `ignored_lines`, `structuralReasons()` therefore builds an
`OosEmailItemExtractionResult` with none, and `processing_metadata.parsing` does not persist them in
the first place. Closing it means persisting ignored lines, bumping the bundle format and
regenerating the bundle and all its hashes — a change to a hash-bound portable contract, so it was
**not** made here. The 24 figure in the recovered baseline is not reproducible and should be treated
as withdrawn pending that work.

**Superseded numbers (2026-08-21).** The `~20 identity + ~33 content-invalid` shape and the
`~14 manual adjudications` handed to the operator are **legacy v12 estimates and must not be carried
forward as facts**. They were produced by the deleted legacy extractor. The semantic parser routes
differently — the 38-source truth corpus scores 36 `review_required`, 2 `invalid_extraction` and 0
`auto_importable`, against the legacy baseline's 4/31/3 — and the semantic cache namespace prevents
any legacy raw-cache reuse. The recovered corrected semantic run above is now the operational
baseline. Reuse its hash-bound cache/assertion artifacts; do not re-run the whole corpus merely to
reproduce evidence that now exists again.

### IC2 — Incremental apply semantics — **IMPLEMENTED 2026-08-15**

Re-scope `ConvergeHistoricChurchService`'s batch admission from "whole approved corpus applicable
or refuse" to "apply every applicable service; report the rest" — per-service lock, classification
and transaction unchanged. Change `ImportOosArchiveCommand`/converge-command exit contracts (was
F32): exit non-zero only for processing *errors*; held/pending residue is reported state in the
audit report. Re-scope `HistoricImportProductionGuard` from one-shot GO to per-round approval
(named round operation + manifest/plan hashes + backup receipt). Keep `HistoricImportJournal`/
checkpoint tooling for long passes; drop their exactness assertions from acceptance.

### IC3 — Item-level ground truth (queued parser plan item 0)

Carried by `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md` ("Parsing
improvement plan, queued 2026-08-14"). Seed the rehearsal song catalogue first (2,580 extracted song
items become item-level checks). Ground truth is derived from corroboration before it is hand-built;
hand-verify only what corroboration cannot reach. Its output decides HIR-D8 (§10) and calibrates
§8's era accuracy figures.

**Status 2026-08-16.** Item 0 is complete. The census that item 2 made a precondition has been run
(`storage/scratch/archive-v13-attribution-20260816.json`, 554 entries, replayed from cache with zero
model calls) and the producing defects behind it fixed (`9f55f13d2`). Read "Revision 2026-08-16" in
that report before starting anything here — it withdraws item 2's premise and adds items 5–7.

**Authority refresh completed 2026-08-19.** The August hymn snapshot is now authoritative for 2025
and 2026 (§6 IC6), so the earlier 2026-08-16 ground-truth artifacts are superseded. The refreshed
item truth is `storage/scratch/item-ground-truth-2026-08-19-authority-refreshed.json` (canonical artifact hash
`8c87a18889e9ed5dc97088a886113d0c14842d02dc6ef55eb59e69eb72284645`), generated against the
recovered 606-identity / 5,661-active-item rehearsal corpus and the 16 August workbook. This closes
the IC3 authority-refresh prerequisite for later HIR-D8 measurement; final-corpus regeneration and
hymn mutation still wait for IC6.

**Model-evaluation closure 2026-08-18.** The archived
[nano/Luna non-inferiority evaluation](../archived-plans/OOS-PARSER-MODEL-EVALUATION-2026-08-17.md)
ran both 554-source arms and closed without a model verdict. Its corrected same-source diagnostic
found material source-exact self-disagreement in both `effort=none` arms (nano 24/30; Luna 19/30),
with item structure alone above the declared 10% threshold in both (21/30; 8/30). Nano remains
configured only as the unchanged status quo; Luna was neither adopted nor rejected. Do not label the
536 model-discordant sources or run full replicates for that closed comparison. IC3's ground-truth
and release-accuracy obligations remain unchanged.

**Permanent parser handoff completed 2026-08-21.** The
[archived OoS email parser redesign](../archived-plans/ORDER-OF-SERVICE-EMAIL-PARSER-REDESIGN-2026-08-19.md)
delivered the executable parser sequence: lossless source annotation, deterministic compilation,
narrow repair, objective evaluation and shared weekly/historic cutover. Delivery 7 acceptance
passed, the semantic parser is the sole path, and the legacy whole-document path is deleted; its
remaining evaluation surfaces have IC8 retirement ownership. The report's queued implementation
order `5, 1, 7, 3` remains evidence, not an executable backlog. IC3 retains the authoritative item
truth, HIR-D8 corroboration and all historic staging/round evidence. **HIR-D8's current projector
implementation is complete**; its accepted Email RG-A corpus certificate remains. The earlier ~14
identity/date adjudications is a superseded legacy-v12 estimate. The recovered 2026-08-21 rehearsal
now supplies the first-pass measurement (§6 IC1), but not the
catalogued, projection-complete certificate required to accept HIR-D8. The parser cannot change
evidence admission, finalisation or publication policy.

### IC4 — Current-era evidence back-fill (drive-free; any time)

Production holds 3 services with 32 canonical items and zero source records (measured 2026-08-09;
decision: back-fill, never manufacture evidence). Recover each service's authoritative source
material, ingest through the normal source-revision path with provenance and hash, then run
`service-tracking:reproject-current-era` over the three-service corpus and audit item-level.
The B13 false-acceptance reversal semantics are already implemented (PR16).

### IC5 — Video and OpenLP completion (starts on the §2.4 trigger)

1. Reconcile the era-table denominators (§5) so release decisions use one accounting.
2. Populate the OpenLP v2 curation fields (`item_key`, `source_kind`, `parse_decision`,
   `concatenation_decision`, `expected_item_count`, `decided_by`/`decision_rule_version`) against
   the authoritative local corpus and re-approve — the drive-mount requirement lapsed when the
   corpus was found local at `storage/scratch/ServiceRecords`; verify no symlink-only paths remain.
3. Video worksheet → adjudication → freeze: `historic-import:draft-video-curation` (cheap,
   repeatable), operator adjudication with written include/exclude reasons (FR-D9), then
   `historic-import:capture-video-curation` (hashes once at freeze; note
   `HistoricVideoCurationManifest::verifiedPath()` re-hashes the 1.0 TB corpus — ~3 h at
   87.7 MB/s — on every `plan()` call; schedule around it). The manifest must carry the *graded*
   corroboration field (recording completeness, not presence) and inventory `Evening/` itself.
   WebM YouTube backups need packet-count duration recovery (no header duration).
4. Calibration slice under the definitive local runtime (short/long, codec families, concat/
   re-encode, era spread), publishing p50/p95 throughput and a cost/capacity forecast the
   maintainer accepts — then the bulk pass in 25-recording/12-hour checkpoints (tooling, not
   gates), through `sermons:import-historic-videos` into the isolated staging context.
5. Stage results, export Bundle A per era as eras complete, and include per-era accuracy (with its
   evidence basis: derived-from-corroboration or hand-verified) in the round reports.

### IC6 — Hymn lane

The hymn workbook has two deliberately different roles. Its human-curated `Known Usage` data is
used early, before this package, as IC3 corroboration for Email song membership and HIR-D8
finalisation; it must not wait for the hymn mutation lane. What waits until after Email/OpenLP/video
convergence is applying hymn occurrences to the service graph: regenerate and hash-bind the hymn
reconciliation against the exact converged corpus (F60), disposition all 5,759 known-service rows including
cross-source duplicates, then apply through the landed operation-owned controls (F61) with the
proven no-op/reconciliation semantics (F62). The date-only lane stays quarantined until its signed
release. The workbook's `a`/`p`/`●` markers carry the service attribution; the contract tolerates
the known phantom row.

**New source snapshot (received 2026-08-16).**
`storage/scratch/Hymn database @ 16.08.2026.xlsx` (SHA-256
`0a6ac2043138c2e0abbf3fdcf314220d2d567a0ec812c3504d49e300213296c6`) contains the `2025` and
`2026` year sheets and supersedes `Hymn database @ 15.03.2026.xlsx` as their authority.
`HistoricHymnReconciliation::SheetAuthority` and its regression tests name the August snapshot so
all further IC3 corroboration uses it; earlier supplied snapshots remain hash-bound, superseded
sources. After the preceding convergence work, regenerate the apply artifact against that exact
rehearsal corpus and review its anomalies, duplicates, unresolved titles and fuzzy candidates;
do not carry forward the old `1,941 / 1,867 / 74` dry-run contract unless the new result reproduces
it and it is approved again. F60 must also close the current artifact gap: the retained builder
emits JSON, whereas `service-tracking:import-historic-song-usage-reports` consumes the derived XLSX
date-only lane and does not apply the JSON's known-service rows. Define and test the bound
reconciliation-to-apply artifact before either lane is imported through a new operation manifest.

### IC7 — Production rounds

The §7 procedure, per lane per round: Email+OpenLP evidence first (drive-free), then video/media
Bundle A rounds per era, then hymn. Each round is RG-B-gated and ends with the audit report. No
round publishes anything.

### IC8 — Retirement (at historic closeout)

As the archived WP10: record every one-shot's deletion trigger; remove spent commands, temporary
compatibility writers/readers and the historic-only guard/ledger surface in R8/R12 ownership
order; contract schema in a later release than reader removal; retain the exact auditors, portable
contract tests, §3.2 canaries and public read-side tests permanently; archive this plan and
reconcile the plans index. The retirement surface is *larger* than WP10 anticipated — it now also
includes the ingress-freeze tooling and one-shot ceremony left unused by REV-D1 — and that is the
point.

## 7. Production round procedure

Replaces WP9 and the archived runbook (`docs/operations/r8-data-convergence-runbook.md` carries a
supersession banner; do not execute it).

### 7.1 Preconditions (RG-B)

- The lane's RG-A staging evidence exists for the same parser/code version.
- Approved manifest + plan hash for exactly this round's corpus subset.
- Code deployed; four quality checks green on that commit.
- Pre-round production database backup taken and verified restorable (the spatie backup plus an
  explicit round dump; verification = a test restore of the dump, not a size check).
- The FR-D7 log-only operation record exists (architecture plan AM3 Delivery 1 — rotating logs
  retained long enough to cover a round and its review). FR-D7 accepted logs *instead of* Sentry;
  what it accepted must actually be running before the first round.
- `HistoricImportProductionGuard` presented with the named round approval.
- Optional: brief `import:ingress block` if the round runs at a time weekly work could interleave
  (Sundays); otherwise unnecessary — there is no freeze requirement.

### 7.2 Apply

Run the lane's apply command with the round's operation id. Per-service transaction, lock and
classification as landed. First hard error stops the round (residue is fine; errors are not);
fix forward and re-run — re-runs classify completed services `already_present`.

### 7.3 Audit report (per round, replaces exact closeout)

One command/report reconciling, against the approved manifests: per-source membership and
disposition (created / merged / evidence_retained / held-by-reason / failed), identity accounting
under the F1 rule (zero unexplained), asset receipts vs claims (orphans enumerated), Scripture
settlement, notification/dispatch silence, and cost/duration. Committed to the private batch
ledger. Drives the completeness number; blocks nothing except by the maintainer choosing to act
on it. Re-run on the health-check schedule to detect drift (as the archived plan already chose).

## 8. Release policy

1. **Unit: era slice** (§5 table). Services release by moving `church.services.public_from`
   backwards and/or releasing the era's sermon batch via `historic-import:release-batch`; media
   below the publication threshold is withheld from listing via the existing exposure inputs
   (`SermonVideoQualityStatus`, `SermonVideoVisibilityOverride` in `SermonExposurePolicy`) while
   the service page stays complete.
2. **Evidence per era before release (RG-C):** accuracy at the FR-D4 floors on IC3's measure,
   stated with its evidence basis; the 2019→2020-03-21 sub-era is hand-verified-or-unpublished by
   construction. Spot-check the acceptance journeys: browse year/date/service/occasion; find by
   preacher/scripture/song; planned vs observed items distinguished honestly; no misleading auth
   bounce; honest 404/private responses; stable URLs; feeds/sitemap/caches correct; no
   notification/analytics flood. Editorial QA for titles/slugs/occasions/unknown speakers.
3. **Every release is a controlled batch** with owner and observation, never an import side
   effect (carried §F.6). Corrections and unpublishing use the ordinary application tools without
   deleting provenance.
4. **Deferred policy, carried unresolved:** editorial, copyright/CCLI, consent, personal-data and
   safeguarding policy for publishing a decade of services must be decided by the maintainer/church
   before large releases. Nothing in this plan clears it.

## 9. Review-load design (carried)

The archived §9.4 automate-first loop is unchanged and now serves staging rounds: run the proposal
census per round (`services:proposal-census --gate` semantics with corpus-completeness evidence);
classify proposal *classes*; automate a class away where a deterministic rule exists (rule-level
dispositions with `decision_rule_version`); hand humans only irreducible ambiguity. Evidence-tier
imports (IC1) enter the census, not the weekly attention inbox. The weekly inbox keeps its
narrower `needs_review` semantics on purpose.

## 10. Open decisions and operator inputs

| Item | Blocks | State |
|---|---|---|
| **Song identity for HIR-D8 corroboration**: resolve song titles through the catalogue in `ChurchServiceProjector::songDimensionValue()` at comparison time (no hash changes; conflict set and therefore finalisation changes; declare via `policyVersion`) or in `ChurchServiceAssertionNormalizer` (stored evidence carries an inference made now — invariant **4**, and `proposed_hash` changes via `strongIdentity()`) | All of HIR-D8 — corroboration finalised **zero** services on the catalogued corpus | Maintainer. Measured 2026-08-21: raw titles agree on membership 0/298 and order 0/298; catalogue-resolved would agree on 162/298 and 154/298. Framing corrected 2026-08-21 — the options are **not** symmetrical and the invariant cited earlier (3/10) was wrong; see §2.5 |
| **Portable bundle ignored-line provenance**: persist `ignored_lines`, bump the bundle format, regenerate the bundle and its hashes | Portable apply — `preflightPortable()` refuses 403 of 554 entries | Maintainer; enumerated in `rga-catalogued-portable-structural-holds-20260821.json` (§6 IC1). The recovered "24 refusals" figure is withdrawn |
| Disposition the 26 held semantic Email sources, or rule on them as `--accepted-holds`; reason counts overlap, so do not turn their sum into a workload | Email-lane settlement / F1 reporting / `expectation_mismatch` | Operator. Prior ~14, RG0A's 19 and the recovered run's 41 are superseded |
| Source recovery for the 3 unevidenced current-era services | IC4 | Operator |
| Video curation worksheet adjudication + freeze | IC5 bulk pass | Operator, on the §2.4 trigger |
| Era release sign-offs and the §8.4 policy decision | Each RG-C release | Maintainer/church |
| Denominator reconciliation (193/220, 538/662) | Era release accounting | IC5 prep |

## 11. Permanent vs disposable surface

Permanent (invest freely): shared parsers/projector/review, §3.2 invariant enforcement, the
canary/red-test suites, the public archive, the audit-report command, quarantine/release seam.
Disposable at closeout (name the trigger in every new addition, per the carried safety invariant):
historic commands, staging guards/contexts, bundles and transfer, checkpoints/journal, ingress
locks, release ledger, production guard. Do not grow the disposable surface except where an IC
package requires it.
