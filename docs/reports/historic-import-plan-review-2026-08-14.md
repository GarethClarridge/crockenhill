# Historic import programme review — wood versus trees

**Recorded:** 2026-08-14
**Status:** Review complete; four maintainer decisions taken (REV-D1–REV-D4 below). **Fold-back
done 2026-08-14**: the plan of record is now
[`HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md`](../plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md),
and the three predecessor plans are archived with supersession banners.
**Purpose:** Answer the maintainer's question: *is the current plan the best way to backfill the
website from the historic content (emailed orders of service, OpenLP files, recordings, the songs
spreadsheet), as if it had been uploaded continually — with minimal manual review, repeated
refinement rounds allowed, and every learning applied to the ongoing weekly pipeline?*

## Executive summary

**The destination is right. The vehicle is not.**

The programme's end-state — production left "in the durable state each service would have reached
if its sources had arrived through the current application at the time"
(`docs/archived-plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md` §1) — is exactly the stated
goal, and the code genuinely honours the "bulk = weekly in bulk" principle at the processing layer:
historic video dispatches through the same `UnifiedMediaProcessor` as a weekly upload, historic
emails through the same `OosEmailParserService`/`InboundEmailImportService` as the Mailgun webhook,
and one shared projector/convergence/review layer serves both eras. Parser and projector
improvements made for the import land in the weekly pipeline automatically.

What has gone wrong is the **safety model wrapped around production apply**: a one-shot,
exactly-once, windowed atomic operation, with ingress and deploy freezes, forensic source custody,
environment fingerprints bound to checkpoints, timed restore drills, an eleven-step rehearsal
requiring infrastructure the project does not have, exact whole-operation closeout and a provably
no-op second import. Roughly half of the 66 recorded findings exist only to make that single event
provably exact, and each review round finds new ways it could fail to be — which is why the verdict
has been NO-GO for five weeks while the go/no-go checklist stands at ~29 open items and the
historic-only machinery has grown to roughly 39k lines of code and tests, much of it scheduled for
deletion at WP10.

Meanwhile the code has already built a far cheaper safety property that makes most of that
apparatus redundant:

1. **Quarantine-until-release** — imported sermons carry
   `SermonPublicationState::Quarantined` and every public read surface
   (`app/Services/Public/SermonRepository.php`, `SitemapService.php`, `PreacherListCache.php`,
   `PublicChurchServiceArchiveService.php`, the podcast and API paths) excludes them until a signed
   `historic-import:release-batch` (FR-D2). Services are additionally dark behind the
   `church.services.public_from` era boundary (`config/church.php:72`).
2. **Create-only object writes** — `HistoricSermonPublicationService` (HIR7) takes a durable
   ownership claim before any byte moves, writes via conditional create, and never deletes by path;
   failures orphan rather than destroy.
3. **Per-service idempotent apply** — the `already_present` / `create` / `safe_enrichment` /
   `blocked_difference` / `conflict` classification makes re-running convergence safe per service.

Under those three invariants, a wrong or failed import is a *cheap, private, per-service* event you
fix forward and re-run — which is exactly the maintainer's stated tolerance ("repeated rounds of
refinement are fine"). The one-shot model instead defines partial progress as failure (F32: the
import command refuses success while any source is held).

Four decisions were taken on 2026-08-14 in response to this review:

