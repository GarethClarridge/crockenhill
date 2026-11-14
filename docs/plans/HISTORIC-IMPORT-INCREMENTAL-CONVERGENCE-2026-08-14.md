# Historic Import: Incremental Convergence Plan

> **Programme handoff — reconciled 2026-11-06:** the November video round and named repair
> sessions are closed. Later fixes and the pending start trial remain in video §§4.1a–c;
> current-output acceptance remains with the audit §7. Detailed execution history is archived.
> Nothing historic has been publicly released. G10–G11/§8.4 decide the audience/content policy;
> production enforcement and each exact release batch still require verification.
> Weekly stays paused until historic completion (G5/R8, reaffirmed 11-06). Minimise manual
> review under G7/Q15; reuse evidence and bound repeated work under G8.
>
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
> **Consolidated 2026-10-29.** Closed execution journals are in the
> [programme evidence snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md); §§0/6/10 carry current work.
> No live census or production verification was performed for this documentation reconciliation.
>
> **Email/OpenLP corpus settlement is complete.** The final expectation is **553 approved sources /
> 539 identities / 20 operator-approved accepted holds**. Both lanes reconcile with zero unstaged
> approved sources, zero unstaged identities and zero unexplained identities. The remaining red
> proposal census is a cross-source convergence result, not an Email/OpenLP corpus defect and not a
> reason to delay the video source that can supply its missing evidence.
>
> **Cannot proceed without a human:** recovery of authoritative source material for any current-era
> services still reported as unevidenced, consequential media ambiguities that evidence cannot
> settle, and every exact release batch. The §8.4 access/content policy was decided 2026-10-17;
> verify it on production rather than asking for the same policy decision again.

## 0. Current execution brief — use this for new work

### 0.1 Current execution sequence — reconciled 2026-11-06

| Order | Slice | Recorded status / next action |
|---|---|---|
| 1 | Email/OpenLP settlement | Completed 08-24 in rehearsal: 553 approved Email sources / 539 identities / 20 approved holds; 614 OpenLP includes. Both lanes reconciled with zero unexplained/unstaged membership. Residual cross-source proposals are not parser corpus defects. |
| 2 | Round authority and corroboration provenance | Completed 08-25/26: exact manifest/plan/operation bindings, resume and notification containment; historic video grade carried end to end. Retain tests; no rebuild owed. |
| 3 | Video calibration and source freeze | Completed; accepted model decisions and manifest revisions are preserved in the [evidence snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md). Do not restart six-service calibration from its old instructions. |
| 4 | Video processing and acceptance | [Video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md) owns the next round and acceptance status; closed November processing does not certify later changed outputs. |
| 5 | Production census and current-era sources | §0.4 owns identity comparison; IC4 owns evidence back-fill on a fresh production count. |
| 6 | Convergence and hymn lane | Re-census after accepted full-grade video, resolve proposal classes and catalogue dependencies, regenerate IC6's exact apply artifact. |
| 7 | Apply, release and closeout | §§7–8 govern each production round and separately signed era release; IC8 follows closure and the repair-route decision. |

### 0.2 Historical execution snapshots

The superseded 08-24 execution table and dated narratives are in the
[programme evidence snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md). They are not execution authority.

### 0.3 Design rule: bulk is only orchestration

Historic-only code may select approved inputs, verify hashes, concatenate declared segments, bind
private staging/operation context, pace dispatch and transport results. It must not reimplement
segmentation, transcription, structure detection, song matching, sermon extraction, analysis,
thumbnailing or review. `HistoricVideoImporter` already calls `UnifiedMediaProcessor::process()`
with type `livestream`, which reaches the same `ProcessingPipelineBuilder::buildLivestreamChainJobs()`
used by the weekly upload. Preserve that seam; the corpus is meant to exercise it.

Do not add Horizon, a second batch framework, a new historic processing pipeline, or a general
audit subsystem for this one-off. Prefer a small command fix and focused regression test. The
round evidence pack may be a reviewed collection of existing JSON reports with their hashes.

