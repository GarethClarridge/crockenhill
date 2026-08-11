# Historic Archive Final Import Readiness Plan

> **Status (investigation complete through 2026-08-08; expanded Email corpus re-inventoried 2026-08-09): NO-GO. Do not connect
> the archive drive for an
> import run and do not perform any production historic mutation.** Connecting it read-only for the
> inventory work described here is not the import run, but even that should wait until the source-
> handling protocol and destination capacity are agreed.
>
> This document is the remaining-work and final go/no-go layer over
> [Historic Archive Import Readiness Remediation](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md).
> It does not replace that plan's technical contracts, gates G0-G9, or work-package detail. The
> remediation plan remains the implementation record; this plan closes gaps found by auditing the
> complete path from an unmounted drive to public production, including business decisions and
> operational evidence that the remediation plan intentionally deferred.
>
> **Investigation record:** findings were added here as their evidence was verified. Every finding
> below is an open gate unless a later dated entry explicitly closes it.

## 1. Current verdict

The project is **not ready for its one-time import**.

The existing plan itself records that the rehearsal has not started, G2-G9 are unclaimed, the
mounted source inventory and manifests are incomplete, a clean rehearsal database has not been
provisioned, deterministic promotion has no measured production-window budget, and the exact
different-database/no-op/rollback exercises have not run. F1 was decided on 2026-08-09 and its
exact-membership implementation landed with F53; the remaining §5 business decisions were all taken
on 2026-08-11 and are recorded in §3. Deciding them closes Phase 0, not the operation: every one
still needs its implementation and its rehearsal, production or operator evidence.

This audit found thirty-one additional findings, F29-F59; twenty-nine remain blockers after the
maintainer's 2026-08-08 scope decision accepted F42-F43. They span source and manifest integrity,
Email/convergence correctness, false-success command semantics, ephemeral staging and resume state,
checkpoint/recovery, unbound processing environments, event/notification containment, exact corpus
and closeout binding, restore/change control, access boundaries and missing service identity. Most
urgently, the application cannot
guarantee that imported historic sermons remain private: the service-archive date gate does not
gate the sermon archive, podcast, sitemap, or ordinary sermon audio/transcript delivery. That
contradicts the governing business assumption that import must not expand the audience; with the
current read paths, production import can itself publish.

The 2026-08-09 local Email inventory also superseded the old data authority: the approved
404-entry manifest still claims 402 verbatim files, while the roots now contain 533 verbatim and
261 formatted files, leaving 131 non-empty verbatim files unaccounted for. The current
reconciliation target is 535 root-level manifest entries (259 paired, 274 verbatim-only and 2
formatted-only) before identity, exclusion, partial-order or supersession decisions. The old
391-identity F1 baseline and its rehearsal measurements are historical; re-curation, replacement
hashes and a new service-identity baseline must precede Email staging or F1.

This verdict changes only when every item in the final go/no-go checklist is evidenced. A green
test suite or a successful dry run is necessary but not sufficient.

## 2. Relationship to existing plans and runbooks

Use the documents in this order:

1. **This plan** decides whether the complete one-time operation is ready and records the remaining
   technical, operational and business gates.
2. The [readiness-remediation plan](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md) defines the
   implementation contracts, G0-G9, Bundle A/B semantics, rehearsal loop and exact closeout.
3. [Historic Media Acquisition and Result Promotion](../archived-plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
   and [R8 Data Convergence Correctness](../archived-plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md) are archived prior-art
   decision records only; their work-package sequences are superseded.
4. [`docs/operations/r8-data-convergence-runbook.md`](../operations/r8-data-convergence-runbook.md)
   is intended to be the sole production runbook, but is **not executable as written**. It still
   hard-codes the superseded OpenLP 536/105/3/428/7 accounting, requires a completed Manual review
   for every multi-source service where automatic finalisation is now valid, omits the current
   operation-id/expiry requirements from its apply example, and does not encode the newer
   G2-G8 evidence gates. Replacing it with a command-exact, rehearsed runbook is work in this plan.

No operator should assemble a sequence by combining steps from these documents at the prompt.

## 3. Evidence log

### 2026-08-07 — baseline and carried blockers

- Repository state inspected at `d9aebe1a0` (`master`, one commit ahead of `origin/master`, clean
  worktree at the start of this audit).
- The remediation plan's header and definition of done confirm that the rehearsal has not started,
  G2-G9 are unclaimed, and most operation-level acceptance boxes remain open.
- F1 was open at this baseline: the 2026-08-07 approved Email snapshot yielded 391 curated `(date, service)` identities, but
  up to 35 documents in that historical snapshot may validly produce another service. The current integer
  `historic_corpus.expected_services` cannot reconcile that without the rule and PR26 described in
  remediation plan sections 9.4.6, 17 and 19.
- The approval-gated production evidence audit has never reached production because the
  `production-audit` environment lacks `PROD_HOST`, `PROD_USER` and `PROD_SSH_KEY`. Production's
  retained-evidence population and the required current-era disposition are therefore unknown.
- The CBC drive is unmounted. OpenLP re-inventory, v3 manifest population/approval, symlink
  materialisation, video inventory/manifest approval, byte totals and source hashes are unverified.
- The sole production runbook predates material changes in the remediation plan and is internally
  stale. It cannot be the authority for a once-only operation until rewritten and executed verbatim
  in rehearsal.

### 2026-08-09 — manual current-era evidence measurement

- The operator ran the read-only `audit:service-evidence-coverage --json --details` command directly
  on production. It found 3 church services, no source records of any kind, 32 canonical items on
  unevidenced services, and no proposals. The individual service IDs are deliberately not recorded
  in this plan.
- **Maintainer decision:** back-fill retained evidence for all three services. §12.4 current-era
  re-projection remains blocked until the authoritative source material for each service has been
  recovered and ingested through the normal source-revision path with provenance and hash coverage.
  Do not infer or manufacture evidence from the existing canonical items. Then re-project and audit
  the complete three-service corpus; exclusion and legacy acceptance are not permitted.
- **Maintainer decision, 2026-08-09:** manual SSH audits are the accepted permanent operational
  path. The `production-audit` environment does not need the three production credentials. Repeat
  the read-only audit manually after the evidence back-fill and re-projection.

### 2026-08-09 — expanded local Email corpus

- The current local roots contain **533 non-empty `oos-verbatim` files** and **261 `oos` files**.
  The previously approved manifest has **404 entries** and claims **402 verbatim files**, so **131
  non-empty raw files are unmanifested**.
- Reconciliation currently measures **259 paired**, **274 verbatim-only** and **2 formatted-only**
  files, requiring **535 root-level manifest entries** before curation decisions. Every new file
  still needs an explicit include/exclude decision with a reason where excluded.
- The additions span **2014-08-31 through 2026-08-09**, mostly 2015–2021, and include current-era,
  partial, revised and multi-service material. The historic/current operation boundary and
  `(date, service)` identities therefore need to be re-established from the replacement manifest.
- No import or dry run was performed. The old manifest/plan hashes and the old 391/219/391/56%
  measurements remain historical evidence for the unchanged snapshot only; they are not valid
  authority for the expanded roots.

### 2026-08-09 — replacement Email authority and F1 decision

- The expanded roots were reconciled and approved as 535 included entries: 259 paired, 274
  verbatim-only and 2 formatted-only. All three current-era entries are included. The approved set
  contains 521 distinct `(date, service)` identities and preserves all 404 prior decisions.
- The promoted manifest validates with 0 identity disagreements. Manifest hash:
  `928dccb5620fc3422d4c067ebc004a9ab4fbfebdf39a881d41baf8a250823e83`; plan hash:
  `ebf486c1f5d0b927944c78af51ebf2976d557bbade2be9da0a70358a4418618a`.
  **Superseded 2026-08-11 by D1's re-curation:** these two hashes and the 535-included count are
  historical evidence for the pre-D1 manifest only. The current authority is batch
  `oos-curated-2026-08-11`, manifest `f4b6b833…ee013`, plan `03d40e46…2de8c1`, 534 included and 1
  excluded. See the 2026-08-11 D1 entry below.
- **F1 decision:** certify the exact approved 521-identity baseline and allow additional identities
  only where each is explicitly explained by `service_beyond_manifest`; unexplained excess fails.
  F53 remains the implementation owner because global scalar counts cannot prove exact membership.

### 2026-08-09 — date-only historic hymn usage

- The reconciliation workbook contains 1,941 song/date statements without morning/evening service
  identity. It also establishes that these rows are partial reported-sung evidence, not complete
  service orders.
- The application now has a separate `song_usage_reports` evidence path. It leaves the canonical
  `(date, service)` identity and `SermonService` enum unchanged, displays `Service not recorded`,
  and does not produce a public service URL for an ambiguous row.
- The read-only command baseline against the current catalogue is exactly 1,941 rows: 1,867
  catalogue-resolved and 74 retained unmatched. The exact rehearsal/apply/no-op/rollback commands
  and abort counts are recorded in remediation plan §13.5 under “Date-only historic song usage
  import”.
- This implementation does **not** change the plan's NO-GO production verdict. The `--import` mode
  remains subject to the production backup, deploy freeze, rehearsal, witness and approval gates;
  a successful local dry run is not production authority.

### 2026-08-10 — implementation batch and its review

A large implementation batch landed against F29-F58. It is **implementation evidence only**: no gate
below is closed by it, because every one of those findings also requires rehearsal, production or
operator evidence that this batch cannot produce.

**What was implemented.** Six commits (`6e861bd85` date-only song usage, `cb8d873dd` F53 exact corpus
membership, `b4006d1b8` F30 Email supersession lineage, `22bc9ee3b` the operation/checkpoint/journal
schema, `332c754a8` F31/F34/F37/F49/F50/F55 source integrity, `ceaadb103` F35/F38/F39/F47/F52 durable
runtime) plus a working-tree batch covering F29 sermon quarantine, F40/F41/F51 convergence, F44
editorial facts, F45/F46 approval and freeze, F56 operation-scoped ingress deferral, F57 exact
closeout and F58 window measurement.

**Review findings, all fixed on the same tree.** The batch had not been run against any of the four
quality gates. Four defects were load-bearing:

- `AuditChurchServiceConvergenceCommand` did not parse — an unescaped apostrophe in `$signature`.
  Laravel silently omits a command whose file cannot load, so `service-tracking:audit-convergence`
  was **absent from `artisan list`** with no error, and the F57 closeout path had no entry point.
- `HistoricConvergenceCloseout::binding()` called `->filter('is_string')`; `Collection::filter`
  passes `($value, $key)`, so every exact-audit closeout died with `ArgumentCountError`.
- `SermonExposurePolicy::isWholeContentPublic()` read `publication_state` as a model attribute while
  `SitemapService` and the admin sermon list selected explicit column lists that omitted it. The
  unloaded attribute read as "not published", so the generated sitemap emitted every sermon `<url>`
  with no `<loc>`. The policy now throws `MissingExposureAttribute` on a persisted row whose deciding
  column was not selected, rather than silently withholding published content.
- `HistoricMediaGraphPersister::verifyExisting()` recomputed section and run-level destinations
  without the `historic-import/{operation_id}/` prefix the apply allocates, so an operation-scoped
  no-op rerun reclassified every already-present service as a blocked difference. Retained as a
  red-to-green regression test.

**Three F29 surfaces were still open and are now closed in code.** Scripture facets
(`sermon_scripture_filters` fed the public book filter list and `SitemapService::addBooks()` with no
publication predicate); `Song::displayVideo()` (a quarantined historic recording with a later
`recorded_date`, or the bundle's own `is_featured`, became a song's public video); and the
normal-output contract, which did not classify `publication_state`, `asset_disk` or
`historic_import_operation_id`. `song_videos` now carries the same publication state as sermons.
The audience decision is deliberately **excluded** from the portable contract: an apply always lands
quarantined on the destination's own private disk, so no exported bundle can dictate what the
destination publishes.

**Gates on this tree:** `artisan test --parallel` green, `composer phpstan` clean, `pint --dirty`
applied. That is necessary and not sufficient, exactly as §1 states.

**Still open, unchanged by this batch:** every rehearsal, production-evidence, manifest-approval,
source-acquisition and operator item. Specifically noted for the next session:

- `CHURCH_SERVICES_PUBLIC_FROM` is now load-bearing and fails closed. `.env.example`, `phpunit.xml`
  and `.env.dusk.ci` set an early bound so the archive behaves as it does today; **production must be
  set explicitly before this deploys** or the public service archive goes dark. The maintainer's
  2026-08-10 decision is an early bound, which means the import's audience boundary rests on
  `publication_state` rather than on the service date gate.
- ~~F44 editorial facts are carried into `historic_import` processing metadata but nothing yet applies
  them to a sermon's title/occasion/speaker/scripture/series. The plumbing exists; the consumer does
  not.~~ **Implemented 2026-08-11; see the entry below.** The rehearsal proof remains unrun.
- ~~`HistoricSermonPublicationService::release()` has no operator command and no batch gate.~~
  **Implemented 2026-08-10; see the entry below.** The exercise itself remains unrun.
- ~~`HistoricImportMutationFreeze` blocks every save and delete on eleven core models with an
  uncaught `RuntimeException`; the failure mode in a web request or queue job is a 500, not a
  refusal.~~ **Decided and implemented 2026-08-10; see the entry below.**

### 2026-08-10 — F29 release command and batch gate

`CHURCH_SERVICES_PUBLIC_FROM` was set in production before this work, so the quarantine batch can
deploy without darkening the public service archive.

**What was implemented.** `historic-import:release-batch` is the operator entry point F29's
"separately authorised release exercise" requires, backed by a new
`HistoricSermonReleaseAuthorisation` gate and a `HistoricSermonPublicationService::releaseRecords()`
batch path. Release is deliberately not a mode of any import command.

**Why the gate is separate rather than a second use of the import approval.**
`HistoricImportProductionGuard` requires the operation to hold the measured ingress/queue freeze,
while `HistoricImportOperationCloseout` refuses to complete an operation whose ingress window is
still open. Release runs after closeout, so the import approval can never authorise it — the
separation F29 asks for is a structural consequence, not a stylistic choice. The release artifact is
therefore its own signed document, gated on `HistoricImportOperationState::Complete`, which is
exactly the state that proves the exact audit and complete no-op rerun passed.

**What the gate requires**, all fail-closed with zero effects: HMAC signature over the canonical
document; an operation in `Complete`; target fingerprint matching both the operation and the
currently resolved target; the deployed release identifier; an unexpired window; a rollback owner
whose observation period outlasts that window; three distinct named people (release owner,
independent verifier, rollback owner); and exact enumerated membership — every named sermon and song
video must exist, belong to that operation and still be quarantined. Declared counts are carried
independently of the id lists so a truncated artifact cannot release a smaller batch than the one
that was signed. `--dry-run` verifies everything and publishes nothing.

**A defect in the existing quarantine work, found and fixed here.** `release()` walked only the four
sermon asset fields. `SongVideo` has no per-record disk and `SongVideoService::getVideoUrl()` always
builds a **sermon-disk** URL, so a released song video would have advertised a public URL for bytes
that only exist on the private quarantine disk. Song videos now travel with their operation's
sermons: bytes hash-verified onto the public disk, state flipped under the same lock, both journalled.

Asset copying is create-only and compensated as one batch — a single missing or unsafe asset aborts
the whole release and deletes only objects this run created, never a pre-existing identical target.
The command journals `release_batch_started` before and `release_batch_completed` after, so an
interrupted release is visible as started-without-completed rather than as silence, and it records
the exact remaining quarantined counts for the operation.

**Gates:** `artisan test --parallel` green (6338 tests), `composer phpstan` clean, `pint --dirty`
applied, `artisan dusk` green (55 tests). The closeout gate was verified red-to-green by disabling it
and watching the refusal test fail. Two `SermonPagesTest` archive assertions failed on one full-suite
run and passed on a re-run of the same tree, with clean `master` green; that is the known intermittent
cross-test interaction, and the new test now flushes the shared cache in `setUp` as its sibling does.

**Still open:** the release exercise itself. F29 requires it run in a production-shaped rehearsal —
quarantined item and all assets returning the agreed non-public behaviour, then an approved item
becoming public without re-importing or changing provenance. No such rehearsal has happened, and no
signed release authorisation exists. This entry closes no gate.

### 2026-08-10 — F46 freeze failure mode decided

**Maintainer decision:** the freeze presents as a clear 503 refusal, and the one scheduled task that
writes a frozen model skips while the freeze is on. The queue is deliberately left alone.

**Measured blast radius before deciding.** The freeze hooks `saving`/`deleting` on eleven models and
is inert only in the process that called `authorize()`. Three surfaces were exposed:

- *Admin web requests* — the real problem. Seventeen admin Livewire components touch those models; an
  operator mid-window got an uncaught exception, and with error tracking uninstalled (F46) the only
  trace was a rotating log. A deliberate freeze was indistinguishable from an outage.
- *The scheduler* — **one** task, not three as first assumed. `media:cleanup-unpublished-section-assets`
  transitions `ServiceSection` publication status. `media:cleanup-temp-files`, `scripture:refresh-passages`,
  `calendar:sync` and `sitemap:generate` are read-only on frozen models, and `model:prune` is scoped to
  two health models.
- *Queue jobs* — smaller than it looks. `HorizonPauseAccounting` records that `supervisor-media` serves
  `default` in the same strict-priority list, so the approval's required supervisor pause stops
  ordinary background work too. In a correctly run window jobs accumulate rather than fail, so no
  queue-layer change was made.

**What was implemented.** `HistoricImportFrozen` carries the operation id and window end and renders
503 with `Retry-After` for both JSON and HTML; the 503 view shows the reason in place of its generic
maintenance copy. An elapsed window advertises **no** retry delay, because expiry does not lift the
freeze — removing the artifact does. An unreadable approval still refuses, just without naming the
operation: an approval that cannot be read is a reason to keep refusing.

The cleanup task's skip predicate now also consults the freeze. Its existing predicate was the ingress
gate alone, but F46 lifts the freeze only after exact audit, smoke and queue/scheduler recovery, so the
freeze deliberately outlives the ingress release — leaving a window in which that task ran and failed.

**Known limitation, asserted by test rather than left implicit.** Several API controllers wrap their
work in a broad `catch (\Exception)` — `MediaController::upload()` is the worked example — which
swallows the refusal and returns their own error shape instead of the 503. The security-relevant
property still holds and is tested: the write is blocked and the response is not a 500. What is lost
there is the operator-facing message. Narrowing those catches is ordinary follow-up, not an import gate.

**Gates:** `artisan test --parallel` green (6346 tests), `composer phpstan` clean, `pint --dirty`
applied, `artisan dusk` green (55 tests). This entry closes no gate: F46 still requires the freeze,
approval binding, watchboard and two-person control to pass a production-shaped rehearsal.

### 2026-08-11 — F44 consumer implemented; two plan claims corrected

**Two items this plan listed as open were already done.** Verifying before building found that
F59's portable Scripture Passage contract is implemented (`HistoricNormalOutputContract` carries
`scripture_passage` as a natural key, `HistoricMediaGraphPersister::resolveScripturePassageId()`
relinks it, `HistoricProcessingResultReadinessService` gates export on settled outcomes) — the
2026-08-10 batch entry simply never mentioned it. F44's same-day identity collision is likewise
already guarded in both manifests: `OosCurationManifest`'s `leavesByService` permits at most one
active non-superseded full order per `(date, service)`, and `HistoricVideoCurationManifest::
validateServiceIdentities()` rejects any two included entries sharing that identity.