| ID | Decision | Outcome |
|---|---|---|
| REV-D1 | Safety model for production apply | **Incremental convergence** behind the quarantine/release seam. Per-service idempotent apply runs repeatedly as lanes mature; backups kept; the post-apply audit becomes a report driven to green, not a gate; no ingress freeze, no no-op-rerun proof, no timed restore drills as gates |
| REV-D2 | Below-threshold email extractions | **Import as flagged evidence.** Plans with a manifest-corroborated identity enter the service graph as `needs_review` source evidence instead of being held outside it; convergence auto-finalises where sources agree; only genuine conflicts and single-source low-confidence services need a human |
| REV-D3 | Remaining rehearsal/evidence depth | **Staging rehearsal + backups.** Gate on clean per-lane staging rehearsal, a verified pre-apply database backup, the landed create-only write path, and a post-apply audit report. The infrastructure-blocked HIR8 steps (1, 2, 4, 6–11) stop gating; landed HIR code is kept |
| REV-D4 | Video lane sequencing | **Sequenced, not parallel.** Definitive processing of the 1.0 TB video corpus starts after the email lane settles (exit criteria in §6), against this review's recommendation to parallelise — recorded as the maintainer's call |

The recorded FR-D1–FR-D10, HIR-D1–HIR-D7 and §5 decisions are **not reopened** by this review.
They were made *within* the one-shot model; REV-D1 changes the vehicle those decisions were made
for. Where a decision's object disappears (e.g. the 480-minute ingress window has nothing to
gate when there is no window), it lapses with the machinery rather than being relitigated.

## 1. What was reviewed

- `docs/archived-plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md` (verdict, evidence log
  F29–F66, decisions, go/no-go checklist), `HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md`
  (value/outcome/success criteria, B1–B21, WP0–WP10, G0–G9), and
  `HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md` (invariants, HIR-D1–HIR-D8, HIR0–HIR8) —
  ~530 KB across the three.
- `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md` including the archive-v12
  corpus measurement and the queued five-item parsing plan.
- Code on both sides of the divide: the weekly pipeline
  (`app/Services/Processing/UnifiedMediaProcessor.php`, the `app/Jobs` chain,
  `app/Services/ChurchService/*`), the historic lanes
  (`ImportHistoricVideoBatchCommand`, `ImportOosArchiveCommand`, `ImportOpenLpDirectoryCommand`,
  `ConvergeHistoricChurchService`), and the promotion/safety layer (`app/Services/Import/*`,
  `app/Services/HistoricMedia/*` — ~15.2k lines, plus ~4.8k lines of historic commands and ~19.1k
  lines of historic-named tests).

## 2. Findings

### 2.1 The architecture is the right one, and the sharing is real

Every lane's *parsing and processing* is the weekly code path:

| Lane | Weekly entry | Historic entry | Shared core |
|---|---|---|---|
| Video/livestream | API upload → `UnifiedMediaProcessor` | `HistoricVideoImporter` | Same processor, same jobs chain, same review states |
| Email OoS | Mailgun webhook → `ProcessInboundOosEmail` | `ImportOosArchiveCommand` | `OosEmailParserService` + `InboundEmailImportService`, same 0.90 threshold (`service-tracking.email_parsing.auto_import_threshold`) |
| OpenLP | (church-PC uploads) | `ImportOpenLpDirectoryCommand` | `ImportChurchServiceFromOpenLp`, `OpenLpSourceAdapter` |
| All | `ChurchServiceProjector` + proposals + review inbox + automatic finalisation | same | same |

This satisfies the "import = weekly upload in bulk" requirement at the layer where it matters, and
it is why F64 (strict extraction schema) and the WP2 projector repairs are permanent weekly
improvements, not import-only spend. The public service archive (WP8) also correctly shipped
first, against current-era data.

### 2.2 The one-shot operation model is the main complexity generator — and it duplicates a cheaper, already-built safety property

Evidence of cost: 66 findings; a ~35-item go/no-go checklist with 6 ticked; HIR8's eleven-step
rehearsal blocked on a production-shaped deploy, an acquisition host, DO Spaces scratch keys,
disposable restore targets and a signed webhook during a freeze; WP9's windowed apply with split
budgets and rollback reserves; ~39k lines of historic-only code/tests, growing with each finding
(custody v2, recovery evidence v2, release ledger), each addition needing its own rehearsal
evidence. The findings are individually correct *given the exactly-once premise*; the premise is
what this review challenges. The quarantine/release seam (§ summary above) already reduces the
blast radius of any import mistake to "wrong but private rows, fixable per service" — the property
the atomic window was buying, at a fraction of the cost.

