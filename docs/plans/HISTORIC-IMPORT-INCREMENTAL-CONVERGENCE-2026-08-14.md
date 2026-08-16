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
> **Cannot proceed without a human:** the ~14 email identity/date adjudications (§6 IC1); the video
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
holds reduced to the identity/content-invalid residue; (b) the ~14 genuinely-manual adjudications
are done; and (c) IC3's ground truth exists. That state triggers IC5.

### 2.5 Carried and lapsed prior decisions

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
relitigated**. HIR-D8 remains open (§10).

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
| **Kept as open work** | F60 (IC6), F1 completeness (IC1), current-era back-fill (IC4), video manifest population (IC5), OpenLP v2 curation fields (IC5), HIR-D8 (IC3) |
| **Reframed as report** | F32 (per-source accounting; exit contract changes in IC2), F53 (exact membership), F57 (round audit completeness), per-round cost/throughput accounting (was F58's measurement half) |
| **Closed with evidence** | F2, F3, F4, F42, F43, F46's guard code, F61, F62, F63, F64, F65, F66; B1–B21 (all repaired; red tests retained); HIR0–HIR7 landed |
| **Lapsed with the one-shot model** | F35 (journal-resume proof), F36 (forensic two-copy custody ceremony — read-only original, one verified working copy and hash inventory remain required practice), F38 (checkpoint *exactness* gating — checkpoints stay as tooling), F39 (fingerprint *binding* — fingerprints stay as recorded provenance), F45 (timed restore/RPO/RTO drills — verified backups remain mandatory), F46 (freeze/watchboard/change-control window), F47 (forced-crash recovery proof), F56 (freeze-sweep semantics — ingress lock tooling retained for optional brief pauses), F58 (window budget); HIR4/HIR5 evidence obligations; HIR8 steps 1, 2, 4, 6–11; safety invariants 4, 5 and 9 of the archived safety plan |

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

Ordered; IC1–IC3 are current, IC4 runs any time, IC5 starts on the §2.4 trigger.

### IC1 — Email evidence-tier import (implements REV-D2)

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
families (~20 + ~33 plans on v12 shape, vs 370 held sources today); staged identity coverage from
157 toward the 393-of-394 the held backlog covers; the 82 already-converged Email/OpenLP services
unchanged (`already_present`). Then hand the operator the ~14 manual items.

### IC2 — Incremental apply semantics

Re-scope `ConvergeHistoricChurchService`'s batch admission from "whole approved corpus applicable
or refuse" to "apply every applicable service; report the rest" — per-service lock, classification
and transaction unchanged. Change `ImportOosArchiveCommand`/converge-command exit contracts (was
F32): exit non-zero only for processing *errors*; held/pending residue is reported state in the
audit report. Re-scope `HistoricImportProductionGuard` from one-shot GO to per-round approval
(named round operation + manifest/plan hashes + backup receipt). Keep `HistoricImportJournal`/
checkpoint tooling for long passes; drop their exactness assertions from acceptance.

### IC3 — Item-level ground truth (queued parser plan item 0)

Unchanged from `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md` ("Parsing
improvement plan, queued 2026-08-14" — implement in order 0, then 2; item 1 is largely superseded
by REV-D2, item 3 optional, item 4 is a no-change record). Seed the rehearsal song catalogue first
(2,580 extracted song items become item-level checks). Ground truth is derived from corroboration
before it is hand-built; hand-verify only what corroboration cannot reach. Its output decides
HIR-D8 (§10) and calibrates §8's era accuracy figures.

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

After Email/OpenLP/video convergence has staged: regenerate and hash-bind the hymn reconciliation
against the exact converged corpus (F60), disposition all 5,759 known-service rows including
cross-source duplicates, then apply through the landed operation-owned controls (F61) with the
proven no-op/reconciliation semantics (F62). The date-only lane stays quarantined until its signed
release. The workbook's `a`/`p`/`●` markers carry the service attribution; the contract tolerates
the known phantom row.

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
| HIR-D8: may cross-source corroboration replace/augment the 0.90 gate for unattended *finalisation*? | Wider automatic finalisation only | Open; decided on IC3's corroborated-vs-uncorroborated item-level exactness, per dimension (order vs membership) |
| ~14 manual email adjudications (6 identity, 8 date) | F1 completeness closure | Operator, after IC1's staging round |
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