**Maintainer decision, 2026-08-11:** confirm the fail-closed guard as the answer to F44's identity
question. No `occasion` column and no widening of `UNIQUE (date, service)`. The measured basis: of
the approved Email manifest's 14 colliding identities, ten are supersession chains and four are
complementary partials — **zero** are two distinct same-day events. A schema change would be
speculative; the guard converts the still-unknown video case into a refusal during curation.

**What was implemented.** The curated `editorial_facts` block stopped at
`MediaProcessingLog.metadata.historic_import.editorial_facts`: nothing applied it to a sermon, and
`HistoricProcessingMetadataSerializer::portableHistoricImport()` is an allowlist of `tag`,
`concatenation`, `codec_fingerprint` and `sources`, so the facts were **stripped at the Bundle A
boundary** and never left the local machine. Now: a typed `HistoricEditorialFacts` value object is
exposed on `ProcessingMetadata`; `SermonCreationOptions` carries it from all four factories and
`SermonCreationService` gives it precedence over ID3 and AI for title, series and scripture
reference, with a curated speaker resolving through the existing explicit-preacher branch as
`PreacherSource::Manual`; and `editorial_facts` is now portable, so it reaches the destination's
processing log.

Two scoping rules are asserted by test rather than left implicit. Curated facts describe the
service's sermon, so `curatedFacts()` withholds them from a children's talk extracted from the same
recording, and a curated speaker never displaces a reviewed children's-talk speaker. `occasion` is
deliberately carried as provenance only — it has no column on any model, which is precisely why
losing it at the bundle boundary was unrecoverable.

**Gates:** `artisan test --parallel` green (6357 tests), `composer phpstan` clean, `pint --dirty`
applied, `artisan dusk` green (55 tests). The serializer test was verified red-to-green by disabling
the allowlist entry. **This entry closes no gate:** F44 also requires the same-day identity ruling to
survive video manifest curation against the real drive, and the curated facts to be proven end to end
in the production-shaped rehearsal.

**Curation defect found, not fixed.** `2026-06-21-am-revised` carries no `supersedes` while all ten
other revision entries do. Its source is a `RE:` reply from `pastor@crockenhill.org` reproducing
Laurie's order with one line changed (`Hymn (Mark to choose)` → `Hymn 868`), and its own curation note
says so. It is a correction chain, not a complementary partial. Both entries are `content_scope:
partial`, which is why `leavesByService` did not catch it — that guard counts only `full` leaves. The
entry appears identically in the 404-entry `approved-2026-08-06` manifest, so the defect predates the
expansion and was carried forward by the preserve-prior-decisions rule. Left unfixed, F30 links
nothing for this service and the superseded placeholder survives into projection alongside hymn 868.
**Re-curating it invalidates the recorded manifest and plan hashes in §3, so it needs an explicit
maintainer decision.** The other three non-superseding collisions (`2017-06-11`, `2019-11-17`,
`2020-02-16`) were checked and are genuinely complementary — different senders, different subjects,
disjoint content.

### 2026-08-11 — the nine remaining §5 decisions taken

Every open row in §5 was worked through with the maintainer and decided. **This entry closes no
gate**: each decision still needs its implementation and its rehearsal, production or operator
evidence. Two rows turned out to be already decided and are corrected below.

**Two §5 rows were stale, not open.** F1 was decided 2026-08-09 and is implemented —
`service_beyond_manifest` is live in `OosArchiveEvaluator` and `ChurchServiceCorpusMembership`
carries F53's exact-membership check. The current-era disposition was decided the same day
(back-fill all three services). Both rows still carried a "No-go" default. Corrected in §5.

**D1 — `2026-06-21-am-revised`: exclude the predecessor, do not build a supersession.**
The plan's proposed fix does not validate. `OosCurationManifest` refuses a `supersedes` on a
`partial` entry and refuses to supersede a `partial` (both guards at the same loop), and **both**
2026-06-21 entries are `content_scope: partial`. Adding the link therefore also requires promoting
both to `full` — which the sources do not support: compared with a genuine `full` entry
(`2015-06-07`: welcome and notices, call to worship, opening prayer, children's talk, closing
prayer), the 2026-06-21 pair carries hymns, Prayers, Bible Reading, Sermon and Communion Hymn only.
The curator's `partial` call was right, and `OosArchiveEntry` documents that a full order's silence
is read as disagreement — so promoting it would make projection assert those items were
authoritatively absent.

Decision: flip `2026-06-21-am` to `disposition: exclude` with the reason already recorded in the
revised entry's curation note. The revised document reproduces Laurie's order verbatim with one line
changed (`Hymn (Mark to choose)` → `Hymn 868 'Guide me, O my Great Redeemer'`), so it is a strict
content superset and nothing is lost. Both entries stay `partial`, so the survivor still routes to
review via `partial_source_scope` rather than importing unattended. Re-hash and re-approve: this
invalidates manifest `928dccb5…` and plan `ebf486c1…`. **Applied 2026-08-11** — see the D1 entry
below for the replacement batch key, hashes and counts.

Measured correction to the finding above: the placeholder does **not** reach projection unattended.
`ImportOosArchiveCommand::reviewReasons()` flags every partial, so both entries land in the review
queue. The guard is a human, not a machine — which is why the entry is excluded at curation rather
than left for a reviewer to catch across 521 identities.

**D2 — imported sermons end `Published`, released in signed batches.** Same visibility as existing
material, which is what §4.B's audience-preservation rule actually requires: all 832 existing sermons
are public. Release stays a separately authorised `historic-import:release-batch` exercise, never a
side effect of import. `SermonPublicationState` remains binary; no third state is introduced.

Measured before deciding: the podcast feed cannot flood — `SermonBuilder::forPodcast()` is
`orderBy('date','desc')` with `limit(config('podcast.items_limit'))` = 100, so no historic sermon
ever enters it. There is no search index (no Scout, no indexing jobs), so bulk writes fan out
nowhere. Children's talks stay private through the existing `CHILDRENS_TALKS_PUBLIC=false` default,
honoured by `whereVisibleInSitemap()`. The one real effect is ~500 new sitemap `<loc>` entries,
spread across release batches.

**D3 — ratify F59's relink-only contract, and add a pre-apply enrichment pass and preflight.**
`HistoricMediaGraphPersister::resolveScripturePassageId()` is unchanged: it relinks by
`(bible_id, normalized_reference)` and refuses anything else, and the bundle keeps carrying the
natural key only — never `html_content`, `copyright` or the per-fetch `fums_token`.

The gap this exposes is production's, not F59's. Locally there are **18 `scripture_passages` rows
against 709 sermons carrying a reference, with 16 linked**; the same enrichment gap is recorded for
production. As written the apply would throw partway through. Required before the window: run
enrichment in production across the exact reference set the bundle carries, then preflight every
required key and **fail with zero writes** if one is missing, rather than throwing mid-apply. Watch
the known api.bible cross-chapter reference mangling during that pass.

**D4 — thresholds split precision from recall.** A wrong asserted fact goes public and is
unrecoverable; a missing fact is honest and Phase 6 editorial follow-up fixes it without re-importing
or touching source provenance. They are therefore not gated symmetrically.

Precision floors, **batch stops below**: date/service 1.00; preacher, scripture reference, song link
and sermon/children's-talk boundary each ≥ 0.98. Recall floors, **route to review below**: item
recall and item order ≥ 0.85; title/series presence ≥ 0.70. Existing gates unchanged — auto-import
0.90, review 0.75 — on the evidence of the 2026-07 eval, where calibration was 34/34 correct in the
importable bands and 0/66 wrongly promoted from the held band. Exact identity, hashes, accounting,
supersession and the visibility boundary remain 100% and non-waivable, as §4.F.2 already states.
Truth set: 50 services minimum, stratified per §4.F.1, maintainer adjudicating, disagreements
resolved against the verbatim source rather than the parse.

**D5 — `maximum_import_ingress_blocked_minutes` = 480.** The maintainer accepted an eight-hour cap
against a recommendation of 240, to complete the operation in the fewest windows and avoid repeating
the full approval/freeze/backup/closeout ceremony. `HistoricPromotionBudget` takes this as its
accepted-cap input and derives the applying budget from it after preflight, closeout and rollback
reserves, stopping admission at `MINIMUM_ADMISSION_FLOOR_MINUTES` = 15.

`HistoricImportCheckpointPlanner`'s existing bounds are ratified unchanged: `MaxItems` = 25,
`MaxForecastSeconds` = 43,200. Rollback triggers: any hard-invariant violation aborts and rolls back;
any asset-transfer verification failure aborts the batch; more than 2% of admitted services failing
apply stops admission and goes to closeout.