**Retained regardless** (load-bearing, cheap, or landed and harmless): F29 quarantine coverage —
this becomes *the* hard gate; F30 supersession lineage; F31 source hash verification at dispatch;
F33/F34 artifact privacy and redaction; F37 strict approved manifests as sole mutation authority;
F40 Manual authority; the F41 per-service lock; F44 identity/occasion facts; F48 explicit command
modes; F49/F50 byte-snapshot binding; F51 no-events-in-convergence; F52 notification suppression;
F53 exact membership accounting (as the completeness report's basis); F54/F55; F59/HIR3 settled
Scripture; HIR2 parse cache; HIR7 create-only object store; the hymn-lane controls F61/F62.

**Retired as gates by REV-D1/REV-D3** (code that exists is kept; evidence obligations stop):
F35 crash-safe journal (re-run replaces resume-proof), F38 checkpoint *exactness* (checkpoints stay
as useful tooling for the long video runs), F39 fingerprint binding (fingerprints become recorded
provenance, not preflight), F45 timed restore/RPO/RTO drills (verified backups remain mandatory),
F46 freeze/watchboard, F47 forced-crash recovery, F56 ingress-freeze sweep semantics, F57 closeout
binding (becomes the audit report), F58 window budget, HIR4 custody v2 evidence, HIR5 recovery
evidence v2, HIR8 steps 1, 2, 4, 6–11. F32's per-source accounting survives, but the exit-code
contract changes: under incremental convergence a held residue is a reported state, not failure.

### 2.3 The email gate guards the wrong boundary, and created its own backlog

`OosEmailParserService::planDisposition()` holds any plan below 0.90 self-reported confidence
without two-attempt consensus *outside the service graph entirely*. Measured consequences
(archive-v12, 2026-08-14): 370 of 534 sources held (~69%); 157 of 521 approved identities staged;
~1,100 model calls per measurement round (95% of sources take a corrective second call); and the
confidence score being gated on is weakly calibrated — 78.7% identity-exactness even in the
0.90–1.00 band.

The gate treats *existence of evidence in a staging database* with the risk posture appropriate to
*publication*. Three consequences:

1. **It is structurally self-defeating.** The strongest correctness signal available is
   cross-source agreement — OpenLP proves item count and sequence, the hymn workbook proves song
   membership, livestream observation proves order, and corroboration covers ~72% of email
   identities (HIR-D8). But convergence can only compare sources that were allowed to import, and
   the gate runs first, so the best evidence is never consulted. HIR-D8 is the plans beginning to
   notice this from inside.
2. **It is an outlier in the system's own design.** Every other source's ambiguity is resolved
   *after* ingestion, by projector matching, proposals, automatic finalisation of compatible
   evidence (success criterion 7; B11 removed the performative human gate) and the review inbox.
   Only email uses a model's self-assessment to decide whether evidence may exist.
3. **It sets the weekly pipeline up to fail the same way.** The threshold is shared; a Sunday email
   at 0.85 also sits held instead of converging with that week's OpenLP and livestream evidence.

**REV-D2** re-draws the boundary as three tiers:

- **What may exist unattended widens:** a plan whose *identity* is manifest-corroborated imports as
  source evidence flagged `needs_review`, regardless of content confidence. The residual holds
  shrink to wrong/unknown identity (the 20-entry gate-hold family — and keep the
  manifest-corroboration date gate exactly as is: it caught 8 of 8 date errors with zero false
  passes) plus known-bad extractions (~33 content-invalid plans in archive-v12).
- **What may finalise unattended does not widen yet:** automatic finalisation still requires the
  current bar (≥0.90 + consensus) *or* cross-source agreement via convergence — and widening
  beyond that stays gated on the item-level ground truth (queued item 0) exactly as HIR-D8
  requires. Nothing in REV-D2 pre-empts HIR-D8.
- **What may go public unattended stays zero:** release remains a signed, operator-initiated act
  (FR-D2), and a service still carrying `needs_review` evidence is excluded from release batches.

This is also the durable weekly-pipeline improvement the maintainer asked the import to produce:
weekly emails converge instead of queueing.

Governance note: HIR-D6/HIR-D7's "Axis B" (never change what the importer imports unattended
without a recorded decision) is exactly the control this touches. REV-D2 *is* that recorded
maintainer decision, at evidence level only, with the finalisation and publication tiers
explicitly unchanged.

