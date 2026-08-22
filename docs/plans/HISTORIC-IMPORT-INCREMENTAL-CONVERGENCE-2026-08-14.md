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
> **Cannot proceed without a human:** disposition of the catalogued rehearsal's 26 held source
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

**Decided 2026-08-21 by the maintainer: resolve song identity in
`ChurchServiceAssertionNormalizer` (the evidence-level placement).** The reasoning and the
measurement that produced it are recorded below; invariant 4 is amended accordingly in §3.2. The
alternative — resolving at comparison time in `ChurchServiceProjector::songDimensionValue()` — was
considered and **rejected**, for the reason in "why comparison time is the wrong placement" below.
Until it is implemented (IC3), HIR-D8's corroboration path cannot finalise a service on this corpus
and its acceptance criteria cannot be met.

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
forward. **That containment argument is superseded by the measurement below** — it weighed the
placements' side effects without establishing that comparison-time resolution repairs the defect,
which it does not.

**The measurement that decided it 2026-08-21.** The framing correction above established that the
placements differ in what they touch. Inspecting the catalogued rehearsal established that they
differ in what they *fix*, and that the comparison-time placement fixes nothing.

Because every historic assertion has a null `song_canonical_key`, `matchPair()`'s **tier-1
`song_identity` match never fires anywhere in the historic corpus**. Song matching therefore runs
entirely on the tier-3 anchored-title and tier-4 anchored-position fallbacks, which were designed
as a last resort and have become the only resort. The consequence is a defect in the projection
itself, not merely in review routing:

- Across the 269 dual-source (Email + OpenLP) pending proposals, **264 carry more song items than
  either source lists**, because each song is projected twice — once per source. Only 5 merge
  cleanly. The corpus carries **1,024 surplus song items**.
- The fallbacks also mis-pair. In service 297 (morning, 2021-10-24) both sources list the same six
  songs in the same order, but the projected item filed under canonical identity
  `songs:nip 'come people of the risen king'` carries the title *"The Best Book To Read"* — Email's
  first song merged with OpenLP's third by position. The mis-pairing raises no conflict of its own.

The Email side's titles carry the projectionist's shorthand (`NIP`, quotation marks, `Praise! 873`,
parenthetical performance notes) while OpenLP carries the archive file's own title, so string
equality reports six-of-six disagreement on a service a reader would call identical.

**Why comparison time is the wrong placement.** Service 297's stored staging reasons are exactly
two entries — `corroboration_mismatch` on song membership and on song order. Resolving titles at
comparison time removes both, `stagingReasons` becomes empty, and
{@see IngestChurchServiceSourceRevision} then takes the `else` branch and **applies the projection
unattended**. What it would apply is the eleven-item merge, mis-titled item included. The placement
does not repair the merge; it removes the reviewer who would have caught it, across 264 services.
Its "changes no hash" property is a symptom of that inertness, not a safety property.

**Why the normalizer placement is correct.** A stored `song_canonical_key` makes tier 1 fire, so
the six assertions collapse into six items; the mis-pairing cannot form, because tier 1 refuses two
non-identical strong identities outright instead of degrading to position; and corroboration then
agrees because the items genuinely agree rather than because the comparison was loosened. The
projection hash changes precisely because the projection is better.

**Accepted costs, recorded rather than mitigated away.**

1. *Stored evidence and hashes become catalogue-dependent.* A catalogue edit is henceforth a
   reprojection event, not a lookup-table tidy-up: it changes assertions, `proposed_hash`, and
   therefore the acceptance state of exported bundles. Handle it as a versioned reprojection round
   under the existing `policyVersion` machinery, which {@see ChurchServiceConvergenceBundleImporter}
   already enforces on both import paths.
2. *Partial circularity of corroboration.* The catalogue was built partly from these same sources
   (IC3), so some Email/OpenLP agreement reflects shared normalisation rather than two independent
   authors. This is accepted **because storing the key makes it inspectable**: an agreement resting
   on a resolved key is distinguishable in the data from a literal-title agreement, which it would
   not be under comparison-time resolution. Era accuracy reporting (§8) must not present
   key-resolved agreement as independent corroboration without that split.
3. *Invariant 4 is amended, not bent.* See §3.2.

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
| 4 | Source evidence is immutable and single-origin, **save for versioned catalogue resolution** (amended 2026-08-21, §2.5); supersession is explicit lineage; source silence never removes another source's occurrence (was F30, B3, B5) | `IngestChurchServiceSourceRevision`, source adapters, `ChurchServiceProjector` |
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

**Amendment to invariant 4, 2026-08-21 (maintainer, §2.5).** `song_canonical_key` may be resolved
against the song catalogue when an assertion is normalised, so that stored evidence carries an
inference made at normalisation time rather than a literal source string alone. The immutability
that invariant 4 protects is thereby narrowed, deliberately, to this shape:

- What a source *said* remains immutable: `source_title` and `normalized_title` are never rewritten
  by catalogue state, and no other field may acquire a catalogue dependence under this amendment.
- The resolved key is reproducible, not free-form: it must be derivable from the catalogue plus the
  source title, so any assertion can be re-derived from its byte snapshot and a stated catalogue
  version. Invariant 3's snapshot binding is untouched.
- A catalogue change is a **reprojection event**. It may change assertions, `canonical_identity` and
  `proposed_hash`, and therefore may invalidate approved proposals and exported bundles. It is
  carried out as a versioned round under `policyVersion`, never as an in-place edit.
- Single-origin is unaffected: resolution adds a key to one source's own assertion and never merges,
  borrows or infers content across sources.

Anything wider than this — resolving titles, borrowing another source's text, or storing a key that
cannot be re-derived — remains prohibited by invariant 4 as written.

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