The eight-hour choice makes D7's controls more load-bearing, not less — an operator is alert for a
shorter time than the window is open, and the swallowed-503 limitation recorded on 2026-08-10 already
degrades operator-facing signal.

**D6 — pre-window backup verified by an actual restore; RTO 30 minutes.** Take a
transaction-consistent dump immediately before the window with SHA-256 and a table/row manifest, hold
it **outside the `backup:clean` rotation**, and restore it into disposable MySQL and time it before
the window opens. The measured database is **30.2 MB**, so the drill costs minutes and a 30-minute
RTO is honest rather than aspirational. RPO is everything up to the freeze with zero loss: production
is frozen, and F56 makes the deferred ingress outbox durable and exactly-once.

Two facts that shaped this. F45's object-rollback half is **already satisfied**:
`HistoricProcessingResultAssetTransfer::copyToDestinations()` confines every write to
`historic-import/{operation_id}/` via `assertOperationOwnedProductionPath()`, hash-verifies existing
targets instead of overwriting, and compensates only paths that run created — which is exactly F45's
permitted "immutable operation-owned keys" alternative to conditional/ETag semantics. No bucket
versioning is required. Second, `keep_all_backups_for_days` is 7 and `backup:clean` runs daily at
01:00, so the pre-window backup is pruned on day 8 unless it is lifted out of the rotation. Sermon
media (~200GB in Spaces) stays excluded from backup; asset rollback depends on the operation-owned-key
cleanup path instead.

**D7 — required reviewers on the `production` environment; log-only operation record formally
accepted.** `deploy.yml` triggers on `push: branches: [master]` and its deploy job already declares
`environment: production`, so required reviewers is a settings change that directly prevents the worst
mid-window failure: a master merge redeploying production and killing in-flight Horizon jobs during an
eight-hour frozen window.

The maintainer **formally accepts rotating logs as the operation record** and declines to install
Sentry before this operation, against a recommendation to install it. F46 permits "Sentry or a
formally accepted live alternative"; this is that acceptance and must be recorded in the operation
artifact rather than left silent. The accepted consequence: for the duration of the window a real
outage and a deliberate 503 freeze are not readily distinguishable, and the broad
`catch (\Exception)` in some API controllers still swallows the refusal's operator-facing message.

**D8 — retention tiered by corpus kind; the video corpus is not retained.** F36's "two verified
protected copies" is an acquisition-and-processing requirement so the sole copy is never processed;
it does not imply permanent duplicate retention. Retention therefore splits by size:

- **Permanent, encrypted, two independent locations, named owner** (megabytes): the OpenLP `.osz`
  archives, the Email corpus (`oos` + `oos-verbatim`), and every manifest, hash, fingerprint, journal,
  inventory and report.
- **Video (unmeasured, plausibly ~1TB+)**: two copies during the operation as F36 requires; the
  original stays read-only and is **returned to the church** on signed acceptance; the working copies
  are **deleted once the exact audit and public smoke pass** — an evidence trigger, not a calendar
  one, per the no-calendar-time-gates rule.
- **Recorded assumption:** raw-video preservation then rests on the church's own drive. If F36's
  acquisition health check shows that drive is their only copy, raise it with the church as a separate
  preservation matter. It is not an import gate.

**D9 — fail-closed adjudication over the whole corpus.** Include every recording with an unambiguous,
hash-verified `(date, service)` identity. Exclude — always with a written reason — anything whose
identity is ambiguous or undeterminable, and any non-identical near-duplicate (one included, the other
excluded). Byte-identical duplicates resolve to a single included target. A recording spanning two
services is excluded unless the split is explicitly adjudicated. Scope is the **whole drive**:
services already present in production are curated in and reconciled against the existing record
rather than skipped, so the manifest accounts for everything. Anything unresolved defaults to
exclude with no processing.

F37's strict schema is already in place and enforces the machine half:
`HistoricVideoCurationManifest` is version 3 with a required `batch_key`, exact-key validation,
ISO-8601 `decided_at`, `approved_rule_version`, and `SUPPORTED_EXTENSIONS` covering
`avi, mkv, mov, mp4, webm` — closing F36's AVI/WebM blind spot. Its completeness sweep fails on any
unmanifested candidate recording while deliberately skipping OS metadata scattered by macOS and
Windows. F36's separate whole-filesystem inventory, which must dispose of every path including
unsupported extensions and links, remains outstanding.

**Work these decisions create.** Items 1 and 2 are done; the rest is not:

1. ~~Edit the manifest for D1, revalidate, re-record both hashes in §3, re-approve (D1).~~
   **Done 2026-08-11; see the entry below.**
2. ~~Build the pre-apply production Scripture enrichment pass and the fail-with-zero-writes preflight
   over the bundle's exact reference set (D3).~~ **Built 2026-08-11; see the entry below. Running the
   pass against production remains outstanding.**
3. Record `maximum_import_ingress_blocked_minutes = 480` as the accepted budget input and the three
   rollback triggers in the operation artifact (D5).
4. Write the pre-window backup and restore-drill procedure into the runbook, including lifting the
   artifact out of the `backup:clean` rotation (D6).
5. Enable required reviewers on the GitHub `production` environment, and record the log-only
   monitoring acceptance in the operation artifact (D7).
6. Carry the D4 thresholds into the truth-set design and the calibration acceptance report (D4).
7. Carry D8's retention and custody terms and D9's adjudication rule into the F36 acquisition
   procedure before the drive is connected (D8, D9).

### 2026-08-11 — D1 applied: the Email manifest is re-curated, re-hashed and re-approved

`2026-06-21-am` is now `disposition: exclude`, carrying the written reason D9 requires: the revised
entry is a strict content superset from `pastor@crockenhill.org` that reproduces Laurie's order with
`Hymn (Mark to choose)` resolved to `Hymn 868 'Guide me, O my Great Redeemer'`, and both entries are
`content_scope: partial`, which `OosCurationManifest` forbids on either side of a `supersedes` link.

**The replacement authority.** Batch key `oos-curated-2026-08-11` (the prior re-curation set the
precedent that a changed approved content set takes a new key, so two different manifests never share
a batch identity):

| | Pre-D1 | Post-D1 |
|---|---|---|
| batch key | `oos-expanded-2026-08-09` | `oos-curated-2026-08-11` |
| manifest hash | `928dccb5…823e83` | `f4b6b83336ef4956ff6b4feabaecde5e4de945172f592f0b29ccab3fe70ee013` |
| plan hash | `ebf486c1…18618a` | `03d40e46f96949277dbaeab86879670a0a383b954b27cb072f9012b9032de8c1` |
| entries / included / excluded | 535 / 535 / 0 | 535 / 534 / 1 |
| distinct `(date, service)` identities | 521 | 521 |

**F1's 521-identity baseline is unchanged by design**, because the surviving revised entry keeps the
`2026-06-21 / morning` identity. The reconciliation target is still 535 root-level entries — every
file remains accounted for; one is now accounted for as an exclusion rather than an inclusion. The
whole-corpus counts are otherwise unmoved: 465 full, 69 partial, 10 superseded, 9 inferred-date, 274
verbatim-only, 2 formatted-only. Ten supersession chains remain, confirming that the excluded entry
was the only correction chain not expressed as one.

**Both exclusion guards were proven, not assumed.** This manifest had never carried an exclusion, so
`validateNotImported()` had never executed against this data. Restoring `content_scope` on the
excluded entry fails with *"contradictory disposition fields: content_scope applies to includes
only"*; removing `exclusion_reason` fails with *"Excluded entry 2026-06-21-am must declare
exclusion_reason"*. The promoted manifest then validates clean with **0 adjudicated source
disagreements**.

**Documents re-pointed at the new authority:** this plan's §3 and §4.A, the readiness-remediation
plan's header, §13.1 record and G1 acceptance row, and `docs/plans/README.md`. The pre-D1 hashes are
retained as historical evidence wherever they were recorded, marked superseded rather than deleted.

**This entry closes no gate.** It makes the Email authority correct and re-approvable; F1's
certification, F30's supersession lineage and G2 still require their rehearsal evidence against this
manifest, none of which has run.

### 2026-08-11 — D3 built: Scripture preflight and pre-apply enrichment pass

**The defect, reproduced before it was fixed.** With the new preflight disabled, an apply carrying a
publication whose passage the destination lacks reaches
`HistoricMediaGraphPersister::resolveScripturePassageId()` and dies with *"Historic publication
scripture passage is not available in the destination"* — after that service's run, steps, segments
and sections are already written, and in a batch after earlier services have already committed.
That is exactly what D3 describes, and **no test covered the relink at all** before this work: no
HistoricMedia test referenced `scripture_passage`, which is why the throw had never surfaced.

**The preflight.** `ConvergeHistoricChurchService::assertPlanApplicable()` now collects every
publication's passage identity across the whole plan and refuses the operation before the first
write, naming every missing key at once so a single enrichment pass can close them all. That method
already existed for precisely this class of defect — its docblock says a preflight admitting more
than the persisters accept "would still abort, but only after earlier services in the batch had
already committed" — so the scripture check belongs there rather than in a gate of its own.

**One canonical read, deliberately.** `HistoricScripturePassageRequirements::keyFor()` is now the
only implementation of "what passage identity does this publication require", and the persister calls
it too. A second copy in the preflight would have been F49's "verification and consumption use
different reads" defect in a new place. `missing()` likewise asks for each key with the query the
persister runs rather than fetching in bulk and diffing in PHP: under a case-insensitive collation
MySQL matches references PHP would call different, which is F55's source-key divergence arrived at
from the other direction.

**The pass.** `historic-import:enrich-scripture-passages {bundle}` reports what the destination
cannot satisfy and exits non-zero, which is the gate the runbook runs before the window; `--apply`
fetches exactly the identities the bundle carries. It refuses to run with `API_BIBLE_ENABLED=false`
rather than reporting every identity as an unremarkable `not_found` and exiting as though the pass
had happened. Enrichment was sermon-driven and unusable here — the destination's sermons do not exist
until the apply creates them — so `ScriptureOperatorService::ensurePassage()` was extracted as a
passage-only path that `enrichSermon()` now also uses, sharing the fetch, sanitize, validate and
persist steps rather than copying them.

F59's relink-only contract is untouched, as D3 ratified: the bundle still carries the natural key
only, never `html_content`, `copyright` or the per-fetch `fums_token`.

**Gates:** `artisan test --parallel` green (6372 tests, 81,362 assertions), `composer phpstan` clean,
`pint --dirty` applied, `artisan dusk` green (55 tests). The preflight was verified red-to-green by
disabling it and watching the apply fall through to the mid-apply throw.

**This entry closes no gate.** The pass has not been run against production, which is the actual
remedy for the 18-rows-against-709-references gap; local measurement, the rehearsal proof and the
api.bible cross-chapter watch during a real pass all remain outstanding.

### 2026-08-11 — the clean rehearsal database is provisioned; F2 re-measured

**The measurement F2 demanded, rerun against the current authority.** The 219-of-391 figure was for
the superseded 404-entry manifest. Against `oos-curated-2026-08-11`, the working database holds 408
services, 2,743 items and **one** source record in total, and **231 of the corpus's 521 identities
already hold items with no normalized evidence** — 44%, down from 56% only because the denominator
grew. Every one of the 231 that exists locally holds items. The §9.4 census's largest class would
still have been the July 2026 OpenLP import rather than the projector, so this was not a formality.

The same run re-verified the approved Email authority end to end: `oos:import-archive --dry-run`
reproduces manifest `f4b6b833…ee013` and plan `03d40e46…2de8c1` exactly, with 534 approved entries,
521 identities, 0 adjudicated identity disagreements and D1's whole-corpus counts unmoved. (The
manifest *file's* raw SHA-256 is a different value and always was — the recorded hash is
`CanonicalJson::hash()` over normalized entries, not file bytes.)

**What was implemented.** `historic-import:provision-rehearsal-database` drops, rebuilds and
certifies the database §13.5 step 3 stages into, backed by `RehearsalDatabaseProvisioner` and a new
`rehearsal` connection. It is a reset command rather than a bootstrap, because §9.4 is a loop and
each iteration wants a database no previous iteration has touched.

**Two things had to be discovered rather than assumed.**

- *Laravel resolves the stored schema dump by connection name.* `MigrateCommand::schemaPath()` builds
  `database/schema/{connection}-schema.sql`, so migrating a `rehearsal` connection looks for
  `rehearsal-schema.sql`, silently finds nothing, and migrates from empty — which cannot build this
  schema at all, since the migration set was pruned and no migration on disk creates a base table.
  The command pins `--schema-path` to the default connection's dump, and a test asserts it.
- *The application user cannot create databases.* Its grants cover only the databases named in
  `docker/mysql/create-testing-database.sh`, which runs once when the MySQL volume is initialised.
  The script now grants `{database}\_rehearsal%` for new environments; existing volumes need the
  one-off root `GRANT`, which the provisioner prints verbatim in its failure message.

**The guard set, which is most of the class.** This is the only command in the workstream that drops
a database. It refuses when the shell resolves the production target — reusing
`HistoricImportProductionGuard::guardsCurrentEnvironment()` rather than a bare `APP_ENV` check,
because the case that loses data is a development shell pointed at production, not a process that
knows it is production; when the name is not a plain identifier, since a database name cannot be a
bound parameter and reaches DDL by interpolation; when the target *is* the working database, checked
before the naming rule because an operator who has already repointed `DB_DATABASE` satisfies the
naming rule and would still be dropping the database underneath their own shell; when the name is not
rehearsal-named; and when the base schema dump is absent. Certification then proves the result holds
no canonical or evidence rows — which is also what catches `DB_REHEARSAL_DATABASE` pointed at a
database somebody else has already staged into.

**Proof it unblocks the lane, not just that it runs.** Same corpus, same guard, same 521 identities:
`UnevidencedCanonicalItemGuard` refuses against the working database (231 of 521) and passes against
the provisioned one. The working-database guard was verified red-to-green by disabling it.

**Gates:** `artisan test --parallel` green (6385 tests, 81,388 assertions), `composer phpstan` clean,
`pint --dirty` applied, `artisan dusk` green (55 tests).