### 0.4 Getting production complete: the map (2026-10-16)

**No lane has reached production yet.** Email and OpenLP were settled in a rehearsal database
(row 1), video is still being repaired locally, and rows 6–8 have not started. This machine's
database was built here, not restored from production (operator, 2026-09-08). Production holds
content the local graph has never seen: some 2014–2019 sermons exist only there (operator,
2026-10-16), and so do whatever weekly services were processed there.

**What "done" means.** Production holds every lane's converged state, the eras are released by
signed batches or explicitly held, and weekly processing has taken over. The operator ruled on
2026-10-16 that weekly takes over "whenever historic import is done".
**Reaffirmed 2026-11-06:** the operator rejected early weekly resumption. Weekly stays paused;
the outcome review does not decouple it from historic completion.

**Working definition of the historic import being done** (still to verify at closeout):
- the last historic production round is applied;
- every era is released or explicitly held;
- the weekly-resumption canary has passed on production (video plan §4.2).

| Step | Lane | Production today | Next step | Where |
|---|---|---|---|---|
| 0 | **Production census** | **Measured 2026-10-17** (checksummed export held locally: 704 sermons, 381 of them 2014–2019, 3 services, 41 items; four of the five audits ran). Compared locally 11-14 (`storage/scratch/row6-compare-20261114-wp24/`, unverified by the operator): 347 members create, 76 match a production sermon, none leaves the round; dispositions wait on eight operator questions; the importer cannot yet bind a run to a production sermon. Still owed: a refresh for changes since 10-17, audio hashes, the current song catalogue and a database dump for the rehearsal. Earlier text: never measured against the local graph | Read-only, on the server. The GitHub production-audit channel has never worked: its environment has no secrets. See below. | This section |
| 1 | Deploy and pending actions | Last reconciled 2026-10-16; current production revision and action statuses require a fresh check | Compare the deployed revision and production settings with `PROD-ACTIONS-PENDING.md`; apply each still-relevant action in its order. Speaker identification stays off for historic processing. | [PROD-ACTIONS-PENDING](PROD-ACTIONS-PENDING.md) |
| 2 | Email and OpenLP | Settled in rehearsal on 08-24; 223 services uncorroborated and 135 disagreeing | Re-run membership and the proposal census after the video re-census, settle the two classes, rehearse on a production copy, apply (RG-B) | Rows 1, 8; §7 |
| 3 | Video, 2020-03-22 to 2026-08-23 | Nothing historic there | Accepted runs exported as bundles by era and applied quarantined, then released by signed era batches | Video plan §4.5; §7–§8 |
| 4 | Hymn workbook | Apply artifact stale | Regenerate against the converged corpus and apply once | Row 7 |
| 5 | Current-era evidence back-fill | Based on a production count from 08-09 | `audit:service-evidence-coverage` on production, recover real sources | Row 6 |
| 6 | Legacy sermon transcripts, 2003–2019 | 1 of 808 local legacy sermons has a transcript; the 2014–2019 sermons on production are uncounted | **Decided 2026-10-17**, production-only sermons included; planned, run after the historic import. Membership comes from step 0's export. | Video plan §4.7 |
| 7 | Weekly takes over | Paused since 2026-09-14; never ran consistently on production | Weekly-resumption canary, then the services from 2026-08-30 onward, oldest first | Video plan §4.2 |
| 8 | IC8 retirement | — | Only after the long-term repair route for historic media is decided | Video plan §4.6; IC8 |

**Step 0, the production census.** Run on the server, read-only:
1. Run `audit:sermon-assets`, `audit:section-assets`, `audit:service-evidence-coverage`,
   `service-tracking:audit-convergence` and `service-tracking:audit-source-revision-lineages`.
2. Export production's identities (sermons by date, service and source; services and their items;
   song videos; speaker profiles). This needs a small read-only export command.
3. Compare them with the local graph.

The census decides, for every production row, whether a round will create it, enrich it or leave it
alone. It names the production-only content (the 2014–2019 sermons, weekly services processed
there) that no historic round may overwrite or duplicate.