**IC3 item 8 — song identity resolution at normalisation (decided 2026-08-21, §2.5; steps 1, 2 and
6 IMPLEMENTED 2026-08-21, steps 3–5 outstanding).**
Implements the maintainer decision and the invariant 4 amendment (§3.2). Scope, in order:

1. Resolve `song_canonical_key` in `ChurchServiceAssertionNormalizer` for song assertions from
   both the Email and OpenLP adapters, from the source title plus the catalogue. The resolver must
   be deterministic and re-derivable; record the catalogue version it resolved against. Unresolved
   titles stay null and fall through to the existing tiers — resolution fills gaps, it never
   overrules (carried from the gate 9 parity rule).
2. Bump `ChurchServiceProjector::$policyVersion`, so existing bundles and proposals are refused
   rather than silently re-interpreted, and reprojection is explicit.
3. Re-run the catalogued RG-A replay from the recovered cache with zero model calls, and record
   against the pre-change baseline: 756 services, 627 Email identities, 427 OpenLP, 298 overlaps,
   531 pending proposals, 158 finalised, 264/269 services with surplus song items, 1,024 surplus
   song items corpus-wide.
4. Assert the projection defect is actually gone, not merely the conflicts: surplus song items on
   dual-source services must reach zero or be enumerated by reason, and service 297 must project
   six song items with correct titles. **A drop in conflicts alone does not satisfy this step** —
   that is exactly the outcome the rejected placement would have produced.
5. Report corroboration agreement split by how it was reached — resolved key versus literal title —
   per §2.5 accepted cost 2, so §8 era accuracy never presents key-resolved agreement as
   independent corroboration.
6. Regression cover for the amendment's boundary: `normalized_title` and `source_title` unchanged
   by catalogue state; a re-derivation test proving the key is reproducible from snapshot plus
   catalogue version.

Expected direction from the §2.5 measurement, to be confirmed rather than assumed: membership
agreement 0/298 → 162/298 and order 0/298 → 154/298. That still leaves roughly 136 overlaps routed
to review on genuine cross-source disagreement, which is designed residue, not a shortfall.

**Implementation status 2026-08-21 (steps 1, 2, 6).** `ChurchServiceAssertionNormalizer` resolves
song identity for planned evidence only, so Email and OpenLP resolve while Livestream (stronger
lyric/OCR evidence) and Manual (invariant 5) do not — the existing `ChurchServiceEvidenceKind`
argument already separates exactly those sets, so no new plumbing was added. The rule was not
copied: `ServiceItemCatalogueSongResolver` already existed for the live item-merge lane with this
same rationale in its docblock, so its per-item resolution was extracted as `resolveItems()` and is
now called by both lanes. The divergence this closes is that the live lane has resolved song
identity through the catalogue for some time while the evidence lane compared raw strings.
`PROJECTION_POLICY_VERSION` is 4. Pint, PHPStan and the full suite (7,044 tests) are green; the two
Dusk failures seen on the first run were browser-transport timeouts and pass on re-run.

**Out-of-scope fix taken deliberately: `Praise!` decoration.** `SongTitleResolver::stripEmailDecoration()`
stripped `Praise 873` but not `Praise! 873`, so the hymnbook's own punctuation left songs unlinked.
It was first assessed as not worth fixing on historic prevalence (8 of 4,341 song assertions) and
that assessment was **wrong**: the resolver is shared with the live lane, so the miss recurs on every
weekly order of service that spells the hymnbook's name naturally, and historic prevalence was the
wrong denominator. Fixed, with the hymn-number forms covered as a set so a later edit cannot repair
one spelling and drop another.

**Replay completed 2026-08-21 (steps 3, 4, 5) — the defect is fixed and corroboration finalises.**
Replayed on `crockenhill_rehearsal_catalogued_v4`, provisioned certified-clean, catalogue seeded
(1,173 source songs → 1,159 canonical, `songs.sqlite` hash unchanged), recovered parse cache seeded
(554 sources), OoS plan hash `2c139a880b78…` and OpenLP plan hash `7acb266f52c3…` both matching the
recipe. `--cache-only`, so **zero model calls**. The v3 database is retained, and both sides were
measured with one script rather than assembled by hand
(`storage/scratch/ic3-item8-replay-20260821/`).

Admission is undisturbed: 438 created, 74 evidence-retained, 26 held, 16 merged; 756 services, 627
Email identities, 427 OpenLP archives, 298 overlaps, 0 failures.

| Measure (all 298 overlaps) | v3 baseline | v4 replay |
|---|---|---|
| Song assertions carrying a catalogue key | 0 / 4,341 | **4,094 / 4,341** |
| Services with surplus song items | 264 | **88** |
| Surplus song items | 1,024 | **128** |
| Pending proposals (corpus-wide) | 531 | **395** |
| Finalised services (corpus-wide) | 158 | **294** |
| `corroboration_mismatch` membership / order / count | 269 / 269 / 55 | **121 / 128 / 55** |