**This entry closes no gate.** It removes the last drive-free precondition on §13.5 steps 3–4; the
staging run, the census and G5 have not happened. Step 12's production-shaped database with
deliberately different primary keys is a separate artifact and is not built by this command.

**Three §4.A items were stale, not open**, found by checking the tree before building — the same
failure mode recorded on 2026-08-11 for F59 and F44. Item 2's PR26 is implemented as
`ChurchServiceCorpusMembership` with the census command's `--membership` option (`cb8d873dd`); item
3's F30 landed in `b4006d1b8` with direct-import and bundle lineage tests; and item 8's
"configure the approval-gated production audit environment" was superseded on 2026-08-09, when the
maintainer accepted manual SSH audits as the permanent path and the audit had already been run. All
three are struck below.

### 2026-08-11 — first full Email staging run: refused by its own closeout

§13.5 step 3 ran for the first time, over the complete approved corpus, into the freshly provisioned
and certified rehearsal database. **It exited 1.** F32's closeout guard refused with *"Approved OoS
corpus closeout is incomplete; no definitive operation can report success"*, because three entries
errored. That is the guard doing exactly its job, and it is the correct outcome to record: the run
processed all 534 entries and still must not be called a success.

**What staged.** 534 `inbound_emails` (every approved entry reached the parser), 99
`church_service_source_records`, 98 `church_services`, 1,213 `church_service_items`, **0**
`church_service_merge_proposals`. Dispositions: 458 `held_for_review`, 72 `created`, 2
`import_failed`, 1 `failed`, 1 `merged`. Report:
`storage/scratch/rehearsal-staging-2026-08-11.json`.

**Three distinct defects, each needing its own fix:**

- `2018-09-23` — `SQLSTATE[22001]: Data too long for column 'canonical_identity'`. A schema/data
  defect, not a parse one; some composed identity exceeds its column.
- `2020-03-29` — *"Failed to decode OoS email parser response as JSON."* Extractor robustness; needs
  a retry/repair path or a recorded terminal disposition, not a silent loss.
- `2026-03-15-am-second-hand` — *"The declared Email predecessor is absent from this church
  service."* An F30 supersession-lineage case the manifest declares and the staged corpus cannot
  satisfy. This is curation-adjacent and may be a second instance of the D1 class.

**F1 holds against real data, for the first time.** 98 canonical services were created, of which 68
carry an approved manifest identity and **30 do not** — every one of them an `evening` service.
All 30 are explained by an entry flagged `service_beyond_manifest`, and **zero are unexplained**, so
F1's rule ("additional identities only where explicitly explained; unexplained excess fails")
passes. This is the ordinary shape the flag was written for: one Sunday email carrying both that
morning's and that evening's order. `parse_flags` totals across the corpus: `low_confidence` 398,
`service_beyond_manifest` 175, `date_mismatch` 150, `invalid_service` 69, `empty_items` 7.

**The finding that matters most for step 4: there is almost nothing to census.** Zero proposals were
raised, and only 99 of 534 entries produced normalized evidence — 458 are parked in the review inbox
below the 0.90 auto-import threshold. §13.5 step 4 projects the staged corpus and converges the §9.4
census over it; with 86% of the corpus held rather than projected, the census would describe 98
services and the automate-first loop would have no proposal population to work against. The review
load §9.4 exists to design down is now measurable at full scale, and it is 458 entries.

**Calibration, read carefully.** Date accuracy 71.7% (full 76.1%, partial 42.0%) and auto-import
precision 73.0% both measure the *parser's unaided* extraction against the manifest, not the
correctness of what was imported: §7.3 makes the manifest authoritative for identity, and the
verified 68/30 split above is the actual identity outcome. These are the live weekly pipeline's
calibration numbers, and against D4's floors they are poor — but D4's floors apply to asserted
facts, and this run asserts the manifest's identity, not the parser's. Confidence calibration is
0.90–1.00: 172 entries at 0.75 accuracy; 0.75–0.89: 163 at 0.687; 0.50–0.74: 247 at 0.186;
0.00–0.49: 38 at 0.184.

**A reporting trap found while reading the report.** Every entry carries both `flags` and
`parse_flags`. `flags` is populated only for `source_updated_after_import`, so across this run it was
empty on all 534 entries while `parse_flags` held everything — which is exactly how it was misread
here at first. The two near-synonymous keys should be merged or renamed.

**This entry closes no gate.** Step 3 is not complete — it exited 1, three entries errored and 458
are unprojected. G5 is not claimable, and step 4 should not start until the held population is
either automated down or explicitly re-scoped.

### 2026-08-11 — the three staging defects fixed

Each was reproduced by a failing test before it was fixed, and each turned out to be a general
defect rather than a property of the entry that exposed it.

**1. Unbounded parser output into bounded columns (`2018-09-23`).** The source is a conversational
note, not an order of service — *"Peter – I have 2 videos from YouTube for my WWUTT talk…"* — and the
parser read one 265-character line as an item title. `canonical_identity` is composed as
`{type}:{normalized title}#{occurrence}` and stored in a `varchar(255)`, so the insert failed after
the service's run had already begun writing.

Fixed in two places, because there are two distinct overruns. `ChurchServiceProjector::boundedIdentity()`
bounds the identity to 200 characters, keeping a readable prefix and appending a digest of the whole
value — truncation alone would let two long titles sharing a prefix collapse into one identity and
project as the same item, which is worse than the crash. Hashing the whole input keeps it stable
across runs, which identity matching between revisions depends on.
`ChurchServiceAssertionNormalizer::boundedText()` then bounds `title`, `source_title`,
`normalized_title`, `scripture_reference` and `normalized_scripture_key` to their own 255-character
columns; that is the adjacent crash the same corpus would have reached with a slightly longer line,
and the normalizer is the one place every source's items pass through.

**2. A transient extractor failure lost a service permanently (`2020-03-29`).** Re-running the same
input parsed it cleanly, so the failure was not deterministic. Measuring the same 49-line email three
times returned 991, 1,081 and 1,743 output tokens against a hard-coded 3,000 budget — a spread wide
enough that identical requests will occasionally truncate, and a `json_schema` response format means
truncation is essentially the only ordinary way decoding fails.

Three changes. The extractor now retries a bounded number of times
(`service-tracking.email_parsing.extraction_attempts`, default 3), because re-asking is the remedy
the evidence supports; configuration faults are raised before the loop so they cannot be retried. A
`finish_reason` of `length` now raises a message naming the budget instead of the undiagnosable
"failed to decode". And the budget itself is configurable, defaulting to 6,000.

**3. Correction chains were not admitted as a unit (`2026-03-15-am-second-hand`).** The predecessor
parsed at 0.85 and was held for review, so it never received a source record; the correction parsed
at 0.92, imported, and `IngestChurchServiceSourceRevision` refused it because the record it declares
it supersedes was absent. The confidence gate and the supersession contract were simply independent,
and nothing reconciled them — so this would recur anywhere in the corpus's ten chains where member
confidences straddle 0.90.

`reviewReasons()` now holds a superseding entry whose predecessor this run has not imported, under
the reason `superseded_predecessor_not_imported`. Holding the successor was chosen over importing the
predecessor regardless of confidence, which would let the auto-import bar be bypassed by attaching a
correction to a held entry. A correction chain is one editorial decision, so it is now reviewed as
one.

**A behaviour change worth noting.** The retry altered what three existing extractor tests exercised:
they supply one unusable response and expect a throw, and the retry consumed the next queued fake.
They now pin `extraction_attempts` to 1, because they are about how a single response is validated,
not about re-asking.

**Gates:** `artisan test --parallel` green (6,392 tests, 81,413 assertions), `composer phpstan`
clean, `pint --dirty` passed, `artisan dusk` green (55 tests). The identity bound and the
supersession hold were each verified red-to-green by disabling them.

**This entry closes no gate.** The fixes are untested against the corpus itself: the staging run has
not been repeated, so neither the three entries nor the 458-entry held population has moved.

### 2026-08-08 — continuation audit scope and completion

The first audit's technical, operational and business agents exhausted their usage after delivering
their incremental findings but before final exhaustive syntheses. F29-F47 retain those verified
findings. This continuation completed the unexhausted surfaces: every public/API/search/cache/feed/
notification path; every Email/OpenLP/video/Bundle A/B branch and nested job; schema/identity/
collision behavior; and static production/runtime/runbook assumptions. It added F48-F59. F42-F43
remain accepted and non-blocking under the maintainer's existing-audience/existing-processing
decision. Production state and the unmounted drive remain evidence gates, not facts this code-only
continuation can invent.

### 2026-08-07 — F29: production import can publish historic sermons immediately

**State: open; G8 blocker; newly discovered outside the remediation plan's implementation list.**

The governing business decision is that the material already exists under the project's existing
authority and access arrangements, so import does not trigger a fresh rights or consent review. The
technical requirement is narrower: import must not expand its audience. That boundary is not
currently enforced for sermons:

- `config/church.php`'s `services.public_from` lower bound applies to the public **service** archive.
- That service cutoff is itself fail-open when unset/empty, and `CHURCH_SERVICES_PUBLIC_FROM` is
  absent from the checked environment/deployment examples; any song or Bible item can otherwise
  make a historic service eligible for its public index/detail.
- `SermonRepository::basePublicSermonQuery()` has no whole-sermon publication or historic-
  era predicate.
- `PodcastFeedService` consumes that public query, and `SitemapService` includes slugged sermons.
- ordinary sermon audio and transcript delivery is not governed by the service date gate; the
  current exposure policy primarily governs video/thumbnail quality and children's-talk handling.
- the configured Spaces sermon disk is public/CDN-backed, so route authorization alone cannot
  revoke an object URL that has already been revealed.

A Bundle A apply that creates a historic sermon can therefore expose its page, audio, transcript,
podcast entry and sitemap URL even while its parent service remains hidden. A video-quality override
does not quarantine the sermon as a whole.

**Required outcome:** add one fail-closed, whole-content publication state/policy that can quarantine
an imported batch or era before any production write. The default for historic imports is private.
Every read surface must consume the same decision: sermon index/detail and canonical metadata,
podcast/RSS, sitemap, service-to-sermon and song-to-media links, audio/video/thumbnail/transcript
controllers, storage/CDN URLs, search/related-content jobs, and any API/resource surface. Direct
asset access must not bypass it: quarantined assets belong on private storage/a private prefix and
are served only through controlled, revocable delivery. Promotion must preserve the reviewed
publication decision rather than infer public status from the presence of a slug or asset.

**Proof required:** feature/policy tests for every surface above; a production-shaped rehearsal in
which a quarantined historic sermon and all of its assets return the agreed non-public behaviour;
then a separately authorised release exercise proving that an approved item becomes public without
re-importing or changing provenance. Production preflight must also prove the resolved, config-
cached service cutoff and fail closed when it is absent; anonymous service index/detail/sitemap
tests remain required even though the preferred control is the explicit per-record/batch state.

Until this exists, the project's “no newly visible content” assumption is not true in the running
application.

### 2026-08-07 — F30: approved OoS supersession is not applied to evidence lineage

**State: open; G2 blocker; newly discovered outside the remediation plan's blocker list.**

The approved OoS manifest validates and hash-covers each entry's `supersedes` decision, including
the ten curated corrections described in the remediation plan. The runtime import paths do not turn
that authority into `church_service_source_records.supersedes_id`:

- `OosCurationEntryFactory` copies `supersedes` only into the entry's report-oriented `curation`
  metadata and assigns every manifest item a distinct synthetic message ID.
- `EmailSourceAdapter` constructs `source_key` from that message ID plus the parsed service-plan key.
- `IngestChurchServiceSourceRevision` creates a supersession only within an already-identical
  `source + source_key` lineage. Because predecessor and correction have different message IDs,
  they are different lineages and neither supersedes the other.
- The portable assertion-bundle path likewise has no runtime read that maps the manifest item-level
  predecessor to the imported revision. Existing tests prove manifest-chain validity but do not
  assert the imported database lineage or active projection.

The likely result is two active full Email revisions for a corrected service. Both may contribute to
projection, producing duplicate or stale planned items while every manifest hash still passes.

**Required outcome:** define a portable, service-plan-level predecessor identity in the Email
assertion contract. Direct local import and assertion-bundle apply must resolve the predecessor,
create the exact `supersedes_id`, and fail before canonical writes when it is absent, ambiguous,
cross-service or already superseded incompatibly. If one source document yields multiple service
plans, the rule must state which plan(s) the document-level manifest decision supersedes; it must not
guess by database ID or arrival time.

**Proof required:** retain a red-to-green end-to-end test that imports an original plus correction
through both the normal archive path and exported assertion bundle, then asserts one active leaf,
the exact predecessor link, only the correction in the active evidence set/canonical projection,
portable different-PK round trip, idempotent replay and fail-closed missing/ambiguous predecessor.
Run that proof over all ten real manifest chains during rehearsal and include the lineage result in
the private report.

### 2026-08-07 — F31: historic-video bytes are not re-verified at dispatch

**State: open; G1/G5 blocker; newly discovered outside the remediation plan's blocker list.**

`HistoricVideoCurationManifest::plan()` verifies each included recording's root containment,
non-symlink status, byte size and SHA-256 while constructing the plan. It then hands absolute paths
to `HistoricVideoImporter`. A checkpointed bulk pass may consume those paths hours or days later.
The importer does not compare them with the approved `source_files`; it hashes whichever bytes are
present into provenance metadata and dispatches them under the already-approved manifest/plan
identity.

A changed file, remounted replacement volume, retargeted path or corruption after preflight can
therefore be processed and attributed to the approved hash. The OpenLP path already performs an
apply-time include verification; video needs the same invariant.

**Required outcome:** before each single-file dispatch and before reading any segment for
concatenation, re-resolve the approved root and exact relative path and recheck regular-file type,
every path component for symlinks, byte size and SHA-256 against that work item's hash-covered
`source_files`. Abort the item before creating a processing log, temporary concatenation output or
queue work on any difference. Bind processing provenance to the approved digest, while separately
recording the equal observed digest. Operate from a read-only source mount or an independently
verified immutable acquisition copy so the bytes cannot change during the subsequent long read.
Compare the copy-to-processing-storage size/hash and every final durable asset/artifact hash before
an item becomes checkpoint-complete.