### 2.4 Effort is inverted relative to content value

The email lane — item lists — has absorbed three full staging re-runs, F48–F58 and F63–F65, and a
queued five-item parser plan. The video lane — the sermons themselves, 1.0 TB across 506
recordings covering 462 service identities — has processed nothing; its curation-manifest producer
(F66) landed only on 2026-08-14. The songs spreadsheet lane, the *sole* source for 888 identities,
is quarantined behind F60 (blocked on convergence) with its date-only half implemented but
unreleased. This review recommended starting video processing in parallel; **REV-D4 keeps it
sequenced** — accepted, with the exit criteria in §6 so "after the email lane settles" is a
checkable state rather than an indefinite deferral.

### 2.5 Plan hygiene is now a cost of its own

Three interlocking documents totalling ~530 KB, three decision numbering schemes (FR-D, HIR-D,
AM-D), findings whose "State: open" headers outlive their fixes (a known drift trap), and an
evidence log that is now the majority of the text. The maintainer's "wood for the trees" instinct
is confirmed by the documents' own shape. The convention in `docs/plans/README.md` — active plans
hold executable sequences only; history lives in git — argues for a consolidation once REV-D1's
fold-back happens.

## 3. What the revised programme keeps as hard gates

1. **F29 quarantine completeness** — every public read, feed, index and direct-asset surface
   excludes quarantined/pre-boundary content. This is now the foundation of the entire safety
   model and deserves its named test suite run as a release gate forever.
2. **Approved manifests as sole mutation authority** (F37/F49/F50) — imports read only
   hash-verified, curated corpora.