**The census and one small era rehearsal come before the last video round's freeze (11-10,
video plan §4.1f row 6).** Production's own content can change the round's membership, and the
media export and quarantined apply have never been rehearsed end to end. Also compare the song
catalogues: clips bind local song IDs and an OpenLP merge re-binds nothing. Neither step publishes
or mutates production; the rehearsal runs on a production copy.

Prepare the read-only census early. Agents compare identities and prepare create/enrich/leave-alone
exceptions; verify cross-date/title duplicates, richer incumbent metadata and service/media links.
Rehearse against a production copy before apply. The [audit §0](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md#0-wider-release-confidence-and-who-does-the-checks-2026-10-29)
owns quality/delivery checks and agent-first escalation; this programme owns production authority.

## 1. Goal and value

Backfill the website from the historic content — emailed orders of service, OpenLP archives,
livestream/sermon recordings, and the hymn workbook — so that production reaches the durable state
each service would have if its sources had arrived through the current application at the time.
Members gain the authenticated service history linking talks, songs and Scripture under G10/G11;
visitors retain access to eligible public sermons. Song usage history extends to 2004; historic
sermons gain the current pipeline's transcripts, sections and media.

Two standing requirements shape everything here:

1. **Minimal manual review.** Residual human work is a designed quantity: cross-source
   corroboration and automatic finalisation do the clearing; the operator handles genuine
   ambiguity, era release decisions and spot-checks. Repeated refinement rounds are expected and
   cheap; per-item human adjudication at corpus scale is a design failure.
   **Strengthened 2026-11-06 (G7/Q15):** explicitly minimise manual review, measuring operator
   questions/time alongside substantive omissions, contamination and usable talk/song outputs.
   G8 now requires full corpus comparison with justified repeated stages and reuse of valid
   evidence; refinement rounds are bounded by their purpose and the audit's stopping rule.
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

**Operational reading amended 2026-08-25:** IC1 and IC3 are done, the current Email/OpenLP manifests
reconcile on a clean rehearsal, all 20 accepted holds are operator-approved, and every remaining
invalid/proposal is enumerated. The REV-D4 trigger is therefore **met**. It is a source-lane
settlement trigger, not the final §9 convergence gate: requiring that gate to pass before IC5 would
make video wait for the video evidence needed to clear 223 uncorroborated services. The gate remains
red truthfully until that evidence arrives. Do not force evidence-dependent classes into the
terminal `automated` or `irreducible` statuses; inspect and automate only repeated classes with a
credible shared weekly-path improvement, then re-census after IC5.

### 2.5 HIR-D8 — cross-source corroboration may finalise unattended

**Binding decisions, consolidated 2026-10-29; no policy change.**

- **08-19:** independent cross-source corroboration may replace the 0.90 model-confidence
  threshold for unattended finalisation only for the dimension proved. OpenLP proves planned
  song membership/count/order; the hymn workbook proves actual membership, never count/order.
  Source absence is neutral; disagreement routes the affected dimension to review. Identity/date
  manifest gates and the existing confidence-plus-consensus route for uncorroborated dimensions
  remain. Adjudication never manufactures `consensus`; finalisation never authorises publication.
- **08-24/25:** a complete livestream can corroborate all three song dimensions. For historic
  video, only manifest grade `full` qualifies; `short_partial`, `fragmented`, `unknown` and missing
  grades stay neutral. Grade propagation through importer metadata and source provenance was
  completed 08-25; the old description of it being absent is superseded. This historic qualification
  does not change ordinary weekly behaviour. `full` is a recorded grade, not proof that every
  planned song was actually recorded: acceptance and performed/planned reconciliation still apply.
- **08-25:** retain membership, count and order verdicts for diagnosis, but report unique
  proposals/services as review load. Dimension totals overlap; they are not three independent
  failures or three operator decisions.
- **08-21:** resolve historic song identity in `ChurchServiceAssertionNormalizer`, not merely at
  comparison time. Strong identity must repair projection grouping as well as corroboration;
  loosening comparison alone could clear review on duplicated/mis-paired items.
- **Accepted consequences:** catalogue changes are versioned reprojection events under
  `policyVersion`, changing assertions/projection hashes and invalidating affected approvals and
  portable bundles. Invariant 4 (§3.2) explicitly permits this derivation while retaining source
  provenance. Some sources helped build the catalogue, so key-resolved agreement is partially
  circular: accuracy reporting must distinguish it from literal-title independent agreement.

The diagnostic replays, corrected interpretations and evidence supporting these decisions are in
[the programme evidence snapshot §2.5](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md#25-hir-d8--cross-source-corroboration-may-finalise-unattended).
The withdrawn “29 automatically converged” claim and intermediate “decision stays open” text are
historical, not competing policy. IC3 owns the final current-corpus convergence certificate.

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

  A round declaring more than one source kind is certified from **one artifact per lane**, because
  each is a hash-locked derivation of exactly one approved manifest and there is one manifest per
  lane. So an `email,openlp` round runs `oos:generate-corpus-expectation` *and*
  `openlp:generate-corpus-expectation`, and passes both to `services:proposal-census
  --expectation=<email> --expectation=<openlp>`. A declared kind with no artifact is
  `expectation_source_kind_unapproved`: the Email reconciliation is never read as covering OpenLP.
  Membership goes the other way — one certificate spans every lane, so
  `services:generate-corpus-membership --source=email --batch-hash=<email> --source=openlp
  --batch-hash=<openlp>` produces the single artifact `--membership=` takes. Note that the corpus
  size the gate reports is the **union** of the lanes' approved identities, not their sum: both
  lanes describe the same services from different evidence.

  **Sequencing rule (2026-08-25):** this final convergence gate is expected to remain red while an
  approved later source is still needed to resolve a proposal. A red gate still prevents RG-B for
  the affected converged service state, but it does not undo a source-lane settlement trigger such
  as REV-D4 or forbid staging the named next source. The Email/OpenLP rehearsal has 223 services in
  exactly that state awaiting video. `source_item_projection_stale` is truthful until their
  proposals settle; do not suppress it or invent a terminal class status to make the gate pass.
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
| **Kept as open work** | F60 (IC6), current-era back-fill (IC4, after remeasurement), post-video convergence census, video corroboration-grade propagation, video manifest population and resumable operation binding (IC5) |
| **Reframed as report** | F32 (per-source accounting; exit contract changes in IC2), F53 (exact membership), F57 (round audit completeness), per-round cost/throughput accounting (was F58's measurement half) |
| **Closed with evidence** | F1 completeness (Email lane, 2026-08-16; evidence in the programme snapshot), F2, F3, F4, F42, F43, F46's guard code, F61, F62, F63, F64, F65, F66; B1–B21 (all repaired; red tests retained); HIR0–HIR7 landed |
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
`--accepted-holds`, on FR-D9's fail-closed-with-reasons pattern. **The OpenLP lane now has its
producer.** The populated v2 curation fields in batch `openlp-curated-2026-08-23` feed
`OpenLpApprovedCorpus`, which derives the expectation from the approved OpenLP manifest alone.
The combined census accepts one hash-locked expectation per lane, so its `email,openlp` round can
now certify all 614 staged OpenLP identities alongside Email.

G0–G9 as a ladder is retired; their still-live content maps to RG-A/RG-B/RG-C and §3.2. The
archived per-gate audit table's open items are absorbed as follows: G1/PR5's manifest-field
population → IC5; G2 census/completeness → RG-A; G3 round trips → closed; G5 rehearsal → RG-A per
lane; G7/G8 window approvals → RG-B per round; G9 → historic closeout.

## 5. Corpus facts and evidence regimes

Locations (recounted 2026-08-24): Email at `storage/scratch/oos` + `oos-verbatim`, authority batch
`oos-curated-2026-08-22-additional-services` (557 entries: 554 include, 3 exclude; 540 distinct
primary identities and 665 identities when declared additional services are included); OpenLP at
`storage/scratch/ServiceRecords`, authority batch `openlp-curated-2026-08-23` (655 entries: 614
include, 36 duplicate-of, 5 exclude); video at `/Volumes/Sonnics/Services` (1.0 TB, 649 supported
recording files grouped into 462 identities: 347 morning, 115 evening). The old 506-file and
`_Rejected/` descriptions predate the mounted corpus's current layout and are retired. The hymn
workbook crosstabs cover 2004–2018 + 2023 (5,759 known-service occurrences over 1,306 identities;
888 identities have no other source; date-only lane implemented and quarantined).

**Eras are defined by which sources survive.** Do not reconcile the opaque historic pairs
`193/220` or `538/662`; their source manifests have moved. Before a release decision, derive each
era denominator afresh from the exact approved Email, OpenLP, video and hymn artifacts used by that
round and retain the producing report.

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

The executable order is §0. IC1's evidence-tier behaviour and IC3's shared semantic-parser,
ground-truth and corroboration work are complete. IC2's per-service convergence semantics landed,
but its claim that every production lane was hash-bound was too broad; §0 slice 2 owns the remaining
portable Email, OpenLP and video command boundaries. Do not buy another whole-corpus model run.

### IC1 — Email evidence-tier import — completed

Implemented and rehearsal-settled; §0.1 records the final expectation. The full parser/cache/hold
investigation and test evidence are in the [programme snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md). §2.2 remains binding.

### IC2 — Incremental apply semantics — completed

Core and command authority bindings are complete (08-26), including portable Email, OpenLP and
video operation identity. The old “command bindings still open” heading is superseded by §0.1's
completion evidence. Production application still requires §7.

### IC3 — Item-level ground truth and convergence — corpus settled; final convergence open

Email/OpenLP clean rehearsal completed 08-24; no restart of the 15-item parser investigation is owed.
Regenerate combined membership and the proposal census after accepted video and catalogue curation.
The dated residual was 398 proposals / 21 classes, chiefly 223 uncorroborated services and 135
with disagreements. These are a baseline, not current counts. Do not classify an evidence-dependent
class as terminal merely to clear the gate. Apply §2.5's dimension-specific evidence rules and §3's
predeclared accuracy/lineage gates; resolve surviving classes or explicitly retain them.

Reconcile era denominators before release (the older 193/220 and 538/662 disagreement is not solved
by copying a table). Cache caution remains: a parser-code fix does not prove cached extractions were
refreshed; inspect behaviour and provenance, not a rewritten resolve timestamp. Targeted fresh parses
can regress, so retain compared evidence; do not authorise a full paid reparse by inference.

### IC4 — Current-era evidence back-fill (drive-free; any time)

The 08-09 count of three services / 32 items is historical. Run current production evidence
coverage first; recover authoritative sources for every still-unevidenced service, ingest via the
ordinary source-revision path and reproject. Never manufacture evidence. Agents perform the audit
and ingest preparation; the operator supplies genuinely unavailable source material.

### IC5 — Video and OpenLP completion

OpenLP curation, video calibration and the original full-manifest dispatch are completed evidence,
not current work. Their exact hashes, model rulings and experiments are retained in the
[programme snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md). Later local rounds and output acceptance are owned solely by
the [video worklist](HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md); use its current membership and the runbook, never the original
470-identity list. Per-dispatched-file size/hash verification remains mandatory; corpus-wide
verification is separately explicit, not inferred from a bounded pass.

After accepted staging: regenerate membership and proposals, then export exact Bundle A membership
per era. Only `full` video with applicable independent evidence may corroborate the dimensions
allowed by §2.5; the workbook proves song membership, never count/order. Keep video re-detection
independent of uncorroborated Email (operator 09-25). Re-evaluate disagreements, including performed
versus planned songs, before declaring residue irreducible. Report per-era accuracy and evidence basis.

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

### Legacy transcript follow-up (decided 2026-10-17)

**Operator, 2026-10-17:** do it, and include the sermons that exist only on production. Plan it
now, build later.

**Scope.** Every published sermon from before the historic video era (before 2020-03-22) that has
audio and no transcript:
- **2003–2013:** 808 local rows (494 hours, audio only); 1 has a transcript.
  - 807 have a named preacher and 685 a Scripture reference.
  - Their audio is on this machine (`storage/app/public/sermons/audio`, 2.0 GB).
- **2014–2019:** exist only on production; uncounted.

**Production is the record.** This machine's database was built here, not restored from production
(§0.4), so local sermon ids do not match production's, even for 2003–2013.
- **Membership:** read from production by the census export (§0.4, step 0). For each
  sermon, record its production id, slug, date, service, audio path, audio size and SHA-256, and
  whether it has a transcript.
- **Identity:** every later step keys on (production sermon id, audio SHA-256), never on a local row.

**Not touched:** titles, preachers, references, series, publication state and audio (register P4).
No structure detection, cutting, AI titles or summaries.

**One transcription step, shared with direct uploads** (G9; no historic-only behaviour). A legacy
sermon is a direct audio upload that already exists.
- **Today's direct path** (`TranscribeAudio`; video plan §4.2) returns untimed prose with no loop screening.
- **The step:** build one step that transcribes a sermon's audio with local Whisper
  `large-v3-turbo`, `max_context=0` and timed cues.
  - It keeps the cues as an artifact and runs `ServiceTranscriptRepetitionScreen`, then
    `ServiceTranscriptRepetitionRecovery`. Both already take a transcript, not a run.
  - It writes the markdown transcript through `TranscriptStorageService`.
- **Who uses it:** new direct uploads use it before their AI step; a legacy sermon uses it with no AI
  step.
- **What it closes:** the video plan §4.2's direct-upload safeguard item, which is open because the direct path
  cannot be screened.
- **One format change:** the sermon page and `ProcessTranscriptWithAI` still read markdown; only the
  cue artifact is new.

**Route.**
1. **Build, test-first,** on master after the historic import's rounds (any time; it touches no
   round):
   - the shared step;
   - `sermons:transcribe-legacy`, which reads the membership file and is resumable, with a verdict
     per sermon that survives re-runs;
   - a transcript-only promotion bundle and its production importer.
2. **Audio.**
   - **2003–2013:** use the local copy only where its SHA-256 matches production's (restore only if
     byte-identical).
   - **2014–2019, and any mismatch:** download from production storage into a holding directory on
     an internal disk, hashing each file.
3. **Sample before the run.**
   - Time about 10 sermons on host Whisper.
   - Listen to about 20 for level problems; audio treatment only if they show some.
   - Read about 10 transcripts for quality, including names and places.
   - The timing gives the full run's length: 494 hours plus 2014–2019, likely days.
4. **Run, after the historic import is done** (weekly's take-over first; Whisper is the rounds'
   bottleneck, and the run must not share the drives with a cut).
   - Local only, no paid calls.
   - A sermon still flagged by the screen after recovery is held, not shipped, and listed for the
     operator.
5. **Promote,** as a production round (§0.4, step 6). The bundle carries, for each
   sermon: production id, slug, audio SHA-256, the transcript, the cue artifact and provenance
   (code revision, model, decoding options). The importer on production:
   - **dry-runs first**;
   - writes only where the sermon still has no transcript and its audio SHA-256 matches;
   - stores under the production id;
   - changes no other field. `SermonObserver` clears the sermon's cached metadata.
   - **Rollback:** delete what the bundle's ledger wrote.
6. **Afterwards:** search (site search and the Scripture search plans) can use the transcripts.
   They appear on public sermon pages, as the sermon archive is public (G11).

**Tests:**
- the shared step on a direct upload and on an existing sermon, with no AI step for the latter;
- screen and recovery on a looping fixture;
- resumability;
- the importer refusing on a hash mismatch, on an existing transcript and on an unknown id, and
  changing no other field.

**Open before the run:**
- the production count for 2014–2019 (the census);
- whether production's audio for 2003–2013 matches the local copy;
- the sample's timing.


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

### 7.3 Round evidence pack (replaces exact closeout)

The earlier plan promised one aggregate command that does not exist. Do not build a general audit
framework for a one-off import. Assemble and hash the existing lane report, manifest expectation,
combined membership/census, asset audit (for media), Scripture settlement, operation notification/
nested-job ledger and cost/duration output. Add a short cover record naming the round, commit,
operation id, manifest/plan hashes, backup receipt and every non-zero residue. Store it in the
private batch ledger and have the maintainer review it. An unexplained identity, changed input hash,
unowned asset or external historic notification is a defect; an enumerated hold/pending proposal is
reported recoverable residue.

**Operation 4 closeout residue (recorded 09-19; closure not established by this reconciliation).**
The verifier core exists, but its real backup receipt and `HISTORIC_IMPORT_EVIDENCE_SIGNING_KEY`
were still required. Rebuild the seven reports from `storage/scratch/operation-4-round-evidence/`
on the converged state, verify the expected 474-item cover against actual operation membership,
review every disposition/residue, sign and run the verifier before applying its closeout transition.
Neither receipt nor key may be inferred. This is specific outstanding operation evidence, not a
new general framework or permission to release.

## 8. Release policy

1. **Unit: era slice** (§5 table). Service eras become available to verified signed-in users by moving `church.services.public_from`
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
4. **Release-policy decisions (operator, 2026-10-17):**
   - The service archive, including its listing and individual service pages, is authenticated-only.
     It is not a public visitor feature. Service links, search and sitemap exposure must follow
     that boundary. The separate sermon archive remains public. This supersedes the public-service
     exposure assumptions in §8.1–§8.2; an era threshold never grants anonymous service access.
   - Songs are eligible for authenticated access only, permanently. They must never become public;
     an era release must preserve that restriction, including direct media access.
   - Do not release standalone testimonies, prayer requests or prayers, or private occasions, by
     default. Any exception needs an explicit operator decision for the named content.
   - Prayers already within a sermon's agreed extraction boundaries remain part of that sermon.
     This policy does not change the cut rules or require re-cutting those prayers out.
   - Retain reviewed, explicitly approved release batches; preserve curated sermon metadata and
     verify uncertain speakers and references. Unresolved quality or identity cases stay held.
   - Apply the church's existing policy when reviewing identifiable children or sensitive material.
     Corrections and takedowns use ordinary tools and preserve provenance.
   These decisions settle the content/access policy; they do not authorise any release batch or
   establish copyright/CCLI licence coverage. Verify applicable permissions and actual access
   enforcement before release. No production settings or publication state changed with this ruling.
5. **Production enforcement verification remains open.** Local implementation was recorded 10-17;
   that dated report is not proof of today's deployment, bucket policy or CDN behaviour. Compare the
   actual revision/settings with [pending production actions](PROD-ACTIONS-PENDING.md), then verify:
   - authenticated/verified service pages, intended destination after login, guest navigation and
     sitemap exclusion; regenerate the cached sitemap after the applicable deployment;
   - `public_from` as an era bound for signed-in users only;
   - gated song players, private objects, signed cloud URLs and local range/private-cache handling;
   - existing-object ACLs, bucket policy and logged-out direct/CDN reachability. The existing
     `songs:privatise-videos --disk=do_spaces` dry run prepares any separately approved ACL apply;
     an ACL check alone cannot certify bucket/CDN privacy;
   - outstanding visual/browser and full-suite evidence on the actual release revision, without
     treating the 10-17 focused test totals as current proof.
   Codex/Claude perform supported checks under the [audit procedure](HISTORIC-VIDEO-POST-ROUND-AUDIT-2026-10-22.md); only unresolved
   questions and reserved release/mutation decisions go to the operator. Before release, rehearse a
   small era and complete the audit's cross-surface, feed, old-link and partial-recording checks;
   repeat representative object-identity/playback checks at the destination in the observation window.
   Use the existing rollback process if destination acceptance fails. No release is authorised here.

The former pre-implementation baseline, step-by-step build list and local validation results are
in the [programme evidence snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md#8-release-policy). They are historical evidence,
not a second rollout procedure. Items 1–4 above remain the release policy.

## 9. Review-load design (carried)

The archived §9.4 automate-first loop is unchanged and now serves staging rounds: run the proposal
census per round (`services:proposal-census --gate` semantics with corpus-completeness evidence);
classify proposal *classes*; automate a class away where a deterministic rule exists (rule-level
dispositions with `decision_rule_version`); hand humans only irreducible ambiguity. Evidence-tier
imports (IC1) enter the census, not the weekly attention inbox. The weekly inbox keeps its
narrower `needs_review` semantics on purpose.

**Nonterminal evidence dependencies (2026-08-25).** The persisted class-review statuses
`automated` and `irreducible` are terminal. A class waiting for a named, approved later source is
neither. Record that dependency in this plan and leave the class unclassified until the source is
staged and the census is regenerated. Do not add an `awaiting_evidence` status merely to advance
sequencing: if such a status is later added for UI clarity, the gate must list it separately,
require a reason naming the dependency, and remain red while any such class exists. An unknown
status currently falls through the gate's terminal accounting, so adding the constant alone would
be unsafe. REV-D4 independently permits the named next source to stage while this gate is red.

## 10. Open decisions and operator inputs

| Outstanding item | Owner / disposition |
|---|---|
| Fresh production census and final cross-source proposals | Agents, §0.4/IC3/IC5. Recount rather than reuse 223/135; operator receives only surviving irreducible questions. |
| Missing current-era sources | IC4: agent identifies exact gaps; operator supplies unavailable evidence. |
| Duplicate catalogue songs and alternative titles | [Song curation](SONG-TITLE-CURATION-AND-OPENLP-SYNC-2026-09-22.md) owns changes; verify its current status before IC5 re-census/IC6 and reproject on changed policy. Old 19-group/138-title counts are not current tasks. |
| Hymn artifact and era denominator reconciliation | IC3/IC6; bind exact converged membership and account for every row before apply. |
| Production enforcement and exact release signatures | §8; policy is decided, verification and batch authority remain. |
| Repair route, source custody and weekly take-over | Video §§4.2/4.6 before IC8; legacy transcript work follows take-over. |
| Historic VAD proposal and unobservable-song yield | **Revalidation needed, not an approved rule.** The 08-28 proposed inference “no VAD speech means no singing” was never acceptance proof. Check current recovery/corroboration behaviour and actual content before considering a truth-rule change; do not revive it as an automatic implementation task. Measure present neutral evidence, not the six-case calibration's 2/6 extrapolation. |
| 08-27 processing-log test-isolation leak | **Closure not established in this review.** Reproduce the named `tests/Unit/Services/` + `ImportHistoricVideoBatchCommandTest` interaction on the current tree before treating it as a defect; no blanket waiver of current quality gates. |

**Reconciled closures:** the six-service calibration, model adoption, original curation/freeze and
round bindings are completed in §0.1, despite still appearing “open” in the old table. The 207-class
interpretation was retracted: 398 proposals formed 21 dimension-based classes in the dated census.
No framing/asset exemption is owed. Closed investigations and the original caveats remain in the
[evidence snapshot](../archived-plans/HISTORIC-PROGRAMME-EXECUTION-RECORD-THROUGH-2026-10-29.md#10-open-decisions-and-operator-inputs).

Maintain this table in place. Store execution narratives in dated evidence, not below a contradictory
“only these rows are open” table. Do not turn an unverified old issue into a current blocker or a pass.

## 11. Permanent vs disposable surface

Permanent (invest freely): shared parsers/projector/review, §3.2 invariant enforcement, the
canary/red-test suites, the public archive, the audit-report command, quarantine/release seam.
Disposable at closeout (name the trigger in every new addition, per the carried safety invariant):
historic commands, staging guards/contexts, bundles and transfer, checkpoints/journal, ingress
locks, release ledger, production guard. Do not grow the disposable surface except where an IC
package requires it.