**Proof required:** a test builds a valid plan, replaces or mutates a source before its turn, and
asserts zero processing rows/jobs/temp output; cover single, lossless-concatenated and re-encoded
items, symlink/remount substitution, and a later checkpoint after earlier items succeeded. The
checkpoint report must classify the item as a hard source-integrity failure, never merely a skipped
or terminal processing failure.

### 2026-08-07 — F32: import commands can report success without complete corpus success

**State: open; G2/G5/G8 blocker; newly discovered.**

`ImportOosArchiveCommand` catches per-entry exceptions and records `failed` or `import_failed`, but
then writes its report and returns exit code zero. The regression suite currently asserts that
behaviour for an exploded song sync. It also filters entries before bundle export/preflight;
`--limit`, `--date`, `--from` and `--to` can therefore produce or apply a partial bundle carrying the
same full curation-plan hash. Preflight proves only that the bundle matches the *selected* entries,
not that every approved entry is represented.

The historic-video path has the same outcome-level defect. The command succeeds whenever its
`errors` counter is zero, even when approved items were skipped for low disk, in-flight work,
pending review, broad date/service existence, or `--limit`. Its pre-dedup existence test treats any
completed livestream for the date/service as sufficient and `--force` changes that result without
being bound into the approved plan. Neither outcome proves `approved = exact-complete + exact-
already-present`.

**Required outcome:** define one machine-enforced corpus closeout invariant per source. Mutation mode
must return non-zero for failed/import-failed/unaccounted items. Every approved item must end in an
allowlisted terminal disposition tied to its exact manifest item/source/output hashes; review-held
and unresolved are not complete. Final bundles must either cover the entire approved identity set or
belong to immutable, hash-covered checkpoint partitions. Ad-hoc filters and generic `--force` are
forbidden in the definitive run.

**Proof required:** failure, zero-limit, subset, held-review, broad-existing, timeout and interrupted
checkpoint tests all fail closeout; a complete exact replay succeeds and the second run reports only
hash-equal already-present/no-op outcomes.

### 2026-08-07 — F33: the portable OoS bundle still requires the raw corpus in production

**State: open; G3/G8 portability blocker; newly discovered.**

Every bundle mode first rebuilds the curation plan, verifies the raw/formatted payloads and creates
entries from them. `OosArchiveAssertionBundle::preflight()` then calls `structuralReasons()`, which
validates against `OosEmailSourceDocument::fromBody($entry->bodyPlain)`. Production therefore needs
the historic email corpus even though the remediation plan says the normalized assertion bundle is
portable and raw bodies do not enter production.

**Required outcome:** make the approved production artifact self-contained for identity, input hash,
validated normalized assertions and source-validation proof, while containing no raw body, absolute
path or database ID. Perform raw-text semantic validation at controlled export/rehearsal time; at
production, cryptographically verify the approved manifest snapshot, complete identity set,
fingerprints and bundle without opening the raw corpus. If this cannot be achieved, explicitly
change the portability design and define the raw-corpus transfer/cleanup procedure; do not silently
contradict the contract.

### 2026-08-07 — F34: private import artifacts do not meet their privacy contract

**State: open; G3/G5 blocker; newly discovered.**

The plan requires one unique `0700` batch root and `0600` files. OoS reports and bundles create
directories with `0755` and use default-umask file permissions. Bundle B also plain-writes without a
permission check. OpenLP/video paths chmod some files but do not enforce the common private root.
Reports can contain email subjects, absolute paths, message IDs, failures and curation metadata.

**Required outcome:** route every manifest, report, Bundle A/B, OoS assertion bundle, checkpoint,
ledger and acceptance index through one atomic private-artifact writer. It must constrain output to
the approved batch root; reject symlink/pre-existing-unsafe targets; create directories/files as
`0700`/`0600` independent of umask; fsync/rename atomically; redact secrets and unnecessary personal
data; encrypt at rest and in transit; and test actual permissions and tamper behaviour.

### 2026-08-07 — F35: production staging and the resume ledger are ephemeral

**State: open; G5/G8 recovery blocker; newly discovered.**

`historic_staging` and the convergence JSONL ledger default below `storage/app/private`, but the
production compose file does not mount that directory. A restart or deployment can lose both. The
media result bundle references already-staged assets rather than carrying them, and the repository
contains no defined checksummed local-to-production staging transfer. The JSONL ledger can also be
torn between `fwrite`/`fflush` and a host crash; its reader rejects a malformed line wholesale.

**Required outcome:** provision persistent private staging and an operation journal outside the
replaceable container. Prefer a database-backed transactional journal or a framed, fsynced,
hash-chained ledger with an independently mirrored copy and explicit repair rules. Implement an
encrypted, resumable, checksummed asset transfer and inventory, with retention and cleanup. Prove
restart/redeploy/crash survival at the boundaries before copy, after copy, after DB commit, before
and after journal append, and during audit-report creation.

### 2026-08-07 — F36: source acquisition and inventory are not forensically complete

**State: open; pre-G1/G5 blocker; newly discovered.**

The current controls start at an importer-friendly directory. They do not define chain of custody,
write-blocking, drive-health/read-error handling, malware quarantine or a verified preservation
copy. Video inventory only recognises non-hidden `mkv`, `mp4` and `mov`; ordinary configuration also
accepts AVI/WebM, and other historic audio/video/sidecars, hidden files, directories and some links
are invisible to `discovered = include + exclude`. The host command default `/Volumes/CBC
Drive/ServiceVideos` also differs from Sail's `/mnt/cbc-service-videos` mount.

**Required outcome:** before opening corpus files, record physical drive/volume identity,
filesystem, health/read errors and custody; mount the original OS-level read-only with
`noexec,nosuid,nodev` where supported and prove writes fail. Create two verified copies in
independent storage, preserving a filesystem image or metadata-faithful evidence copy plus a
separate materialised working tree. Inventory every path, file type, link/target, size, hash,
timestamp, xattr/read error and explicit disposition, including unsupported extensions. Detect
case-only and Unicode-normalisation collisions across macOS/Docker/Linux. Malware-scan the isolated
copy, quarantine rather than execute, sign/timestamp the inventory, and process only the working
copy through the correct container path. Unreadable sectors, inventory holes or processing the sole
copy are hard stops.

### 2026-08-07 — F37: the video manifest is not yet strict mutation authority

**State: open; G1 blocker; newly discovered.**

`HistoricVideoCurationManifest` remains version 1 with no batch key. Decision authority accepts any
author string without `decided_at`, or any rule string. Duplicate validation proves only that the
named key exists and the duplicate is excluded: it does not require an included, byte-identical
target or reject cycles. Date validation is regex-only before Carbon normalization, so overflow
dates can become a different day.

**Required outcome:** implement the plan's strict schema: exact keys/types, batch identity,
real-calendar dates, authorized decision shape/timestamp, complete inventory accounting and
duplicate target that is included, hash-equal and acyclic. Reject unknown keys and all ambiguous or
lossy authority. Test excluded-to-excluded cycles, nonidentical duplicates, overflow dates and
decision omissions before drive curation.

### 2026-08-07 — F38: bulk processing has no crash-safe checkpoint protocol

**State: open; G5 blocker; newly discovered.**

The video command exposes only an undifferentiated `--limit` and writes its report after the whole
invocation. It has no immutable checkpoint IDs, no durable pre/post-dispatch records and continues
after individual failures. When waiting times out it clears the in-memory in-flight list and keeps
dispatching while old jobs may still run, so real concurrency can exceed `--parallel`. A nested
`AutoPublishServiceSection` job is also outside the main chain/batch tracked by readiness; cleanup
can mark a run completed and Bundle A can export while publication still mutates the graph.
Per-file video deduplication also includes the absolute mount path, so the same approved manifest
remounted at a different root after a crash can receive a different key and duplicate an in-flight
run.

**Required outcome:** derive immutable ordered checkpoint membership from the manifest (maximum 25
recordings or 12 forecast hours), journal before/after every dispatch, stop admission at the first
dispatch/terminal/timeout anomaly, and never dispatch while timed-out work remains live. Track every
nested publication job to terminal state. Provide explicit reconcile/adjudicate/resume commands
that repeat binding preflight. Rehearse worker/Redis/MySQL/Docker kills and confirm no lost,
duplicated or premature-ready item. Include a two-root/same-manifest crash-resume test proving
deduplication derives from portable approved identity, never the host mount path.

### 2026-08-07 — F39: durable output is not bound to the real processing environment

**State: open; G4/G5 blocker; newly discovered.**

Several processing providers default to `mock`, some are absent from `.env.example`, and the video
import does not preflight provider choice, credentials, API project or connectivity. A mock result
can therefore become durable. The fingerprint hashes the configured FFmpeg *path string*, not the
binary/version, and omits output-affecting prompt/schema code, concat codecs/arguments and parts of
song/section matching. Cost usage is only partly present in rotating logs; there is no durable
per-item budget ledger, provider rate limiter or demonstrated 429/`Retry-After` behaviour.

**Required outcome:** before calibration and every resume, fail closed on the exact commit/image,
schema, resolved DB/bucket/prefix/staging identities, non-mock provider allowlist, credentials/API
project, model/reasoning/prompt/schema hashes, every output-affecting algorithm/config, real
FFmpeg/ffprobe binary versions/hashes/arguments, queue/supervisor widths, free space, clock and an
outbound probe. Create a durable per-item/checkpoint calls/tokens/audio-minutes/cost ledger, accepted
forecast with contingency, rate/backoff tests, spending alerts and numeric abort thresholds.

### 2026-08-07 — F40: later machine evidence can silently erase Manual final authority

**State: open; G2 blocker; newly discovered.**

`IngestChurchServiceSourceRevision::stageProposal()` unconditionally clears a non-null
`canonical_finalization` before checking whether the new machine projection is identical. If the
hash is unchanged and there are no conflicts, it returns without a proposal or review flag. A
manually finalised service can therefore retain its reviewed revision while silently losing the
Manual finalization marker, contrary to the plan's Manual-authority invariant.

**Required outcome:** identical machine evidence must preserve Manual final authority. Changed
machine evidence may create an explicit review proposal but may never silently supersede or erase a
Manual decision. Add red-to-green ingestion and full rehearsal tests for identical, complementary
and conflicting later evidence.

### 2026-08-07 — F41: convergence applies stale plans and has no enforced window split

**State: open; G7/G8 blocker; newly discovered.**

`executeBatch()` re-prepares the batch once, then applies services sequentially. It locks a service
only inside its transaction and does not rebuild/compare that service's binding or reclassify under
the lock. A source revision, proposal, finalization, reviewer or canonical edit arriving after the
batch preflight can therefore be overwritten by the stale prepared plan. Plan expiry is also
checked only before the loop. `HistoricPromotionBudget` reports a budget, but apply does not consume
an accepted deadline and will admit every remaining service even after the safe ingress/rollback
reserve is exhausted.

**Required outcome:** under the natural-identity/row locks, rebuild each service's complete binding,
classification and plan hash immediately before its first asset/DB mutation. Abort that service on
any mismatch. Bind the accepted rehearsal-derived deadline/budget into the operation; before each
service, enforce the admission floor plus p95 apply/rollback reserve, finish only the current atomic
service, journal the planned split and exit resumably. Add concurrency-hook and clock-controlled
tests.

### 2026-08-07 — F42: “members only” is only self-registration plus email verification

**State: accepted/non-blocking by maintainer decision on 2026-08-08.**

Any person can register, is signed in immediately and gains song access after verifying the email.
`User` has no membership/approval state. Song catalog/usage queries do not apply the historic
`services.public_from` cutoff, so imported usage history appears behind this open signup wall while
service pages remain hidden. Public service pages link songs to that authenticated route, making
the plan's anonymous service-to-song acceptance criterion a login redirect rather than a usable
journey. Asset redirects to public-disk/CDN URLs also mean a copied URL can outlive authentication.

The maintainer accepts the existing verified-email audience for this material and does not require
a new membership approval model for the import. This is not an import gate. The public service-to-
song login bounce remains ordinary product follow-up. Direct public object URLs are still covered by
F29 because they escape even the accepted existing access boundary.

### 2026-08-07 — F43: external-model processing policy

**State: accepted/non-blocking by maintainer decision on 2026-08-08.**

OoS extraction sends source text to the configured OpenAI extractor and the service-structure path
sends the transcript and planned order. The maintainer considers that the existing project's
processing arrangements apply equally to the historic corpus and does not require a new rights,
consent or DPIA exercise for this import. F39 still binds and verifies the intended provider/model/
configuration so an accidental destination or mock provider cannot enter the definitive pass.

### 2026-08-07 — F44: service identity and editorial metadata can be lost

**State: open; G1 data-quality blocker; rights aspects removed by maintainer decision.**

The video manifest carries bytes/date/service/concat decisions but no preacher, title, scripture or
series. OoS `title_override`/`service_label` exist only in report curation metadata; `ChurchService` has no
occasion/public-title field and public pages render only enum label plus date. Two distinct special
events on one date also collapse into the single `(date, other)` unique identity. Concatenated video
filenames are `YYYY-MM-DD {service}.mkv`; the current filename-to-title path can leave sermons titled
only “Morning” or “Evening”.

**Required outcome:** decide whether the service identity model must support multiple same-day
special events; otherwise fail the inventory on collisions rather than merge them. Preserve known
occasion/title/speaker/scripture/series facts in a portable curation artifact so they survive the
one-time import. Title/slug and special-occasion QA may be post-import editorial work provided the
raw fact is retained and the imported content remains under F29's existing-audience boundary.

### 2026-08-07 — F45: backup success does not prove import rollback

**State: open; G5/G8 recovery blocker; newly discovered.**

Nightly backup intentionally excludes the roughly 200GB sermon-media tree, archive verification is
disabled, and the deploy backup is an on-host DB-only dump. The rollback workflow redeploys an image
and leaves database restore manual. The current test proves an encrypted DB archive can be created,
not that this operation's database, object assets, staging and journal can be restored consistently.