3. **Create-only, never-delete object writes** (HIR7/HIR-D1) — unchanged.
4. **Verified pre-apply database backup** — a dump proven restorable once per apply round
   (`spatie/laravel-backup` already scheduled; the round's dump is an explicit step).
5. **Per-lane staging rehearsal** — each lane demonstrates its full staged result and audit
   report on `crockenhill_rehearsal` before its first production round (REV-D3).
6. **Post-apply audit report** — F53-based exact membership and classification accounting after
   every production round; drives the completeness number to 100% across rounds.
7. **Signed release batches, era by era** — content goes public by explicit operator act, and the
   natural release unit is an era slice (move `public_from` back / release the era's sermon batch)
   once that era's convergence and spot-checks satisfy the FR-D4 accuracy floors.
8. **Privacy** — artifact redaction, no raw email bodies in portable payloads, admin-only raw
   evidence. Unchanged.

## 4. Work the decisions create

In order:

1. **Fold-back PR (documents only).** Rewrite the two governing plans' gate/checklist sections to
   the REV-D1/D3 model; mark the retired evidence obligations as lapsed with a pointer to this
   report; archive the superseded forensic detail per `docs/plans/README.md` conventions. Update
   the safety plan's HIR-D6/D7 Axis-B record with REV-D2. Consider collapsing the three documents
   into one execution plan + one decision record while doing so.
2. **REV-D2 implementation.** In `InboundEmailImportService`: manifest-corroborated held plans
   create source records/assertions with a `needs_review` disposition instead of leaving the
   inbound email held; keep the date-corroboration gate and content-invalid holds; exclude
   `needs_review`-bearing services from release eligibility. Red tests first (the corpus numbers
   above are the fixture material). Then one staging re-run to measure the new held residue
   (expected: ~20 identity holds + ~33 content-invalid plans, versus 370 today) and the
   convergence-driven finalisation rate against the 427 staged OpenLP archives.
3. **Item 0 of the queued parser plan** (ground truth + song-catalogue seeding in rehearsal) —
   unchanged and still first among parser items; it now calibrates *finalisation* rather than
   *import*, and it is what HIR-D8 waits on. Items 1–3 are re-scoped by REV-D2: item 1's
   confidence-gate rework is largely superseded; item 2 (item-type-aware review classification)
   gains value because the review inbox becomes the main human surface; item 3 stays optional.
4. **F32 exit-contract change.** The archive command reports per-source dispositions and exits
   successfully when the run itself was clean, with completeness as a reported metric.
5. **Incremental apply loop.** Re-scope `ConvergeHistoricChurchService`'s batch gate from
   "whole approved corpus applicable or refuse" to "apply every applicable service, report the
   rest" — the per-service classification and locking already support this.
6. **Hymn lane.** Unchanged design (F61/F62 closed); F60 regeneration waits for a converged corpus
   as planned; its 888 email-absent identities become convergence input like any other source.
7. **Video lane** — on the §6 trigger: operator adjudicates the curation worksheet
   (`historic-import:draft-video-curation` → `capture-video-curation`), then staged definitive
   processing runs (note the 3-hour full-corpus hash on every `plan()` call when scheduling).

## 5. Risks accepted by the decisions, stated plainly

- **No atomic rollback.** A defective round leaves wrong-but-quarantined rows to fix forward or
  supersede, rather than a provably restored prior state. Accepted because nothing public changes
  and per-service repair is the system's normal operation.
- **Wrong item lists can exist in the graph unreviewed** (REV-D2, single-source services). They
  cannot finalise unattended and cannot release while flagged; the cost is reviewer attention
  later, not public error.
- **Completeness is a trajectory, not a precondition.** The archive can be released era by era
  while earlier eras are still converging. This is the "as if uploaded continually" outcome by
  construction — the site's history simply grows backwards.
- **Less forensic provenance evidence** (custody v2 / recovery v2 obligations lapse). Source
  hashes, manifests and the parse cache still record what was read and produced; what lapses is
  proof-of-process ceremony, per D10's one-person posture.

## 6. Revised sequence (respecting REV-D4)

1. Fold-back PR (§4.1) and the REV-D2 implementation + staging re-run (§4.2), together with item 0
   (§4.3).
2. **Email lane settles** when: the REV-D2 staging run shows holds reduced to the identity/content
   residue; the ~14 genuinely-manual adjudications (6 identity disagreements, 8 date corrections)
   are done; and item 0's ground truth exists. This is the trigger for the video lane.
3. First production round for Email + OpenLP evidence (they are drive-free and staged): pre-round
   backup → incremental apply → audit report → no release yet.
4. Video curation adjudication and staged definitive processing (weeks of wall-clock, unattended),
   then per-era Bundle A promotion rounds as eras complete.
5. Hymn F60 regeneration against the converged corpus; hymn apply through its landed controls.
6. Era-by-era releases as each era passes the FR-D4 accuracy floors on spot-check; the audit
   report's completeness number, not a gate, tells you when the backfill is done.
7. WP10 retirement of the one-shot machinery — now a larger and more satisfying deletion.

## 7. Out of scope for this review

- Editorial/consent/CCLI policy for publishing a decade of services (§14.4 of the remediation
  plan) — still deferred, still real, and still required before large public releases.
- The architectural-maintainability, Sentry, search and design plans — untouched except where the
  plans index's ownership table already coordinates them.
- Any relitigation of FR-D1–FR-D10, HIR-D1–HIR-D7 or the §5 decision record.