**The residue is enumerated, as step 4 requires.** All 88 services and all 128 surplus items are
attributed, none unexplained: **49 services / 84 items** carry a title the catalogue does not hold,
so the key stays null and matching falls through to the existing tiers by design; **39 services /
44 items** have both sources resolving to *different* catalogue entries, where tier 1 correctly
refuses to merge two non-identical strong identities and routes the disagreement to review. Service
297 is the worked example: 11 song items → 7, the position mis-pairing gone (*"The Best Book To
Read"* now sits under its own identity), and the one surviving duplicate is Email resolving to
`the lords my shepherd 23b` against OpenLP's `the lords my shepherd i will trust in you alone`.

**That second class is correct behaviour, not a backlog (checked 2026-08-21).** Matching first lines
were initially read as evidence that such pairs are one song in two settings, and that reading is
**withdrawn** — the catalogue's own authorship disproves it. `The Lord's My Shepherd #23B` is
William Whittingham (1524–79) against Stuart Townend's `(I Will Trust In You Alone)`;
`Bless the Lord, O my soul (10000 reasons)` is Jonas Myrin / Matt Redman against an unattributed
`Bless the Lord, O My Soul`; `Come O Fount Of Every Blessing (plus extra verse)` is Bob Kauflin's
added verse over Robert Robinson, so its words genuinely differ. Identical opening lines therefore
do not establish identity, and a first-line merge rule would have collapsed distinct songs into one
another. Tier 1's refusal to merge two non-identical strong identities is the correct outcome. Do
not open a catalogue de-duplication work package on the strength of first-line similarity;
`song_authors` is the far better discriminator and is already populated (595 authors).

**But the residue is not therefore all correct — `NIP` is a discarded constraint (2026-08-21).**
The conclusion that these disagreements simply belong in review is **withdrawn**. `NIP` means
*not in Praise!*: it asserts the song is absent from the hymnbook, so it is evidence about *which*
catalogue row is meant, not decoration. `SongTitleResolver::stripEmailDecoration()` removes it. The
corpus bears the meaning out — NIP-prefixed Email lines resolve to songs with no Praise! number 626
times against 44 with one, while Praise/number lines resolve to numbered songs 1,041 times against
19 without.

Those **44 NIP lines resolved to a Praise!-numbered song contradict their own source**, and **23 of
them have a NIP-consistent alternative already in the catalogue** — `NIP 'beneath the cross of
jesus'` resolved to `#699` while an unnumbered *Beneath the Cross of Jesus* exists; `NIP 'facing a
task unfinished' (getty version)` resolved to `#618` while the unnumbered Getty rewrite exists. The
remaining 21 have no unnumbered alternative and are genuinely ambiguous: either the operator's NIP
was loose or the catalogue lacks the row.

Service 297 is one of the 23. Email's `NIP 'The Lord's my Shepherd'` cannot be Whittingham's
Praise! 23B, so it is Townend's setting — which is what OpenLP said. The two sources agreed and the
resolver manufactured the conflict. So both readings hold at once: the catalogue rows are genuinely
distinct songs, *and* the resolver picked the wrong one of them by discarding what the source said.

**Resolved 2026-08-22 — NIP now selects the row (`SongTitleMatch::TYPE_HYMNBOOK_ABSENT`).** A line
marked NIP prefers a catalogue song the hymnbook does not number, on an **exact or word-boundary
prefix match only**. Prefix carries information — an email writes `my hope is built` for the
catalogue's *My hope is built on nothing less* (Cornerstone), and `man of sorrows` for *Man of
sorrows, Lamb of God*. Fuzzy resemblance does not, and the corpus proves it: at a 0.75 cutoff it
pairs `to God be the glory` with *Thine Be The Glory* and `tell all the world of Jesus` with *Tell
Me The Stories Of Jesus* — different hymns whose numbered rows were already correct. Both near
misses are permanent regression tests, so no later widening can reintroduce them. Ambiguous prefixes
resolve to nothing, as everywhere else in this resolver.

A number written behind a NIP marker is a supplement number rather than a Praise! one — already
documented on {@see SongTitleResolver::leadingPraiseNumber()} — so it is deliberately not treated as
positive evidence that would override the marker. The type is registered as an audited match
everywhere inferred links are audited, so the link records that the marker drove the choice.

Expected effect, to be confirmed by the replay rather than assumed: **28 of the 44** NIP-contradicting
resolutions become correct (23 exact twins plus 5 prefixes), and 16 keep their numbered row. Under
the maintainer's rule that **a different tune does not make a different hymn, while different or
added words do**, most of those 16 are already right — `when i survey (modern tune)` is Watts #453
and `yes finished… new version` is Wesley #452. `PROJECTION_POLICY_VERSION` is 5; the replay is
**pending** and should be batched with the extraction work below rather than run twice.

**A catalogue-coverage claim made here on 2026-08-21 is withdrawn.** The modern settings were
reported as absent from the catalogue; they are present — *My hope is built on nothing less*
(Mote / Liljero / Myrin / Morgan / Bradbury) and *Man of sorrows, Lamb of God* (Ligertwood /
Crocker). The test that reported them missing required exact bare-title equality and never reached
a longer catalogue title. There is no coverage gap here; there was a matching gap.

**Cross-source corroboration now finalises, measured the way the withdrawn claim should have been.**
Rather than reading `payload_complete` — which is absent from every one of the 650 Email records in
both databases, so it cannot discriminate — this counts merged song items whose
`metadata.source_assertion_sources` carries *both* sources:

| | v3 baseline | v4 replay |
|---|---|---|
| Finalised overlaps | 29 | **165** |
| …with a song item corroborated by both sources | **0** | **136** |
| …whose song items are OpenLP-only | 29 | 29 |
| Song items carrying both sources | 0 | **565** |

This independently reconfirms the withdrawal of the original "29 automatically converged": those
same 29 are still OpenLP-only in v4 and still are not corroboration. The **136** services are new,
and are the first services on this corpus that HIR-D8's corroboration path has actually finalised.

**Step 5 — agreement split, and it is the uncomfortable number.** Of 1,340 Email song assertions on
overlapping services: 1,170 (87.3%) agree with OpenLP *only* after both sides resolve through the
catalogue, 6 (0.4%) agree on literal title, 103 (7.7%) are unresolved, 61 (4.6%) disagree. So of the
1,176 agreements, **99.5% rest on the catalogue rather than on matching text**. Accepted cost 2 is
therefore not a theoretical caveat on this corpus but the dominant case: §8 era accuracy reporting
must present these as catalogue-resolved agreement, never as two independent authors writing the
same thing. The catalogue is a legitimate anchor — it is the only vocabulary both sources reach —
but it was built partly from these sources, and the reporting must keep that visible.

**What this does not yet certify.** RG-A requires the full audit-report reconciliation, not only the
song-identity figures. This run shows `Auto-import precision (identity) 77.6%`, well under the FR-D4
floor of 0.98, and `Item counts reconciled 0/1`; the 26 holds still await the maintainer's
`--accepted-holds` ruling (§10). Song identity was one gate condition among several, and the others
are unchanged by this work.

**IC3 item 9 — Email song-reference extraction (BUILT 2026-08-22; replay pending with item 8).**
The residue after item 8 is almost entirely Email-side: of 247 unresolved song assertions in the
catalogued replay, **247 are Email** and OpenLP resolves against the same catalogue nearly
perfectly. The catalogue is therefore not the limiting factor — reading the Email line is. At least
**85 (34%)** name a song the catalogue already holds, and that is a floor, because the estimate used
a strict similarity cutoff and left obvious cases out (`nip 'jesus is lord, the cry that echoes'`
and `nip 'jesus is lord – the cry'` are the same song written three ways). About **15 (6%)** name no
specific song at all — `hymn - gareth to choose`, `2 songs from holiday club (to follow)` — and must
keep resolving to nothing.

The classes are named, and each is a separate decision rather than one regex:

1. **Prose wrappers** — `final hymn for evening – 313 'let us love and sing and wonder'`,
   `welcome sheet: 'see what a morning'`, `my final hymn for the morning is 427 '…'`. The title is
   present, wrapped in scheduling prose. Largest class.
2. **Mojibake** — `nip â€˜behold the lambâ€™` is UTF-8 read as Latin-1. This is an encoding defect
   in the email path, worth fixing on its own merits and not by teaching the matcher to read it.
3. **Other hymnals** — `mp196 'good christian men rejoice'`. Only `Praise` and `NIP` are handled;
   Mission Praise numbering is not.
4. **Contractions and spelling variants** — `there's a hope` against the catalogue's
   `there is a hope`.
5. **Hymn numbers in unhandled line shapes** — 35 lines carry a number the catalogue holds.

**The trap this work must not fall into.** A naive number pass matches
`songs 2+3 - who, o lord, could save themselves?` to hymn **#3, *O Lord How Many Enemies*** — the
`2+3` is a list position, not a hymn reference. A number must be in hymn-reference position to
count. In this corpus a wrong link is worse than a null one, so every class above needs the same
treatment NIP got: an exact/prefix rung with the near misses pinned as regression tests, never a
widened fuzzy cutoff.

**Variant parentheticals are the same discarded-constraint problem as NIP, and are harder.**
`(cornerstone)`, `(getty version)`, `(modern tune)` and `(new version)` name which setting is meant,
while `(v1 only remaining seated)` is a performance note; `buildProbes()` strips both alike. Under
the maintainer's tune-versus-words rule a `(modern tune)` variant is the *same* hymn and needs no
new row, whereas added words — Kauflin's extra verse on *Come O Fount* — make a different one. Do
not attempt this class without that rule in hand.

Sequencing: land with item 8's pending replay so `PROJECTION_POLICY_VERSION` moves once and the
catalogued RG-A replay runs once, not twice.

**Built 2026-08-22, and measured against the corpus rather than against its examples.** Every
change was scored by resolving all 2,569 Email song assertions in `crockenhill_rehearsal_catalogued_v4`
through the working tree's resolver and diffing against the same run before the change
(`storage/scratch/ic3-item9-20260822/`), so the evidence is gains, *changes* and *losses* — not
gains alone. Result: **71 newly resolved, 16 re-pointed at a different song, 3 links removed**;
2,339 → 2,407 of 2,569 (93.7%). Every one of the 16 changes and all 3 removals were inspected
individually and each is a correction.

**The plan's named trap was not hypothetical — it was already live.** `stripLeadingLabel()`
stripped `Song ` from `Song 1 - Oceans` and handed the `1` to the Praise!-number rung, so twelve
corpus lines were linked to *Happy The People Who Refuse #1* and *O Lord How Many Enemies #3* —
songs nobody had written down. Those are the 16 changes and 3 removals. The rule now consumes a
**single digit** after the role word as a list position: services number their songs in single
digits, while the corpus writes every real reference with the book's printed two- or three-digit
form (`Song 187 'O God beyond all praising'`), so the digit count separates them without reading
the rest of the line. `songs 2+3` still resolves to nothing, and a number the writer marked as a
reference — `(#894)`, `Praise no 618` — is read by its own rung and is unaffected.

**What each class needed.**

1. **Prose wrappers** are read off the *quotes*, not by enumerating the prose: the writer already
   said where the title stops. One quoted run per line only — `either 'great is thy faithfulness',
   'amazing grace' or 'it is well with my soul'` offers a choice, and resolving the first would
   pick a winner the source never picked. The run is taken from the raw line because
   `stripEmailDecoration()` trims a leading or trailing quote and would leave the other half
   unpaired. This is the largest single contributor.
2. **Mojibake** is repaired at ingest, in `App\Support\MojibakeRepair`, not in the matcher. The
   transform is applied only when it is **reversible** — re-damaging the candidate must reproduce
   the input exactly — so accented prose and text that was never damaged are returned untouched.
   It is wired at three boundaries: the Mailgun request (body, HTML and subject), the OoS archive
   entry factory (after digest verification, since the approved bytes stay hashed as approved),
   and `ChurchServiceAssertionNormalizer`. The last is required, not belt-and-braces: the archive
   parse cache is keyed on the source file's **digest**, not its body, so results banked before
   the ingest fix still arrive damaged — which is also why repairing the body costs the replay no
   model calls.
3. **Other hymnals** needed no rung of their own. `MP196 'Good Christian Men Rejoice'` resolves
   through the quoted run, and `(p315)`/`(#894)` through the parenthesised book-number rung, which
   requires the marker to *open* the parenthetical so `(Crockenhill Praise 47)` — a different
   book — is not read as a Praise! number.
4. **Contractions and `&`** are expanded per probe. Only contractions that cannot also be
   possessives: expanding `'s` generally would turn `God's love` into `God is love` and hand a
   different catalogued song a false match.
5. **Hymn numbers in unhandled line shapes** are reached by three narrow additions: a full stop
   counts as a label separator (`Hymn. 96`), a leading `4. ` enumerator is stripped (without it
   `4. Praise no 618 - Facing a task unfinished` resolved to the *unnumbered* Getty rewrite
   instead of #618, because the enumerator hid the explicit reference), and a dash-separated tail
   is probed as an attribution (`Creator God - Ben Slee`).

**NIP became position-independent, and that was a second discarded constraint.** Item 8 anchored
the marker to the start of the line, so `Communion hymn – NIP 'Beneath the cross of Jesus'`,
`Beneath the cross of Jesus NIP` and `Song In Christ Alone (NIP)` all had their marker ignored and
kept resolving to the numbered twin. The assertion is the same wherever the operator wrote it.
A role word standing immediately before the marker is now recognised as a label on that evidence
(`Hymn NIP Creator God`), and only on NIP lines is a bare role word stripped — the marker has
already narrowed the answer to an unnumbered song, so a title that genuinely opens with "Song" has
to be unnumbered *and* match the remainder exactly before this can mislead.

**Nothing was widened fuzzily.** Every addition is an exact or word-boundary rung, and the near
misses are pinned: `tests/Unit/Services/Song/SongTitleResolverEmailReferenceTest.php` holds the
list positions, the three-song choice and the lines that name no song, all verbatim from the
corpus.

**The residue is enumerated, not estimated.** 162 assertions remain unresolved: **83 (51%)** name a
song the catalogue does not hold, **58 (36%)** name no specific song and must keep resolving to
nothing (`hymn - gareth to choose`), and **21 (13%)** are still matcher gaps — almost all typos
(`come o found of every blessing`, `when i fear my fail with fail`) which only a widened fuzzy
cutoff would reach, plus `NIP 'On the cross'`, which is genuinely ambiguous between two catalogue
songs and correctly refuses. Item 8's ceiling estimate of 85 reachable was a floor, as it said.

`PROJECTION_POLICY_VERSION` stays at **5** — item 8's replay has not run yet, so both land under
one version and one replay, exactly as the sequencing note requires. Pint, PHPStan, the full suite
(7,091 tests) and Dusk (55 tests) are green.

### Items 8 and 9 — the catalogued replay, run 2026-08-22

`crockenhill_rehearsal_catalogued_v6`, provisioned certified-clean, catalogue seeded (1,173 source
songs → 1,159 canonical, `songs.sqlite` hash unchanged), recovered parse cache seeded (554
sources), OoS plan hash `2c139a880b78…` and OpenLP plan hash `7acb266f52c3…` both matching the
recipe. `--cache-only`, and **all 554 entries reused their cache: zero model calls**. None of the
changed files is in `OosParserSurfaceFingerprint::Files`, so the parser version did not move.
Artifacts in `storage/scratch/ic3-item9-20260822/`.

**Admission is undisturbed, again:** 438 created, 74 evidence-retained, 26 held, 16 merged; 756
services, 627 Email identities, 427 OpenLP archives, 298 overlaps, 0 failures.

| Measure | v4 (item 8 baseline) | v6 replay |
|---|---|---|
| Song assertions carrying a catalogue key (all sources) | 4,094 / 4,341 | **4,179 / 4,341** |
| …Email only | 2,322 / 2,569 | **2,407 / 2,569** |
| Services with surplus song items | 88 | **73** |
| Surplus song items | 128 | **98** |
| Pending proposals | 395 | **385** |
| Finalised services | 294 | **304** |
| Finalised overlaps | 165 | **175** |
| …with a song item corroborated by both sources | 136 | **146** |
| Song items carrying both sources | 565 | **613** |
| `corroboration_mismatch` membership / order | 121 / 128 | **108 / 117** |
| `field_conflict` | 4 | **0** |

Assertion-level, joined on source key and position so a repaired title still pairs with its
predecessor: **88 gained, 44 changed, 3 lost**. The 44 split into item 8's NIP work and item 9's
list-position fix; the 3 losses are all removals of a wrong `Song N` → hymn *N* link where no real
song was reachable.

**The replay found a defect the tests had not.** The encoding repair sat inside
`ChurchServiceAssertionNormalizer`'s per-item loop, while `resolveSongIdentity()` runs *ahead* of
it — so four mojibake titles were stored repaired and had already failed to match. Fixed by
repairing before identity resolution (`26d5ee820`); the regression test asserts the resolved song
key rather than the stored title, so it fails on the old ordering. Email keys 2,403 → **2,407**,
which is exactly what the offline harness predicted: the two now agree on all 2,569 assertions.

**Item 8's NIP prediction is superseded by measurement, as it asked to be.** It predicted 28 of 44
NIP-contradicting resolutions corrected and 16 retained. Measured: the denominator is **52**, not
44 — item 9 made the marker position-independent, so eight more lines carry it — and the split is
**32 corrected, 20 retained, 0 lost to nothing**. The 20 retained hold up against the maintainer's
tune-versus-words rule: four are explicit tune variants (`when i survey (modern tune)` → #453),
two carry an explicit number alongside the marker, and the rest have no unnumbered twin in the
catalogue, so the resolver correctly declines to invent one. `man of sorrows what a name` → #433
and `man of sorrows (oh, that rugged cross)` → the unnumbered Hillsong row is the discrimination
working.

**Agreement split, restated on the new corpus.** Of 1,340 Email song assertions on overlapping
services: 1,214 (90.6%, was 87.3%) agree only after both sides resolve through the catalogue, 6
(0.4%) agree on literal title, 69 (5.1%, was 7.7%) are unresolved, 51 (3.8%, was 4.6%) disagree.
**99.5% of agreement still rests on the catalogue rather than on matching text** — unchanged, and
§8 era accuracy reporting must keep presenting it that way.

**Residue after both items: 162 Email assertions and 98 surplus items, all attributed.** The
surplus is 38 services / 59 items whose title the catalogue does not resolve and 35 services / 39
items where both sources resolve to *different* catalogue entries — the second class is still
correct behaviour per the 2026-08-21 finding, not a backlog.

**RG-A is unchanged and still fails.** `Auto-import precision (identity)` is **77.6%** against the
FR-D4 floor of 0.98 and `Item counts reconciled` is **0 / 1** — both identical to the v4 run.
Song identity was one gate condition among several and this work moved only that one
(`Song-link hit rate` 93.6%, `Plans with every song resolved` 528 / 657). The blocker remains the
date/identity resolver and the maintainer's `--accepted-holds` ruling on the 26 holds (§10), not
song matching. Items 8 and 9 are closed; RG-A is not.

### IC3 item 10 — `services_present` was a scalar (2026-08-22)

**The RG-A identity blocker was never extraction, and never the date resolver.** Date accuracy in
the v6 run is 553/554 (99.8%). All 132 `auto_import_precision` failures carry the correct date and
non-empty items and fail on the *service slot* alone; all 132 sit inside one of the 138 entries the
pipeline already flags `service_beyond_manifest`. Four were checked against verbatim source and are
correct extractions of a real second service — `2015-06-14` literally reads
`Evening Family/Youth Service:`, `2015-08-09` carries an `Evening Service:` section at line 66.

**Root cause.** `OosCurationEntryFactory` built `servicesPresent: [$include['resolved_service']]`
from a **scalar** manifest field. 138 of 554 entries (25%) are one Sunday email carrying both that
morning's and that evening's orders, so a correctly-extracted second service scored as an identity
error against the FR-D4 floor. `services_present` reads as a list at every consuming site —
`ChurchServiceCorpusExpectation` validates against it, `autoImportPrecision()` scores against it —
and the only thing that ever wrote it could write one element. This is F66's "validator without a
producer" in its purest form: a single-element list is indistinguishable from a correct one at
every reading site, so the defect was visible only where the value is produced.

**Why the fix is additive rather than plural.** `resolved_service` is identity-bearing: it is half
the source key (`OosCurationEntryFactory::sourceKey()`), it keys the one-active-leaf-per-service
guard (§7.2 lineage), and `OosApprovedCorpus` derives F1 expected membership from it. Widening it
would re-identify every staged revision in the corpus to fix a measurement. Schema **v2** therefore
keeps `resolved_service` untouched and adds `additional_services` (plus `additional_service_labels`
and `curation_note`), which feed `servicesPresent` only. `OosApprovedCorpus` is deliberately not
changed: the hash-covered `service_beyond_manifest` rule still admits the extra identities, so
expected membership is unmoved. The version bump is the load-bearing half — the field is additive
and its absence unambiguous, but a v1 reader handed a v2 manifest would silently drop a declared
second service, which is exactly the failure the field exists to end.

**Adjudication, produced not asserted.** Artifacts in `storage/scratch/`, and the producer →
rulings chain re-runs to a byte-identical artifact:

| Artifact | SHA-256 |
|---|---|
| `services-present-adjudication-20260822.json` | `1c3546e59190730c4aab5eae9b7eb1446633508e043689492d1482fa937e0468` |
| `services-present-adjudication-producer-20260822.py` (rule `oos-services-present-producer-v4`) | `0aa5dc0cb7826c4ba209c596c51f9a7b8c121371515fced5c4751117e1ccfc18` |
| `services-present-rulings-20260822.py` (the maintainer's 2026-08-22 rulings) | `13098acd24994c6b94b6f9610fe6ef73a6388be68272a8ea3ef902223b35cab3` |

**The rule is positional, not lexical:** a line counts as a service heading only when it *starts* with the service
name, which is what separates `Evening Service – communion – 6pm` (a section) from
`Final hymn for evening service – 641 '…'` (an item belonging to a service that is not in this
document). Calibrated by negative control — the same rule against the service the manifest already
declares finds it in **129/138 (93.5%)**, so the rule under-detects and routes to a human, which is
the only direction it can err.

| Decision | Count |
|---|---|
| `confirmed_present` (standalone heading in the body) | 118 |
| `confirmed_present_afternoon_slot` | 8 |
| `confirmed_second_service_slot_unresolved` | 3 |
| `human_adjudication` | 11 |

**Maintainer rulings, 2026-08-22.** The 118 accepted as a batch. Four afternoon/evening headings
(`2015-11-08`, `2016-03-13`, `2016-06-12`, `2017-06-11`) are genuine evening services. The five
2022 building-work Sundays (`2022-02-27`, `2022-03-06/13/20/27`) met at 10am in the rear hall and
2pm in the village hall; the 2pm carried the children's talk and main sermon and was functioning as
the main service, but is stored as **evening** because `SermonService` and the `{service}:{date}`
plan key cannot hold two morning services on one date — recorded verbatim in `curation_note` on all
five. Fragments are **preserved**: a stray hymn belonging to a service whose order is elsewhere
keeps its evidence rather than being discarded. `2023-12-24`'s second full order is the Christmas
*morning* service — a different date, not a slot — declared `other` with the required label.

**Measured.** `Auto-import precision (identity)` 456/588 = 77.6% → **585/588 = 99.49%**, which
clears the FR-D4 floor of 0.98 on the manifest change alone. The residual 3 are the `other`-slotted
plans in point 1 below, held for review by design; 585/585 was considered and rejected as not worth
a corpus re-parse. Curation plan
hash `2c139a880b78…` → **`5a6bef7cf376…`**; the pinned rehearsal-recipe hash is stale by
design and must be updated before the next replay so the mismatch is not read as a fault. The
extraction cache is untouched — curation is not in `rawCacheKey()` — so the replay stays **zero
model calls**. `entryAuthorityHash()` includes `services_present`, so the portable bundle needs
regenerating; that is already the open §10 bundle item and the two should land together.

Pint, PHPStan, the full suite (7,102 tests, 84,128 assertions) and Dusk (55 tests) are green.

**Outstanding, and why each is not this item's work.**

1. **Three `other`-slotted plans stay held for review — deliberately not a parser change.**
   `2015-12-20` (3 stray songs), `2016-02-07` (4 stray songs) and `2022-02-27` (a complete 12-item
   order whose source reads `Afternoon meeting (2pm) in village hall`) are slotted `other` by the
   extractor and refused by the validator — *"An other service requires explicit special-service
   evidence"*. All three appear in `held_plan_keys`: they are held and enumerated for review, not
   dropped, which is the designed outcome rather than a defect.

   **Do not "fix the parser" for these.** The slot is the extractor's own output
   (`"service":"morning|evening|other|unknown"` in `OosEmailExtractionPrompt`), so there is no
   file-specific lever, and a general one would be a prompt change to correct 3 documents out of
   554. `ParserVersion` is part of `rawCacheKey`, so any parser change — prompt or deterministic
   guard — re-parses the whole corpus with model calls. Worse, the archived model evaluation
   measured source-exact self-disagreement of 24/30 at `effort=none` with item structure alone above
   the 10% threshold, so a bump perturbs the corpus measurement *even with no prompt change*: the
   re-run is itself a change to the thing being measured. Identity precision is **585/588 = 99.49%**
   without any of it, clear of the 0.98 floor; the fixes were buying a nicer number, not a passing
   one. Revisit only if review load justifies it, and then as a general slotting rule measured
   across the corpus, never as a per-document correction.
2. **`Item counts reconciled` 0 / 1** — one entry, `2026-02-22-am-revised`, asserted 13 items and
   parsed 14. It needs adjudicating, but a one-plan denominator should probably not gate RG-A at all.
3. **The 26 held semantic Email sources** still need the `--accepted-holds` ruling (§10). Unchanged
   by this item.

**A naming defect found on the way.** `corroborated_plan_keys` in the run report is
`array_unique($eligiblePlanKeys)` (`OosArchiveEvaluator.php:69`) — eligibility, not corroboration.
Any figure quoted from that field as independent corroboration needs re-reading; the genuine
cross-source corroboration measure is the census, not this.

### IC3 item 11 — the portable bundle dropped its ignored lines (2026-08-22)

**`preflightPortable()`'s 403 refusals were a bundle-format defect, not 403 bad documents.**
Splitting `rga-catalogued-portable-structural-holds-20260821.json` by reason class:

| | Entries |
|---|---|
| Refused **solely** for unclassified source lines | 373 |
| Refused for at least one other reason as well | 30 |
| Valid | 151 |

**Root cause, and it is the same seam class as item 10.** `OosEmailExtractionValidator` requires
every source line to be classified as service evidence, an item, or ignored context. The extractor
produces those `ignored_lines` and `OosEmailParserService` carried them through the evening-evidence
pass — then dropped them at the `OosEmailParseResult` boundary, which had no such field. They never
reached `processing_metadata.parsing`, so `export()`'s `Arr::only` could not ship them, so
`structuralReasons()` re-validated every shipped entry against an extraction that declared nothing
ignored. Every greeting and signature in the corpus came back as an unaccounted line: 2,121 reason
instances of one shape. Item 10 was a value that could only ever be written singular; this is a
value that was produced and then discarded in transit. Both were invisible at every reading site.

**Not derived, persisted.** Reconstructing "ignored = any line no item claimed" was rejected: it
makes the rule vacuously true, and the rule's whole value is that the extractor *declared* each
line's disposition. The field is threaded through the parse result, its encode/decode pair, the four
`OosArchiveIdentityResolver` rebuilds that already carry `extractionAttempts` the same way, and the
bundle's export and rebuild.

**Bundle `VERSION` 1 → 2**, on item 10's reasoning: a v1 bundle is refused outright rather than read
leniently, because a lenient read turns a check that cannot run into a silent pass. Malformed
entries are dropped on restore but refused on import — a bad shape in the database is legacy
residue, a bad shape in a bundle means the bundle is untrustworthy.

**Reproduced before it was fixed.** With the fix stashed, a three-line source whose third line the
extraction ignores stages as
`"Source line 3 was not classified as evidence, an item, or ignored context."` and `--apply-bundle`
refuses the bundle. Both tests are retained.

Pint, PHPStan, the full suite (7,106 tests, 84,149 assertions) and Dusk (55 tests) are green.

**Outstanding.** The bundle has not been regenerated — that is the §10 item, and it now folds
together with item 10's `entryAuthorityHash()` move: one replay covers both. The expected outcome is
portable-valid 151 → ~524 of 554, with ~30 genuine structural holds remaining (16 evening-boundary,
13 non-existent service evidence lines, 6 non-existent item lines, the 3 `other`-slotted plans ruled
not-queued in item 10, 2 duplicate plans; the classes overlap, so do not sum them). Those 30 are
real findings about documents and need dispositioning, not a format change.

**Before the replay:** the curation plan hash is
`5a6bef7cf3769f534de1ebee869b92a026c11d83777e25ab8f3d0e254fef3460` (recomputed 2026-08-22, matching
item 10's `5a6bef7cf376…`). The rehearsal recipe's pinned `2c139a88…` is stale and would report a
plan-hash mismatch that reads like a fault.

### IC3 item 11 replay — the bundle regenerated, the refusals did not move (2026-08-22)

`crockenhill_rehearsal_catalogued_v7`, provisioned certified-clean, catalogue seeded (1,159
canonical), recovered parse cache seeded (554 sources, all with a cached binding). Plan hash
`5a6bef7cf3769f534de1ebee869b92a026c11d83777e25ab8f3d0e254fef3460` accepted. `--cache-only`, **zero
model calls**.

| Measure | v6 | v7 |
|---|---|---|
| Date accuracy | 99.8% | 99.8% |
| Auto-import precision (identity) | 99.5% | 99.5% |
| Held for review | 26 | 26 |
| created / evidence_retained | 438 / 74 | 441 / 71 |

**The three that moved are item 10's effect, not item 11's.** `2015-06-14`, `2016-05-01` and
`2016-06-26` imported *identical* plan keys in both runs; what changed is that the manifest now
declares the second service the document carries, so the entry is fully explained and reads
`created` rather than `evidence_retained`. `2015-06-14` is one of the four item 10 checked against
verbatim source.

**The bundle regenerated cleanly and the refusals are unchanged: still 151 valid / 403 invalid, with
byte-identical reason counts.**

| Artifact | SHA-256 |
|---|---|
| `rga-portable-assertions-20260822.json` (format v2, 554 entries) | `ba2ae5a69e8117d23366c7210b84f3c0921e0d358ab6760d8b2370e31f6dfc5b` |
| bundle_hash | `371b262f3b3138e0c19974ff1b5021a90de716638f4166ecd18dec8898a22d34` |
| `rga-portable-structural-holds-20260822.json` | `6e4166078bd5b4a1de527890fc4382124c491e45077d951555a6eddd4b396183` |

**Why, and it corrects a claim made when item 11 was scoped.** `archive_parse_cache.raw_result` is
not raw model output despite the name — it is an `InboundEmailImportService::encodeParseResult()`
payload, as that method's own docblock says ("the archive's raw-extraction cache is the one caller
that does"). Its keys are the encoded parse-result keys, and it was written before `ignored_lines`
existed, so a `--cache-only` replay decodes an empty list for all 554. Every exported entry carries
`"ignored_lines": []`. The code path is correct — the synthetic reproduction exercises a real parse
and passes — but the banked corpus has nothing to put through it.

**The data is recoverable, free.** `extraction_attempts[].final_annotations` survives in the same
cache with every line's `role`, `service_group_id` and `shared_service_group_ids` — exactly what
`CompileOosSemanticAnnotations::ignoredLines()` consumes. The two compile rules also partition the
document completely: a non-item line *inside* a service group becomes `service_evidence_line_ids`
(`evidenceLineIds()`), one outside every group becomes an ignored line. So a recompile closes the
coverage rule rather than merely reducing it.

**The precedent exists in the evaluation lane and not in the import lane.**
`oos:recompile-semantic-candidate-evidence` was built for exactly this move — its docblock reads
"Candidate artifacts retain `attempts[].final_annotations` precisely so this is possible" — but it
replays banked *candidate* artifacts, not the archive parse cache. The import lane has no
equivalent.

**Next step, and it is a decision, not a task.** Backfilling `ignored_lines` into banked evidence is
a mutation of the cache the whole lane is replayed from. It needs a maintainer ruling before it is
built, sized as: recompile `ignored_lines` from `final_annotations` into the stored parse payload
and the cached `raw_result`, re-export, re-enumerate. Zero model spend either way. Until then the
portable path stays at 151 of 554 and the §10 bundle item stays open.

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
| ~~**Song identity for HIR-D8 corroboration**~~ | — | **DECIDED 2026-08-21: resolve in `ChurchServiceAssertionNormalizer`** (§2.5), invariant 4 amended (§3.2), implementation queued as IC3 item 8. Comparison-time resolution was considered and rejected: it would have auto-applied 264 services' duplicated song items unattended |
| ~~**Portable bundle ignored-line provenance**~~ | — | **CODE DONE 2026-08-22** (IC3 item 11): `ignored_lines` persisted, bundle format v2, both tests retained. 373 of the 403 refusals were this defect alone. What remains is regenerating the bundle, which folds into the item-10 `entryAuthorityHash()` replay below |
| ~~Regenerate the portable bundle on the moved `entryAuthorityHash()` and format v2~~ | — | **DONE 2026-08-22**, v7 replay, zero model calls. The bundle is current; the refusals are not fixed by it |
| **Rule on backfilling `ignored_lines` into the banked parse cache** | Portable apply — still 151 of 554 | Maintainer. The cached `raw_result` is an encoded parse result written before the field existed, so the replay could not supply it; `final_annotations` in the same cache can, at zero spend. This mutates banked evidence, which is why it is a ruling and not a task |
| Disposition the 26 held semantic Email sources, or rule on them as `--accepted-holds`; reason counts overlap, so do not turn their sum into a workload | Email-lane settlement / F1 reporting / `expectation_mismatch` | Operator. Prior ~14, RG0A's 19 and the recovered run's 41 are superseded |
| ~~**Second services the manifest could not declare**~~ | — | **DECIDED 2026-08-22** (IC3 item 10): schema v2 `additional_services`, 137 entries re-curated, identity precision 77.6% → 99.49%. Plural `resolved_service` was considered and rejected: it is half the source key, so widening it would re-identify every staged revision |
| ~~Three parser slot fixes for `other`-slotted plans~~ | — | **NOT QUEUED 2026-08-22** (IC3 item 10): all three are held and enumerated for review, which is the designed outcome. The slot is the extractor's output, so a fix is a prompt change costing a full paid re-parse — and re-parsing is not idempotent (24/30 source-exact self-disagreement), so it would perturb the corpus to correct 3 of 554. Identity precision is 99.49% without them |
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