**Required outcome:** take transaction-consistent, timestamped on-host and independent off-host DB
backups with hashes and table/row manifests; restore the exact artifact into disposable MySQL and
measure RTO. Obtain destination object versioning/snapshot/retention evidence or prove a create-only
rollback ledger covering every attempted object, including copy-before-commit failures. Exercise
mid-service and cross-service failure, apply compensation, full restore and repeat apply. Preserve
source, bundles, results, private staging and journals independently through the acceptance and
rollback windows. `backup:run` exit zero is not restore evidence.

The current asset transfer's `exists()` followed by ordinary `writeStream()` is not an atomic
create-only operation: a concurrent object can appear between them, be overwritten, and then be
blindly deleted by compensation. Use conditional create/version/ETag semantics or immutable
operation-owned keys, verify ownership/version again before deletion, and fault-inject the between-
check/write and write/cleanup races.

### 2026-08-07 — F46: production identity, change control and monitoring are not operation-safe

**State: open; G8 blocker; newly discovered.**

`HistoricImportProductionGuard` cannot detect a non-production `APP_ENV` pointed at production and
checks only environment plus a nonblank approval ID. Production has no required reviewers while
pushes to master auto-deploy. The ingress gate pauses import submissions and stages inbound email,
but not ordinary admin edits to targeted data. Error tracking is explicitly uninstalled, rotating
logs are not a durable operation record, and pause/resume instructions do not prove workers,
scheduled jobs or delayed/reserved queues reached the intended state.

**Required outcome:** bind approval to resolved DB identity, bucket/prefix, staging root, commit/image,
schema/config/fingerprints and operation ID—not `APP_ENV`. Freeze deploy/rollback/config/manifest and
targeted admin/data mutations from final preflight through closeout. Protect the production approval
environment or explicitly accept the risk. Assign an incident commander, operator, independent
verifier and monitoring owner; pre-authorise numeric abort thresholds. Use Sentry or a formally
accepted live alternative and retain an external watchboard covering queues/job age, live/failed/
timed-out IDs, workers, DB locks/connections, resource/free-space growth, API 429/5xx/cost and app
exceptions. Release the freeze only after exact audit, public/admin smoke, queue/scheduler recovery
and deferred-email reconciliation. Approval must also enumerate the permitted command and phase;
one token for convergence must not authorize direct OoS, OpenLP or video mutation commands.

### 2026-08-07 — F47: the local definitive-processing runtime is tuned for disposable development

**State: open; G4/G5 blocker; newly discovered.**

The Sail MySQL configuration uses `innodb_flush_log_at_trx_commit=2`, disables doublewrite and sets
`sync_binlog=0`; local Redis has no AOF; the optional Whisper image floats on `latest-cpu`. A host or
Docker crash can lose acknowledged work/queued jobs or change the model between checkpoints. That
is unsuitable for media outputs intended never to be recomputed.

**Required outcome:** define and pin a “historic definitive” runtime with durable MySQL settings,
Redis AOF/recovery, image digests, package lock, FFmpeg/ffprobe/model versions, resource limits,
storage-health monitoring and Mac sleep/update/restart controls; use a UPS where practicable.
Calibrate under those exact settings. Force-kill MySQL, Redis, worker, Docker and the orchestration
command in rehearsal and prove exact reconciliation/resume.

### 2026-08-08 — F48: OoS command modes do not make mutation intent unambiguous

**State: open; G2/G8 command-safety blocker; continuation-audit finding.**

`ImportOosArchiveCommand` has no exactly-one-mode validation. Bundle handling runs before
`--dry-run` is evaluated, so `--dry-run --apply-bundle` applies the bundle rather than remaining
read-only. Supplying both `--import-bundle` and `--apply-bundle` selects one path by option precedence
instead of refusing the contradiction. Supplying no mode performs the database-writing evaluation
mode, while `--import`, `--export-bundle`, bundle flags and filters have other combinations that are
ignored or composed without one documented authority. Tests do not cover these conflicts.

**Required outcome:** define an explicit mutually exclusive mode enum/matrix—reconcile-only,
evaluate, import, export assertions, stage assertions or apply assertions—and reject zero or multiple
modes before reading the corpus, accessing the extractor or writing anything. `--dry-run`/reconcile
must be provably non-mutating in every combination. Bind allowed filters to a complete immutable
checkpoint partition (F32), reject ad-hoc filters for final export/apply, and print the selected mode
and mutation scope before execution.

**Proof required:** table-driven command tests cover every single valid mode and every conflicting/
missing combination, especially `--dry-run --apply-bundle`, both bundle flags, implicit evaluation,
import plus bundle/export, and filters outside an approved checkpoint; every refused invocation has
zero database, extractor, file and queue effects.

### 2026-08-08 — F49: OoS payload verification and consumption use different reads

**State: open; G1/G2 source-integrity blocker; continuation-audit finding.**

`OosCurationManifest::verifyIncludes()` hashes each approved payload and returns its path.
`OosCurationEntryFactory` later opens that path once for frontmatter and again for the body. The
command verifies all entries before the factory begins reading them, so a replacement/remount/edit
between verification and either read can be parsed and imported while `OosArchiveEntry::inputHash`
still carries the manifest's old digest. The earlier deterministic identity check also performs its
own verify/read sequence and does not make the later consumption atomic. Existing tests change the
corpus between separate command invocations, not between verification and consumption in one run.

**Required outcome:** consume each approved payload from one verified immutable byte snapshot. Open
the regular non-symlink file under its approved root, read once, verify pre/post file identity and
size plus SHA-256 against the manifest, then parse frontmatter/body from those exact bytes. Better,
use F36's immutable working copy and still retain this per-entry check. The observed equal digest,
approved digest, parsed content and cache/source-revision input hash must be one binding; any change
fails before creating/updating `InboundEmail`, invoking the extractor, exporting assertions or
ingesting source evidence.

**Proof required:** deterministic hooks replace/truncate/retarget a payload after planning, after
the batch verify and between frontmatter/body reads; direct evaluate/import and assertion export all
fail with zero DB/extractor/bundle effects. Cover a later entry after earlier entries were evaluated
and require the whole checkpoint to remain incomplete under F32.

### 2026-08-08 — F50: OpenLP verification, parsing and provenance hash can see different archives

**State: open; G1/G2 source-integrity blocker; continuation-audit finding.**

`ImportOpenLpDirectoryCommand::importArchive()` calls `verifyInclude()` inside the per-service DB
transaction, but that returns a path. `OpenLpServiceParser` subsequently opens the path as a ZIP,
and `OpenLpSourceAdapter` later hashes the path again. The adapter records whatever hash it observes
without comparing it to the manifest include. A replacement/remount/edit between these operations
can therefore make the approved digest, parsed service/items and persisted source-revision
`input_hash` describe different archive versions. Holding a database transaction does not lock a
filesystem path. Existing tests verify replacement before apply, not at these intra-apply seams.

**Required outcome:** create one immutable per-entry snapshot after resolving the approved regular
non-symlink path. Verify its exact size/SHA against the manifest, parse that snapshot and pass the
same approved/observed digest into the source adapter; the adapter must compare rather than reassign
authority. Delete the snapshot after commit/rollback and record no source evidence or canonical
change on mismatch. The containing F36 working copy remains read-only but is not a substitute for
the binding.

**Proof required:** deterministic hooks replace the source after `verifyInclude()`, while the ZIP is
opened, and before source adaptation. Each case fails with zero service/source/assertion writes and
an incomplete checkpoint; the persisted input hash in the green path equals both manifest and
snapshot hash. Cover a correction of the same logical filename and an earlier successful item in
the batch.

### 2026-08-08 — F51: convergence can commit before observer side effects finish

**State: open; G6/G8 atomicity and mutation-boundary blocker; continuation-audit finding.**

`HistoricConvergenceDispatchGuard` rejects queued jobs, mail and notifications but deliberately
leaves model observers active. `HistoricMediaGraphPersister` creates ordinary `Sermon` and preacher-
alias models and relies on observers for derived work. `SermonObserver` and `PreacherAliasObserver`
handle events after commit; the latter calls `SermonIdentitySyncService`, which can update every
pre-existing unassigned sermon matching the new alias, not just the service in the approved plan.
Laravel commits the PDO transaction before running after-commit callbacks. If a callback fails,
`ConvergeHistoricChurchService` can report apply failure and compensate by deleting copied assets
even though the database rows have already committed and may reference those assets.

The result violates both halves of the convergence contract: a successful apply can mutate
unrelated production sermons outside its prepared hash/audit, and a reported failed apply can leave
committed graph rows pointing at compensated objects.

**Required outcome:** production convergence uses event-quiet persistence. Every required scripture-
filter, identity and other derived write is explicit, bounded to the locked service transaction and
included in the prepared plan, binding hash and exact audit. Global alias backfill is forbidden in
the import; if wanted, it becomes a separate planned and rehearsed operation. Any cache closeout
outside the transaction must be non-authoritative, durable and resumable. The operation fails before
mutation if an unmodelled observer/domain event would run.

**Proof required:** an imported preacher alias cannot change an unrelated existing sermon; injected
scripture/identity/cache failures cannot produce committed rows whose assets were removed; apply
emits zero model/domain events as well as zero jobs, mail and notifications; the exact audit covers
aliases and scripture filters and includes an unrelated-row/database-diff assertion.

### 2026-08-08 — F52: historic processing cannot reliably suppress outbound notifications

**State: open; G4/G5 operational-safety blocker; continuation-audit finding.**

Historic videos enter the ordinary livestream job chain, including service-structure detection,
sermon extraction and completion notification. Only the completion email respects
`email.send_success_notifications`. Both manual-review branches queue `ManualReviewRequired`
unconditionally, and `ProcessingRunFailureHandler` queues `LivestreamProcessingFailed`
unconditionally. Although configuration defines `email.send_failure_notifications`, no production
code reads it. A definitive runtime using the real mail transport can therefore produce a bulk
review/failure mail storm, queue pressure and obscured live alerts even when the operator believes
historic notifications are disabled.

**Required outcome:** bind an explicit no-external-notifications mode or isolated mail transport to
the immutable historic operation. It must cover success, manual-review and failure paths while
retaining the same facts in the private durable journal/watchboard. Preflight proves the transport
and operation binding; ordinary current processing continues to alert normally.

**Proof required:** exercise historic success, both review branches and processing failure while
global live notifications are enabled and assert no outbound message; retain the corresponding
private alert records. Add a current-run control proving ordinary alerts still send and a test that
the documented failure-notification toggle is effective rather than dead configuration.

### 2026-08-08 — F53: the corpus gate can pass on unrelated global evidence

**State: open; G2 corpus-certification blocker; continuation-audit finding.**

`ChurchServiceCorpusCompleteness` counts source records and service identities across the whole
database and treats a source kind as staged when its count is merely greater than zero.
`ChurchServiceProposalCensusGate` then compares the global identity union to one scalar expected
service count. The test suite explicitly permits two Email services plus only one OpenLP record to
pass. Consequently current-era evidence, a prior batch or a single OpenLP row can satisfy a historic
gate even when most approved OpenLP entries were never staged. F1's explained-excess rule cannot
repair a gate that does not know the approved per-source membership.

**Required outcome:** G2 certifies the exact approved manifest/bundle membership per batch and source
kind: each source item key maps to its expected service identity or explicitly approved identities,
current active leaf, input hash, processing fingerprint and projection. Missing, extra, stale, old-
batch and cross-batch evidence all fail; source-specific expected sets replace “count > 0” and the
single global-count shortcut.

**Proof required:** the complete current approved Email identity set plus one approved OpenLP entry
fails; unrelated/current-era and prior-batch rows cannot help; the exact source-specific sets pass;
stale input hash, active leaf or projection binding fails. The historical regression used 391 Email
identities and 428 OpenLP entries; those counts are not the current Email authority. Retain the
complete item-level certification in the acceptance index.

### 2026-08-08 — F54: normalized-content no-op can reuse stale immutable provenance

**State: open; G2/G8 provenance and idempotency blocker; continuation-audit finding.**

`IngestChurchServiceSourceRevision` defines its revision hash from normalized assertions and service
content, then immediately returns the old leaf when that hash matches. Yet the source row also holds
the input hash, batch hash, processing fingerprint, payload completeness, captured time and author.
The same normalized service produced from changed raw bytes, a different approved batch or a
different processing fingerprint can therefore be reported successful while the returned evidence
still records the old authority. Exact rerun and corpus certification may never converge on the
artifact actually applied.

**Required outcome:** distinguish content equality from immutable evidence identity. A no-op is
valid only when every authority/provenance field required by the operation also matches. Otherwise
append a correctly linked immutable provenance revision or association without needlessly changing
the canonical projection. Every importer must assert that the returned record has the exact
manifest input/batch/fingerprint/completeness binding it supplied.

**Proof required:** repeat identical assertions with a different input hash, fingerprint, batch and
payload completeness and retain each exact provenance transition; an exact replay alone is a no-op.
Old-batch evidence cannot satisfy the new operation, through either direct OpenLP/Email import or
portable bundle apply.

### 2026-08-08 — F55: source-key equality differs between MySQL and PHP

**State: open; G2 portability/identity blocker; continuation-audit finding.**

The evidence schema stores source keys under MySQL's default case/accent-insensitive Unicode
collation and makes `(source, source_key, revision_hash)` unique. Lineage construction, projection
and the lineage auditor use byte-strict PHP array/string equality. Case variants, accents, composed
versus decomposed Unicode and trailing spaces can therefore be one database identity but multiple
PHP lineages, causing unique-key failures, missed successors or order-dependent selection. F36's
filesystem collision inventory does not cover Email/manual/live keys or repair this schema/runtime
disagreement.

**Required outcome:** define one canonical, portable source-key encoding or binary/hash identity and
use it consistently in PHP, bundle contracts, schema constraints and audits. Audit existing rows
before an additive migration; reject rather than silently merge ambiguous legacy variants.

**Proof required:** case, accent, composed/decomposed Unicode and trailing-space variants behave
identically and deterministically through OpenLP, Email and Manual ingestion, database uniqueness,
successor lookup, projection, bundle round trip and lineage audit.

### 2026-08-08 — F56: ingress reopening sweeps the ordinary pending-email inbox

**State: open; G8 freeze/release and recovery blocker; continuation-audit finding.**

During the import window the webhook stores deferred mail as ordinary `Pending` rows with no
operation identifier. `Pending` is also the normal state for held/manual-review messages and failed
redelivery. Release then opens the ingress gate and dispatches every pending row; the database lock
is released before the unscoped sweep. A partial failure leaves ingress open, ordinary review mail
queued, and no durable cursor by which the operation's deferred set can be resumed exactly.

**Required outcome:** create operation-scoped deferred/outbox records atomically with webhook
receipt. Release selects only that operation's set, marks it durably and dispatches uniquely and
resumably after commit; existing pending/review messages are never swept. Reopening ingress and
draining its outbox must be separately observable and retry-safe.

**Proof required:** cover a pre-existing held pending email, arrivals during the closed window,
duplicate webhook delivery, failure after N dispatches, crash after reopening but before dispatch,
retry and sequential import windows. Each deferred message processes once, ordinary pending rows do
not move, and the operation cannot close until its outbox reconciles exactly.

### 2026-08-08 — F57: “exact audit” can close an incomplete or unrelated operation

**State: open; G8 exact-closeout blocker; continuation-audit finding.**

`AuditChurchServiceConvergenceCommand` makes Bundle A optional, and the auditor simply skips media
checks when it is absent. With `--operation-id`, any nonblank value is written to the ledger without
resolving the prepared/applied operation or checking its Bundle A/Bundle B hashes and target. The
command also appends a passed `exact_audit` ledger sample before validating, encoding and writing
the report. An unwritable report can therefore return failure while the ledger claims that the
operation passed; a canonical-only Bundle B audit can masquerade as full promotion closeout.

**Required outcome:** operation-closeout mode requires both exact bundles and resolves a prepared,
applied ledger operation whose operation ID, target, bundle hashes and fingerprints match. Write the
report atomically, hash it and verify its durable location first; only then append a passed closeout
event referencing that digest. A routine canonical-only audit remains available but can never write
an operation closeout.

**Proof required:** missing Bundle A plus operation ID, arbitrary/mismatched operation, wrong target,
corrupt/missing asset and report-write failure all produce no passed ledger event. The green event
binds the applied operation, both bundle hashes, target/fingerprint and durable report digest, and a
later audit verifies that report before accepting G8.

### 2026-08-08 — F58: the production-window budget omits and misclassifies the no-op rerun

**State: open; G6/G7 capacity blocker; continuation-audit finding.**

The audit command describes its closeout reserve as exact audit plus the mandatory full no-op rerun,
but records only `exact_audit`. Every already-present service in the rerun is instead recorded as an
ordinary `service_completed` sample, and `HistoricPromotionMeasurements` includes those samples in
apply throughput. That distorts apply p95 downward while the sequential audit-plus-rerun time is not
reserved at all. A deadline derived from these measurements can admit another mutation batch without
enough time to execute the required closeout or rollback decision.

**Required outcome:** add an explicit batch-level exact-no-op closeout event with operation/bundle
binding, duration and exact result. Exclude `already_present` reruns from mutation/apply samples and
services-applied counts. Budget the sequential combined exact audit and full no-op rerun using a
measured conservative bound before admitting the next production checkpoint.

**Proof required:** no-op services never enter apply measurements; a complete exact batch rerun is
counted once in closeout; partial, changed or non-no-op reruns fail; budget tests use audit plus rerun
and leave the accepted rollback reserve.

### 2026-08-08 — F59: Bundle A can lose Scripture Passage linkage

**State: open; G4/G5/G8 normal-output completeness blocker; continuation-audit finding.**

Sermon AI processing dispatches scripture enrichment independently of the main chain. Historic
throughput/readiness does not track that job to settlement. Bundle A carries a reference and the
derived scripture-filter index, but intentionally excludes `scripture_passage_id` and carries no
portable passage natural key/content/outcome; its production persister does not relink it. A sermon
that had a working “Read passage” relationship after definitive processing can therefore be
exported before enrichment finishes or imported without that public relationship. Existing sermon
promotion code already demonstrates that a natural reference can be remapped across different
database IDs, so this is a missing historic-output contract rather than unavoidable PK portability.

**Required outcome:** choose and document a portable natural-key/re-fetch policy. Track enrichment
lineage and terminal outcome in historic readiness, carry the expected passage identity/outcome in
Bundle A, then deterministically relink an existing production passage or perform an idempotent,
tracked enrichment. Exact audit verifies the link or the approved terminal absence reason.

**Proof required:** pending/failed enrichment blocks export unless it has an explicitly approved
terminal outcome; an existing passage remaps across deliberately different IDs; absent/API-disabled/
budget/not-found results follow the approved policy; closeout catches missing/wrong relationships and
the public “Read passage” journey is preserved.

### 2026-08-08 — continuation coverage record

The continuation inspected the remaining code paths to exhaustion. The following checks produced no
additional finding distinct from F29-F59; this is positive scoping evidence, not evidence that the
open gates have passed:

- anonymous sermon/service routes, APIs, route-model binding, feeds, sitemap, taxonomy/count pages,
  JSON-LD, caches and direct audio/video/transcript/thumbnail/storage URLs all reinforce F29; no
  separate push, newsletter, websocket, search-index or import-time analytics fanout was found;
- authenticated/verified song and member screens remain within accepted F42, and the checked admin
  routes, middleware, policies and service APIs introduced no separate import-specific boundary;
- OoS direct/evaluate/export/stage/apply, OpenLP plan/apply, historic video single/concat/timeout/
  resume, the full queue graph, Bundle A/B, convergence, audit and no-op branches are represented by
  F30-F35, F38-F41 and F48-F59; queue retry-after exceeds the scanned worker/job timeouts and the
  required historic queues have configured supervisors;
- song soft-delete/restoration and slug lookup include deleted records where needed, song sync is
  transactional, pivot rows are deduplicated, multiple same-day sermons appear intentional, and the
  source-lineage one-successor constraint exists; none closes F44's separate same-day service issue;
- no further static production-volume, Redis AOF, timeout-ordering or scheduler-ingress defect was
  found beyond F35/F45-F47/F56.

This was a static, read-only investigation: no importer, extractor, mutation or test suite was run.
The drive is unmounted, so OpenLP/video corpus membership, bytes, links, permissions, filesystem
health, mount portability and capacity remain unverified. The Email roots have now been measured
locally, but the replacement manifest and approval are still outstanding. Production DB/schema/data,
object versioning,
bucket contents, queue/workers, resolved config/secrets, provider quotas/API availability, backup
restore and live report/journal durability likewise require the gated production audit and
production-shaped rehearsal. These are explicit evidence dependencies, not unchecked code areas.

## 4. Required sequence and remaining workstreams

This is the dependency order. A later phase must not be used to discover whether an earlier gate
was actually met.

| Phase | May start when | Exit evidence |
|---|---|---|
| 0. Decide and design | Now; no corpus access | F1 and all business decisions have named owners — **all §5 rows decided 2026-08-11**; F29-F59 have accepted designs and testable acceptance criteria |
| 1. Harden code/runtime | Phase 0 decisions affecting schemas/contracts are made | Required fixes pass focused/full quality gates; operation artifacts and runtime are version-pinned |
| 2. Acquire and curate | F36 protocol, capacity, protected copies and malware tooling are ready | Signed whole-drive inventory; original protected; approved OpenLP/OoS/video manifests with zero unaccounted paths, including the current 533/261 Email inventory |
| 3. Definitive local processing | F31-F39, F47-F50, F52-F55 and F59 are green; manifests are approved | Every checkpoint exact-complete; output/cost/capacity ledgers reconcile; no unresolved live/timed-out work |
| 4. Production-shaped rehearsal | G1-G5 plus clean different-PK environment | Exact Bundle A/B apply, audit, complete no-op rerun, crash/resume, restore/rollback and public/private smoke all pass |
| 5. Production apply | G6/G7 accepted; every open F29-F59 finding green; command-exact runbook approved | One mutation pass, exact audit/no-op closeout, recovery evidence retained, no audience expansion |
| 6. Editorial follow-up | Exact import accepted | Titles/occasions and ordinary corrections improve without re-importing or changing source provenance |

### A. Close the known engineering and data gates

**The phase table above governs ordering.** Each item below names the earliest phase its finding
must be green for; where the two could be read differently, the table wins. An item that also
appears in a later phase's work is doing implementation there, not relaxing its gate.

1. ~~Re-inventory and re-curate the expanded local Email roots, then decide F1.~~ **Done
   2026-08-09, re-curated 2026-08-11 (D1):** the approved replacement holds 535 entries — 534
   included and 1 excluded — and 521 identities, including the three current-era entries. Batch
   `oos-curated-2026-08-11`, manifest `f4b6b833…ee013`, plan `03d40e46…2de8c1`. F1 uses that exact
   set and permits only hash-covered `service_beyond_manifest` identities; unexplained extra or
   missing services fail closed.
2. ~~Implement PR26 as part of F53 exact per-batch/per-source membership, with red-to-green gate,
   command and end-to-end census tests. Do not add a scalar-only exception.~~ **Done 2026-08-09
   (`cb8d873dd`):** `ChurchServiceCorpusMembership` plus the census command's `--membership` option.
   No scalar-only exception was added. Its certification against the staged corpus remains unrun.
3. ~~Fix F30 before any Email staging or proposal census, with direct-import and portable-bundle
   lineage tests.~~ **Done 2026-08-10 (`b4006d1b8`).** Re-approve the assertion/bundle contract and
   invalidate any rehearsal Email evidence produced before the fix — no such evidence exists yet,
   because no staging run has happened.
4. Fix F31-F39, F47-F50, F52-F55 and F59 before definitive local processing (phase 3), because each
   one can corrupt or misattribute output that is never recomputed: exact apply-time byte
   verification and post-copy/output hashes; complete/fail-closed outcomes; genuinely portable
   bundles; private artifacts; persistent staging/journal/transfer; strict manifests; crash-safe
   checkpoints; nested-job readiness; complete environment/fingerprint/cost binding; isolated
   historic notifications; exact batch/source membership certification; exact input/batch/
   fingerprint provenance on unchanged normalized content; one canonical source-key identity; and
   settled Scripture Passage enrichment before any Bundle A export.
5. Fix F40-F41 and F51 before convergence rehearsal (phase 4): preserve Manual final authority,
   make apply event-quiet with every derived write bounded and audited, and rebind/reclassify each
   service under lock while enforcing the measured window split.
6. Fix F56 before production-shaped operations: make ingress deferral/release operation-scoped,
   durable and exactly resumable. F52's notification isolation is required earlier, at item 4.
7. Add F57-F59 to a single operation closeout command/report which fails unless every approved source item,
   checkpoint, source revision, review decision, staged asset, promoted object and canonical result
   has one exact acceptable disposition. It must reconcile Email/OpenLP/video/Bundle A/Bundle B and
   the operation journal, require the exact media and canonical bundles, settle/relink Scripture
   Passage enrichment, and reserve/measure the complete exact audit plus no-op rerun rather than
   trusting the exit code of an individual command.
8. ~~Configure the approval-gated production audit environment~~ — **superseded 2026-08-09:** the
   maintainer accepted manual read-only SSH audits as the permanent operational path, and
   `audit:service-evidence-coverage` has been run (3 services, no source records, 32 canonical items
   on unevidenced services). The disposition was decided the same day: back-fill retained evidence
   for all three through the normal source-revision path. **That back-fill is still outstanding**,
   and it is drive-free.
9. ~~Provision a clean rehearsal database with a documented refresh/reset procedure. Do not use the
   contaminated working database and do not use `--accept-unevidenced-items` merely to get past the
   guard.~~ **Done 2026-08-11:** `historic-import:provision-rehearsal-database`; see the evidence
   entry. Still open, and deliberately a **separate** artifact from the above: §13.5 step 12's
   production-shaped destination database with deliberately different primary keys, which this
   command does not build.
10. Complete the mounted-drive OpenLP and video inventories and approve the final immutable manifests
   before any bulk processing.
11. Run focused tests, PHPStan, Pint and the full parallel suite for each release candidate. Run
    Dusk/read-side tests for the unchanged visibility boundary and editorial corrections.
12. Run the full remediation-plan rehearsal, including census convergence, calibration, checkpointed
   media processing, residual review, linked Bundle A/B export, different-PK import, exact audit,
   complete second no-op run, backup restore and apply/rollback repetition.

### B. Preserve the existing visibility boundary and source facts

The maintainer's decision is that this is a backfill of existing material under existing access and
processing arrangements. It does not trigger a fresh rights, consent, licensing, DPIA or membership
review. Those topics are not import gates.

1. Implement and prove F29 as an **audience-preservation** control. Imported material must not be
   reachable by a broader audience than the equivalent existing material through page, feed,
   sitemap, controller, object URL or CDN cache. It may be quarantined until the exact audit and then
   assigned the already-accepted existing visibility; this is not a new publication approval.
2. Record the accepted F42 verified-email audience and F43 existing external-processing arrangement
   in the operation artifact so later operators do not reopen them or accidentally configure a
   different audience/provider.
3. Resolve F44's same-day special-service collision before manifest approval and preserve known
   title/occasion/speaker/scripture/series facts through the one-time import. Presentation cleanup
   can happen afterwards because it does not require source reprocessing.
4. Prove that corrections/unpublishing supported by the existing application remain possible without
   deleting import provenance or rerunning the corpus.

### C. Replace the stale runbook with an executable one-operation specification

1. Rewrite the sole production runbook from the actual command signatures and current G2-G9 plus
   F29-F59 gates. Delete obsolete fixed counts, mandatory-Manual assumptions and commands missing
   current operation ID/expiry arguments.
2. Include exact commands, arguments, artifact paths, expected output/exit code, operator, witness,
   evidence captured, abort condition and rollback action for every step.
3. Remove superseded fixed counts and Manual-review assumptions; all counts come from the approved
   manifests and reconciliations.
4. Include T-minus preparation, source/staging transfer, backups and restore proof, ingress/admin/
   deploy freeze, queue/scheduler snapshots, per-checkpoint admission, monitoring cadence, crash/
   restart/resume, exact audit, no-op closeout, release and evidence retention.
5. Make the batch/manifest/operation/deploy/config/fingerprint relationship explicit. No value is
   reconstructed during the production window. Every path shown must be the path visible in the
   process/container that executes it.
6. Include a decision tree for source/hash mismatch, low space, timeout/live work, failed job,
   provider/rate/cost breach, DB/object failure, expired token, plan drift, concurrent edit and
   missed deadline. State which cases stop, resume, compensate or restore; never improvise.
7. Have a second operator walk through it, then rehearse the document verbatim with timings and
   screenshots/reports. Any prompt-time improvisation or undocumented command makes the
   runbook unapproved and returns the operation to rehearsal.

### D. Source acquisition, custody and preservation

1. Write the F36 acquisition procedure and capacity plan before connecting the drive. Name the
   custodian, witness, evidence locations and disposition for a failing/unreadable source.
2. Connect only for read-only acquisition: identify the physical device and filesystem; record
   health/read errors; prove the original is non-writable/non-executable; make and independently
   verify two protected copies. Never point an importer at the original.
3. Produce a whole-filesystem inventory, not an importer extension-filter inventory. Every regular,
   hidden, unsupported, sidecar, directory, symlink/alias/hard link and read error receives an
   explicit, signed disposition. Preserve raw path bytes/Unicode form and detect case/normalization
   collisions.
4. Malware-scan in isolation. Preserve an untouched evidence image/copy and generate a separately
   hashed working tree; materialize approved symlinks only in that working tree with a signed map.
5. Build strict source manifests from the complete inventory, adjudicate every include/exclude/
   duplicate/correction/identity collision, obtain two-person approval and freeze them. Bind the
   working-copy/drive identity into the operation context.
6. After processing, keep the original and both protected copies read-only until exact production
   acceptance plus the rollback/takedown observation window. Record when and by whom they may later
   be deleted or returned.

### E. Capacity, cost, observability and production recovery

1. Build F47's pinned durable local runtime and F35's persistent private staging/journal/transfer.
   Prove source-before-copy, destination-after-copy and final durable-output hashes.
2. Calibrate a stratified sample under the exact definitive runtime: short/long, single/concat,
   lossless/re-encode, old/new codec/resolution, low quality, multiple services, children/special,
   existing-production collision and known failure. Measure p50/p95/max per stage, source/temp/final
   bytes, DB growth, CPU/RAM/GPU, API calls/tokens/minutes/cost and human review time.
3. Forecast every checkpoint and total operation with at least the approved contingency. Reserve
   source working-copy space, worst-case simultaneous temp/concat/output/staging, DB/index/log/backup
   growth and rollback copy. Define warning/stop thresholds before work starts.
4. Use immutable checkpoint IDs/membership and the durable journal. After each accepted checkpoint,
   reconcile elapsed/cost/capacity forecast, jobs/queues, output hashes, DB, staging and backup;
   reforecast before admitting the next. Never continue past live timed-out work or a hard error.
5. Implement F45 backups/restores and F46 binding/freeze/monitoring. Rehearse crash/torn-write,
   provider outage/429, low disk, worker loss, DB restart, object-copy failure, deploy interruption,
   mid-service compensation and full restore. Record achieved RPO/RTO and compare with the accepted
   window.
6. Create one immutable acceptance-evidence index: acquisition/inventory/manifests; code/schema/
   config/binary/model/prompt fingerprints; checkpoint/cost ledgers; failed-job dispositions;
   DB/object/staging backup and restore evidence; different-PK/no-op/restart/rollback results; G7/G8
   approvals; every report; pause/release snapshots; public/admin smoke; exclusions. Encrypt,
   checksum/sign and retain two independent copies with owner, retention and destruction date.

### F. Content quality, identity and business acceptance

1. Before bulk processing, define a blinded/hand-verified truth set stratified by era, source type,
   quality/codec, morning/evening/special, single/concatenated, multi-service email, correction chain,
   duplicate, existing-production collision, guest/preacher alias, children/talk, repeated song and
   incomplete service. The single currently verified Email service is not representative.
2. Set numeric acceptance thresholds *before* seeing results. Exact identity/hash/accounting,
   supersession and visibility-boundary enforcement must be 100%; no source loss, wrong service or
   broader-audience exposure is waivable. Set separate thresholds for item precision/recall/order, sermon/talk boundaries,
   transcript quality, preacher/scripture/title/series extraction and song matching. Define the
   sample size, confidence/strata, adjudicator and what happens below threshold.
3. Run editorial QA for low-information/duplicate titles and slugs, special occasions, two same-day
   `other` events, unknown speakers, scripture/series, planned-versus-actually-observed items,
   incomplete recordings and conflicting sources. Never present a planned item as observed fact.
4. Define the archive product acceptance journeys: browse by year/date/service/occasion; find by
   preacher, scripture and song; distinguish complete/incomplete/planned-only history; open a
   sermon/song from a service without a misleading auth bounce; understand private omissions;
   request correction/takedown; and retain stable canonical URLs after edits.
5. Verify responsive/keyboard/screen-reader behaviour, captions/transcript usability, meaningful
   titles, no empty/duplicate search noise, feed/sitemap/cache correctness and honest 404/private
   responses. Test the application's existing public, verified-email and operator audiences.
6. Prove bulk writes do not send unintended notifications, flood feeds/sitemaps/search, trigger
   analytics as new content, or expose hundreds of items at once. Release is a controlled editorial
   batch with owner, rollback and observation period—not a side effect of import.

## 5. Decisions required from the maintainer or church

**All rows are now decided.** The 2026-08-11 evidence-log entry above records each decision, the
measurements behind it and the work it creates. A decision is not a gate: every row still needs its
implementation and its rehearsal, production or operator evidence.

| Decision | Owner | Outcome |
|---|---|---|
| F1 explained-excess corpus reconciliation | Maintainer | **Decided 2026-08-09.** Exact approved 521-identity baseline; extra identities only where `service_beyond_manifest` explains them. Implemented; F53 owns exact membership |
| Disposition of production services with canonical items but no retained evidence | Maintainer | **Decided 2026-08-09.** Back-fill retained evidence for all three through the normal source-revision path; no inference, exclusion or legacy acceptance |
| Exact existing visibility assigned to imported sermons/assets | Maintainer | **Decided 2026-08-11 (D2).** `Published` — identical to existing sermons — via signed `historic-import:release-batch`, never as a side effect of import |
| Existing verified-email audience and existing external-model arrangement | Maintainer | **Decided 2026-08-08; accepted as-is (F42/F43)** |
| Same-day special-service identity and retained occasion/title facts | Maintainer + editorial owner | **Decided 2026-08-11.** Fail closed on collision, no schema change; curated facts consumed and carried. Video manifest curation must still exercise it |
| Re-curate `2026-06-21-am-revised` with its missing `supersedes` (invalidates recorded manifest/plan hashes) | Maintainer | **Decided 2026-08-11 (D1).** Exclude the predecessor `2026-06-21-am` instead; both entries stay `partial`. Re-hash and re-approve |
| Scripture Passage remap/refetch and approved terminal-absence policy | Maintainer + editorial owner | **Decided 2026-08-11 (D3).** Ratify relink-only; add a pre-apply production enrichment pass and a fail-with-zero-writes preflight |
| Final include/exclude/duplicate/identity decisions in OpenLP/video manifests | Maintainer | **Rule decided 2026-08-11 (D9).** Fail-closed adjudication, whole-corpus scope, written reason on every exclusion. Per-file content still needs the mounted drive |
| Accepted accuracy threshold and treatment below it | Church governance + maintainer | **Decided 2026-08-11 (D4).** Precision floors ≥ 0.98 stop the batch; recall floors ≥ 0.85 route to review; identity/hash/supersession/visibility stay 100% |
| Maximum local checkpoint, production ingress window, split and rollback thresholds | Maintainer/operator | **Decided 2026-08-11 (D5).** `maximum_import_ingress_blocked_minutes` = 480; checkpoint bounds 25 items / 12 forecast hours ratified; three rollback triggers set |
| Backup/object rollback design, RPO/RTO and retention window | Maintainer/operator | **Decided 2026-08-11 (D6).** Pre-window dump verified by real restore; RTO 30 min, RPO to the freeze; object rollback already met by operation-owned keys |
| Production deploy/admin/config freeze and approval protection | Maintainer/operator | **Decided 2026-08-10 + 2026-08-11 (D7).** 503 refusal + scheduler skip; required reviewers on `production`; log-only operation record **formally accepted** in place of Sentry |
| Evidence retention and source-drive custody duration | Maintainer/operator | **Decided 2026-08-11 (D8).** Small corpora and artifacts permanent; video original returned to the church, working copies deleted on exact audit + smoke |

## 6. Final go/no-go checklist

This is the final checklist for the investigation. No single person may waive a failed technical
invariant during the production window.

- [ ] F1 decided, PR26 implemented, and G2 certified against all declared source kinds.
- [ ] F30 manifest-authorised Email supersession produces one exact active lineage through direct
      import and portable bundle apply.
- [ ] F31 re-verifies each approved video source immediately before reading/dispatch and fails with
      zero downstream state on any change; copied and final outputs are hash-verified.
- [ ] F32's per-source and whole-operation closeout accounts for every approved item; no failure,
      held/unresolved item, ad-hoc subset or generic existence skip can report success.
- [ ] F33 bundle apply is genuinely portable without raw email bodies in production, or a changed
      operational design explicitly supplies and cleans up the corpus.
- [ ] F34 artifacts are atomically created in a private `0700` root as `0600`, encrypted/redacted
      and protected against symlink/tamper/pre-existing-file cases.
- [ ] F35 persistent staging, asset transfer and crash-safe operation journal survive restart,
      redeploy and every rehearsed transaction boundary.
- [ ] F36 whole-drive acquisition produces two verified protected copies and a signed, complete
      inventory with every path/read error explicitly disposed.
- [ ] F37 strict OpenLP/OoS/video manifests reject unknown schema, invalid authority, bad dates,
      nonidentical/cyclic duplicates and all unaccounted files.
- [x] Current Email roots are fully reconciled: 533 verbatim, 261 formatted, 259 paired, 274
      verbatim-only and 2 formatted-only are represented by the approved replacement manifest and
      hashes, with the current-era boundary and every include/exclude decision recorded.
- [ ] F38 immutable checkpoints stop on anomalies, never exceed concurrency after timeout, and wait
      for every nested job before declaring durable readiness; remounting the same manifest cannot
      change deduplication identity.
- [ ] F39 exact non-mock providers, environment, binaries, prompts/algorithms/config and cost controls
      are fingerprinted, preflighted and bound to every checkpoint/resume.
- [ ] F40 preserves Manual final authority; F41 rebinds each service under lock and enforces the
      accepted time/rollback reserve.
- [ ] Production evidence audit succeeded and the current-era disposition is implemented/rehearsed.
- [ ] F29 whole-content quarantine covers every read, feed, index and direct asset surface.
- [x] F42 existing verified-email audience accepted by maintainer; no new membership model required.
- [x] F43 existing external-model processing arrangement accepted; no new rights/DPIA gate required.
- [ ] F44 special-service identity and title/occasion handling preserve all curated source facts.
- [ ] Source drive protocol, complete inventories and immutable approved manifests are signed off.
- [ ] F45 exact DB/object/staging/journal backup was restored successfully; compensation and full
      rollback/re-apply were timed and met accepted RPO/RTO; conditional object creation/cleanup
      cannot overwrite or delete a foreign concurrent version.
- [ ] F46 operation binding, deploy/admin/config freeze, alerting/watchboard and two-person control
      passed rehearsal; F47 durable local runtime passed forced-crash recovery.
- [ ] F48 every OoS invocation selects exactly one explicit mode before any read, extraction or
      mutation; conflicting/missing modes and ad-hoc definitive subsets fail with zero effects.
- [ ] F49/F50 bind OoS and OpenLP parsing, provenance and mutation to one immutable byte snapshot
      whose observed hash equals the approved manifest hash.
- [ ] F51 convergence emits no model/domain events or after-commit authoritative work; every derived
      write is bounded, transactional and audited, with no change to unrelated rows.
- [ ] F52 historic success/review/failure paths produce no external notification while retaining
      durable private alert facts; ordinary current processing still alerts.
- [ ] F53 certifies exact source-item/batch membership rather than global counts; F54 records the
      exact input/batch/fingerprint provenance even when normalized content is unchanged; F55 uses
      one source-key identity in PHP, bundles and MySQL.
- [ ] F56 ingress release drains only the operation-scoped deferred outbox exactly once and never
      sweeps the ordinary pending/review inbox.
- [ ] F57 closeout requires and binds both exact bundles, the applied operation and a durable report
      digest; no passed event can predate or outlive its report.
- [ ] F58 apply timings exclude no-ops and the accepted window reserves the measured full exact
      audit plus complete no-op rerun and rollback floor.
- [ ] F59 settles Scripture Passage enrichment before Bundle A export and portably relinks or
      records the approved terminal absence; exact audit and the public journey prove it.
- [ ] Clean production-shaped full rehearsal, exact audit, full no-op rerun and restore/rollback
      repetition all pass on the release candidate.
- [ ] Measured throughput, cost, capacity and production-window/rollback budgets are accepted.
- [ ] Command-exact production runbook was executed verbatim in rehearsal and independently checked.
- [ ] Private batch ledger contains every manifest, hash, fingerprint, approval, backup, report and
      run identifier, with no secrets or inappropriate personal data.
- [ ] G3-G8 are evidenced on the exact release and operation; production approval is time-bounded and
      names that operation.
- [ ] Named operator, witness, incident decision-maker, pastoral/content owner and takedown owner are
      available for the window and closeout.
- [ ] Editorial truth-set thresholds and all user journeys pass; import does not broaden the
      audience beyond the existing accepted visibility.

## 7. Out of scope for this investigation

- Running an importer, extractor, media processor or production mutation.
- Connecting or mounting the CBC drive.
- Changing application code, configuration, dependencies or infrastructure.
- Treating later cleanup/one-shot deletion as import readiness; cleanup remains gated by G9.
