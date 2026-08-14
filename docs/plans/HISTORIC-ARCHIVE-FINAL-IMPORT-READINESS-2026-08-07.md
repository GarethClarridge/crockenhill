# Historic Archive Final Import Readiness Plan

> **Status (read-only audit extended through 2026-08-12): NO-GO. Do not connect
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

Rehearsal has begun: the clean rehearsal database exists, two superseded full Email staging runs
exposed and then verified earlier fixes, and archive-v11 passed its balanced 69-entry calibration.
That is not the production-shaped rehearsal. A fresh clean-database **full archive-v11 staging run**,
mounted-drive OpenLP/video inventories, definitive processing, different-database apply, exact
no-op, restore and rollback exercises have not run. G2-G9 remain unclaimed and deterministic
promotion still has no accepted production-window measurement. F1 and the remaining §5 business
decisions are decided, but their required operation/rehearsal evidence remains outstanding.

The audits found thirty-seven additional findings, F29-F65; thirty-two remain blockers after the
maintainer's 2026-08-08 scope decision accepted F42-F43 and the closures of F61, F62 and F63. Two of
those thirty-two — F64 and F65 — have their code landed as of 2026-08-14 and are blocked only on the
staging re-run that measures them. They span source and manifest integrity,
Email/convergence correctness, false-success command semantics, ephemeral staging and resume state,
checkpoint/recovery, unbound processing environments, event/notification containment, exact corpus
and closeout binding, restore/change control, access boundaries, missing service identity and the
historic hymn-usage lane. Most urgently, the application cannot
guarantee that imported historic sermons remain private: the service-archive date gate does not
gate the sermon archive, podcast, sitemap, or ordinary sermon audio/transcript delivery. That
contradicts the governing business assumption that import must not expand the audience; with the
current read paths, production import can itself publish.

The Email roots are now covered by the approved `oos-curated-2026-08-12` authority: 535 entries,
534 included, 1 excluded and 521 named identities. Archive-v11's balanced calibration passed and the
full staging population has now been measured and reviewed — but on a tree where F63 was believed
fixed and was not, so those figures describe the unfixed behaviour and must be re-measured. The
review itself stands: **only 127 of the 521 approved identities are staged, and the 404 held sources
are the entire remaining gap.** 231 of the missing identities are clearable by review today, 148 are
unreachable by any operator action until F65 lands, and just 14 are genuinely manual. The hymn reconciliation workbook is a separate
unapproved derived authority: its date-only lane is implemented, but its known-service lane,
immutable provenance, production controls and rerun reconciliation are open under F60-F62.

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
  historical evidence for the pre-D1 manifest only. **Superseded again 2026-08-12 after the
  archive-v10 source-date diagnosis:** the current authority is batch `oos-curated-2026-08-12`,
  manifest `474d32c4…8451`, plan `6795f149…6cda`, 534 included and 1 excluded. It retains the same
  521 distinct identities while correcting `2015-12-20` from `other` to `evening`. See the
  archive-v11 entry below.
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
  remains subject to the production backup, deploy freeze, rehearsal and approval gates;
  a successful local dry run is not production authority. (The witness gate that stood here was
  removed by D10 — one-person project.)
- **Qualified by the 2026-08-12 read-only audit (F60-F62):** those controls are prose-only for this
  command, the workbook is stale against the current corpus, the 5,759 known-service rows are not
  dispositioned and “later matching” is not implemented. Do not use the procedure in this entry as
  production authority until F60-F62 are green.

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
whose observation period outlasts that window; three named role owners (release owner, independent
verifier, rollback owner — **one person may hold all three under D10**, the names are accountability
fields and are not compared for uniqueness); and exact enumerated membership — every named sermon and song
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
approval binding and watchboard to pass a production-shaped rehearsal. (F46's two-person control was
removed by D10; single-operator control replaces it.)

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
The curator's `partial` call was right: promoting it would make projection assert that omitted
items were authoritatively absent.

Decision: flip `2026-06-21-am` to `disposition: exclude` with the reason already recorded in the
revised entry's curation note. The revised document reproduces Laurie's order verbatim with one line
changed (`Hymn (Mark to choose)` → `Hymn 868 'Guide me, O my Great Redeemer'`), so it is a strict
content superset and nothing is lost. Both entries stay `partial`; under the 2026-08-12 shared
completeness policy, the survivor is retained as incomplete evidence and cannot project canonical
order. Re-hash and re-approve: this invalidates manifest `928dccb5…` and plan `ebf486c1…`.
**Applied 2026-08-11** — see the D1 entry below for the replacement batch key, hashes and counts.

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

> **Scope correction (2026-08-12): the "already satisfied" finding covers the apply-step writer
> only.** The 10–12 August commit review found a **second** object writer that this decision never
> considered: `HistoricSermonPublicationService` copies out of quarantine to the **final public
> path** — not an operation-owned key — and compensates by path, so a losing concurrent release can
> delete the winner's published asset.
>
> D6's RPO/RTO, backup and restore decisions **stand and are not reopened**. Only the clause "no
> bucket versioning is required" is now scoped to the apply step. The release step is owned by
> [historic import safety remediation](HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md) **HIR7**,
> whose HIR-D1 determines the required create/receipt primitive. Record HIR-D1's answer here when it
> is taken, so both writers are visibly accounted for against F45.
>
> **HIR-D1 answered 2026-08-12 by measurement against the production Spaces bucket** (full results in
> the safety plan §4.1). Both writers are now accounted for as follows:
>
> - **Create side — solved.** Spaces enforces `PutObject` `IfNoneMatch: *`, refusing a present key
>   with 412 `PreconditionFailed`. HIR7's release writer gets a genuine atomic create-if-absent, so
>   two concurrent releases can no longer both believe they created the final object. This must go
>   through the raw `S3Client`; Flysystem's option allowlist silently drops conditional headers.
> - **Delete side — not solved, and it cannot be.** Bucket versioning is disabled (decided: it stays
>   disabled), and `DeleteObject` `IfMatch` is **silently ignored by Spaces** — a stale-ETag delete
>   succeeded in the probe. There is therefore no exact-ownership deletion primitive available.
> - **Consequence for F45.** D6's "no bucket versioning is required" clause now holds for *both*
>   writers, but for different reasons: the apply step because its keys are operation-owned, and the
>   release step because versioning would not have helped without a working conditional delete.
>   Release compensation is the retained-`orphaned`-ledger path with operator reconciliation, and
>   automatic deletion of a final public object is prohibited outright.
> - **Standing hazard this creates:** orphaned public objects accumulate on failed releases and are
>   only removable by a human. The release runbook needs a reconciliation step; F45 is not met by
>   HIR7 alone.

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

> **Blocking correction (2026-08-12): the accepted log record does not yet exist.** The 2026-08-12
> architectural review verified that production Laravel logging resolves `LOG_CHANNEL=stack` to the
> `single` driver, so `storage/logs/laravel.log` **does not rotate at all** — at `LOG_LEVEL=debug`,
> on the persistent `app-logs` volume. PHP's `error_log` likewise targets a file. Docker's
> `json-file` rotation reaches only stdout/stderr, which today means Nginx and PHP-FPM. Scheduler and
> Horizon are bounded by Supervisor defaults but are invisible to `docker logs`.
>
> D7 declined Sentry on its own merits and that stands. But the alternative D7 chose is unbuilt, so
> the operation would open its window with **no** working monitoring control.
> [Architectural maintainability delivery](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md)
> **AM3 Delivery 1** is therefore a prerequisite of the operation window, and its operator evidence
> for all four log producers must be attached to the operation artifact before D7 can be treated as
> satisfied.

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

**D10 — this is a one-person project; every multi-person control is removed, permanently.**
Decided 2026-08-14. Crockenhill is maintained by one person. There is no second operator, no witness,
no independent verifier and no separate incident commander — not "not yet", and not "not for this
window". Every control in this programme that required a second human is therefore **deleted rather
than waived**, because a per-window waiver invites the next session to reinstate it, and a gate the
only maintainer cannot pass is not a safety control: it is an unreachable gate whose only possible
outcomes are an abandoned import or invented names in a signed artifact. One honest name is better
evidence than four fictional ones.

*Removed by this decision*, in code and in this plan:

| Control | Was | Now |
|---|---|---|
| Production import approval roles | Four distinct people (`incident_commander`, `operator`, `independent_verifier`, `monitoring_owner`), enforced fail-closed | Four **named roles**, one person may hold all four; each must still be non-blank |
| Release authorisation roles | Three distinct people (`release_owner`, `independent_verifier`, `rollback_owner`), enforced fail-closed | Three **named roles**, one person may hold all three; each must still be non-blank |
| Runbook validation (§C.7) | "Have a second operator walk through it" | Verbatim self-rehearsal against the written document; any improvisation still returns it to rehearsal |
| Source acquisition (§D.1, §D.5) | Named custodian *and* witness; two-person approval of the frozen manifests | One named custodian who is also the approver; the manifest freeze and its hashes are unchanged |
| §6 checklist | "two-person control"; "Named operator, witness, incident decision-maker…" | Single-operator control; one named person holding the operator, incident, pastoral/content and takedown roles |

*Deliberately kept*, because a single operator can satisfy them and they still bite:

- Every fail-closed machine check: signature, binding hash, target fingerprint, release identifier,
  operation state, permitted command/phase, unexpired window, exact enumerated membership, declared
  counts carried independently of the id lists.
- The rollback observation window outlasting the authorisation. This is a real constraint on one
  person — it says the person who released the batch is still answerable for it afterwards.
- Every artifact, hash, journal entry and retained report. Evidence does not need a second reader to
  be evidence; it needs to be reproducible, and it is.
- The roles themselves as **fields**. They are the durable record of who to call and who owns
  rollback, and they stay required and non-blank.

*Checked and deliberately kept, because they are not second-person controls.* D7's required
reviewers on the GitHub `production` environment and the `CODEOWNERS` entries both resolve to
`@GarethClarridge`, so they are a self-approval speed bump, not another human. Configure required
reviewers **without** "prevent self-review", and they do exactly the job D7 wanted: stop a master
merge from silently auto-deploying into a frozen window. If self-review is ever disabled, that turns
them into a second-person gate and D10 says to fix the setting, not to add a person.

*Accepted consequences, stated plainly.* There is no separation of duties: the person who curates a
manifest also approves it, and the person who releases a batch also verifies it. A single mistaken
judgement will not be caught by a second reader. The word "independent" in `independent_verifier`
now names a role, not an independent human — it never meant a cryptographically independent one
either (HIR-D3 already established that the application holds the symmetric signing key, so no
signature here has ever attested verifier independence). Self-review of the runbook is weaker than
peer review, and that is the residual risk this project accepts in exchange for an import that can
actually be executed.

*The rule going forward.* Do not reintroduce a distinctness check, a witness, a second operator or a
two-person approval anywhere in this programme. `HistoricImportApprovalManifest` and
`HistoricSermonReleaseAuthorisation` both carry this decision in their class docblocks, and
`one_maintainer_may_hold_every_operational_role` and `one_maintainer_may_hold_every_release_role`
fail if anyone adds one back. If a genuine second person ever joins, that is a new decision that
supersedes this one in writing — not a silent restoration.

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
8. ~~Remove the multi-person controls from the approval and release gates, their tests and this
   plan's prose (D10).~~ **Done 2026-08-14.** `HistoricImportApprovalManifest` and
   `HistoricSermonReleaseAuthorisation` no longer compare role names; the §C, §D and §6 text below is
   rewritten for one operator. Note that the *approval and release artifacts themselves are
   unaffected in shape* — the `roles` objects keep all their keys, so no manifest, hash or fingerprint
   is invalidated by this change and nothing loops back through §13.6.

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

**The measurement F2 demanded, rerun against the then-current authority.** The 219-of-391 figure was
for the superseded 404-entry manifest. Against `oos-curated-2026-08-11`, the working database holds 408
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

### 2026-08-11 — second staging run: no defects left, and the real blocker is now visible

Re-run over the complete corpus into a freshly provisioned and certified database, so every entry
re-parsed rather than reusing the first run's caches — the extractor fixes only exercise on a real
call. Report: `storage/scratch/rehearsal-staging-2026-08-11-run2.json`.

**Zero errors, down from three.** Dispositions are now 462 `held_for_review` and 72 `created`, with
no `failed`, no `import_failed` and no entry carrying an error at all. Each fix is confirmed on the
data that exposed it: `2018-09-23` now parses and holds at 0.74 instead of failing the identity
column; `2020-03-29` parses and holds at 0.70 instead of losing the service to one unusable
response; and `2026-03-15-am-second-hand` parses at **0.93 — above the auto-import bar — and is
still held**, carrying `superseded_predecessor_not_imported`, because its predecessor held at 0.86.
That last one is the chain rule working exactly as intended.

**F1 holds again**: 90 services, of which 69 carry an approved manifest identity and 21 are
explained excess, with **0 unexplained**.

**The run still exits 1, and now for the honest reason.** `hasUnsettledResults()` counts
`held_for_review` as unsettled, so F32's closeout refuses while any approved item awaits a human
decision. With no defects left, the exit code is measuring exactly one thing: **462 of 534 entries
are in the review inbox**. The corpus operation cannot complete, and step 4's census cannot be
meaningful, until that population is worked down. That is a corpus and threshold judgement, not a
defect to fix.

**New finding — the staging outcome is not reproducible run to run.** Identical inputs, identical
manifest and identical code produced 98 services in run 1 and 90 in run 2; date accuracy moved
71.7% → 70.4% and auto-import precision 73.0% → 71.8%. The cause is the extractor: confidences
vary per call, and entries near the 0.90 bar cross it in either direction. **Reruns are stable only
because parse results are cached**, so the no-op rerun G3 requires is a property of the parse cache,
not of the model. Any procedure that re-parses — a fresh rehearsal database, a cache invalidation, a
`--fresh-parse`, or a `ParserVersion` bump — will produce a materially different held/created split.
This needs stating in the runbook, because "run it again and get the same answer" is currently true
for the wrong reason.

**Runbook rule:** treat the persisted parse cache, identified by the entry input hash and parser
version, as part of the staging evidence. A no-op rerun is only a replay of that evidence; it is not
proof that a fresh extractor call is deterministic. Do not use `--fresh-parse`, invalidate the cache,
or bump `ParserVersion` while comparing rehearsal outcomes. Any such change starts a new evidence
run and requires its own report and threshold decision.

**This entry closes no gate.** Step 3 still exits non-zero by design, and G5 remains unclaimable
while the review population stands.

### 2026-08-11 — archive-v8 balanced calibration sample

The next operator action from the second staging-run handoff was completed without importing any
canonical service or changing the 0.90 auto-import threshold. `oos:import-archive --evaluate` ran
against the current 534-entry approved manifest with parser `archive-v8`. The date filters produced
69 entries spanning every archive year from 2014 through 2026: 50 full and 19 partial records, 44
curated morning, 19 evening and 6 other identities, 8 superseding entries and 18 dates carrying
multiple selected entries. The run used the normal `(input_hash, parser_version)` cache policy,
without `--fresh-parse`; the version bump therefore started a new evidence set as the runbook rule
above requires. Report: `storage/scratch/archive-v8-balanced-sample-2026-08-11.json`.

**No processing failure occurred.** All 69 entries produced a result; 32 were eligible and 37 held
for review. Every missing or wrong date was held: date accuracy was 51/69 (73.9%), split 42/50
(84.0%) for full records and 9/19 (47.4%) for partial records. The five non-null wrong dates and all
thirteen null dates therefore produced no silently admitted wrong identity.

**The report's raw precision figures need the same F1 interpretation as the full staging runs.** It
reports 31/38 (81.6%) eligible-plan precision and 13/15 (86.7%) accuracy in the 0.90–1.00 confidence
band because `exact_correct` treats every plan outside the entry's single curated service as false.
All seven apparent eligible misses were flagged `service_beyond_manifest`. Reading their verbatim
sources confirmed that every one is a real, separately bounded service in a multi-service email;
the two at or above 0.90 were the explicit morning section in `2016-04-24` and explicit evening
section in `2022-11-06`. On the approved F1 rule, adjudicated eligible-plan precision is therefore
38/38 and the 0.90–1.00 band is 15/15. The threshold stays at **0.90**.

This sample clears the immediate archive-v8 calibration question, but it does not establish the
whole-corpus review population or complete step 3. **Do not begin mass review from this sample.**

Source inspection of the 37 held entries then identified five avoidable parser defects. They are
implemented in `archive-v9`: archive parsing now uses the corpus's recorded `source_date`; service
slot is separated from special-service occasion; strong subject and 5pm/afternoon/tonight evidence
can support a single evening plan; response line IDs are constrained to the source while legitimate
in-plan context is allowed; and a single non-contradictory full plan may inherit its approved
manifest identity. Partial sources and explicit identity contradictions still hold, and the
auto-import threshold remains **0.90**. The 534-entry deterministic archive-v9 reconciliation passed
on 2026-08-11.

The parser-version change starts a new evidence set. The next operation is therefore a new balanced
calibration sample using archive-v9, not a full staging run or mass review. Only after that sample
passes should a fresh clean-database full staging run size the review inbox and decide whether step
4's census is meaningful. This entry closes no G-gate.

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
verifier and monitoring owner — **under D10 these are four role fields the one maintainer may hold
simultaneously, not four people**; pre-authorise numeric abort thresholds. Use Sentry or a formally
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
| 0. Decide and design | Now; no corpus access | F1 and all business decisions have named owners — **all §5 rows decided 2026-08-11**; F29-F65 have accepted designs and testable acceptance criteria |
| 1. Harden code/runtime | Phase 0 decisions affecting schemas/contracts are made | Required fixes pass focused/full quality gates; operation artifacts and runtime are version-pinned |
| 2. Acquire and curate | F36 protocol, capacity, protected copies and malware tooling are ready | Signed whole-drive inventory; original protected; approved OpenLP/OoS/video manifests with zero unaccounted paths, including the current 533/261 Email inventory |
| 3. Definitive local processing | F31-F39, F47-F50, F52-F55, F59, F63, F64 and F65 are green; manifests are approved | Every checkpoint exact-complete; output/cost/capacity ledgers reconcile; no unresolved live/timed-out work; F60 hymn reconciliation is regenerated against the converged corpus |
| 4. Production-shaped rehearsal | G1-G5 and F60 plus clean different-PK environment | Exact Bundle A/B and F61-F62 hymn apply, audit, complete no-op rerun, crash/resume, restore/rollback and public/private smoke all pass |
| 5. Production apply | G6/G7 accepted; every open F29-F65 finding green; command-exact runbook approved | One mutation pass, exact audit/no-op closeout, recovery evidence retained, no audience expansion |
| 6. Editorial follow-up | Exact import accepted | Titles/occasions and ordinary corrections improve without re-importing or changing source provenance |

### A. Close the known engineering and data gates

**The phase table above governs ordering.** Each item below names the earliest phase its finding
must be green for; where the two could be read differently, the table wins. An item that also
appears in a later phase's work is doing implementation there, not relaxing its gate.

1. ~~Re-inventory and re-curate the expanded local Email roots, then decide F1.~~ **Done
   2026-08-09, re-curated 2026-08-11 (D1), corrected 2026-08-12 after archive-v10:** the approved
   replacement holds 535 entries — 534 included and 1 excluded — and 521 identities, including the
   three current-era entries. Current batch `oos-curated-2026-08-12`, manifest
   `474d32c…8451`, plan `6795f149…6cda`. F1 uses that exact set and permits only hash-covered
   `service_beyond_manifest` identities; unexplained extra or missing services fail closed.
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
7. Add F57-F62 to a single operation closeout command/report which fails unless every approved source item,
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
11. ~~Fix F63 before the fresh full archive-v11 staging run so the review population is measured
    after curated content scope, while extra unknown-scope services remain held.~~ **Done
    2026-08-14** — and note the trap: this step was marked satisfied on 2026-08-12 while the fix had
    not landed, so every review population measured before 2026-08-14, including the 2026-08-13 F1
    restage, measured the unfixed behaviour.
11a. ~~Fix F64 and F65 before the next staging run, in that order.~~ **Done 2026-08-14, including
    corpus proof.** The fresh 534-source run is recorded in
    `docs/reports/historic-import-f64-f65-parser-follow-up-2026-08-14.md`; its report SHA-256 is
    `b698f3a56e5251e68ba3c800f240c55d3f51b26c0a0567678bbd4c4da4b2aa7c`. F64 produced zero phantom
    source-line failures. F65 left bookkeeping-only findings reviewable rather than unreachable.
    The measured result is 196 service rows covering 159 of 521 approved identities, with 373
    sources held; parser follow-up now starts with a report-level reason census rather than an
    asserted small manual residue.
12. Resolve F60 after Email/OpenLP/Livestream convergence: regenerate and hash-bind the historic
    hymn reconciliation, then disposition all 5,759 known-service rows and reconcile every source
    overlap. Do not import only the convenient lane and call the workbook complete.
13. Fix F61-F62 before the hymn production apply: bind it to the approved operation/artifact/counts,
    record exact outcomes and implement true no-op/later-resolution/canonical-link reconciliation.
14. Run focused tests, PHPStan, Pint and the full parallel suite for each release candidate. Run
    Dusk/read-side tests for the unchanged visibility boundary and editorial corrections.
15. Run the full remediation-plan rehearsal, including census convergence, calibration, checkpointed
   media processing, residual review, linked Bundle A/B export, different-PK import, exact audit,
   the operation-bound hymn lane, complete second no-op run, backup restore and apply/rollback
   repetition.

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
   F29-F65 gates. Delete obsolete fixed counts, mandatory-Manual assumptions and commands missing
   current operation ID/expiry arguments.
2. Include exact commands, arguments, artifact paths, expected output/exit code, operator,
   evidence captured, abort condition and rollback action for every step. (D10: no witness column.)
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
7. Rehearse the document verbatim with timings and screenshots/reports. Any prompt-time
   improvisation or undocumented command makes the runbook unapproved and returns the operation to
   rehearsal. **D10 removed the "have a second operator walk through it" step** — there is no second
   operator. The verbatim rehearsal is what replaces it, and it is the stronger half anyway: a
   reader can miss a wrong command, but executing the document as written cannot.

### D. Source acquisition, custody and preservation

1. Write the F36 acquisition procedure and capacity plan before connecting the drive. Name the
   custodian, evidence locations and disposition for a failing/unreadable source. (D10: no witness;
   the custodian is the maintainer.)
2. Connect only for read-only acquisition: identify the physical device and filesystem; record
   health/read errors; prove the original is non-writable/non-executable; make and independently
   verify two protected copies. Never point an importer at the original.
3. Produce a whole-filesystem inventory, not an importer extension-filter inventory. Every regular,
   hidden, unsupported, sidecar, directory, symlink/alias/hard link and read error receives an
   explicit, signed disposition. Preserve raw path bytes/Unicode form and detect case/normalization
   collisions. **Tooling landed 2026-08-14 (F66):** `historic-import:draft-source-dispositions`
   enumerates the tree and emits the worksheet; the adjudication is still the operator's, and the
   worksheet is where the per-class written reasons live.
4. Malware-scan in isolation. Preserve an untouched evidence image/copy and generate a separately
   hashed working tree; materialize approved symlinks only in that working tree with a signed map.
5. Build strict source manifests from the complete inventory, adjudicate every include/exclude/
   duplicate/correction/identity collision, approve and freeze them. Bind the
   working-copy/drive identity into the operation context. **The custody half is executable as of
   2026-08-14 (F66)** via `historic-import:capture-source-acquisition`; the **video** curation
   manifest still has a validator and no builder, and must not be hand-written for the same reason
   the custody artifact could not be. **D10 replaced the two-person approval
   with a single named approver**; what carries the weight is the written reason on every
   include/exclude and the frozen hashes, both of which survive unchanged.
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

### 2026-08-12 — incomplete Email sources retained as shared evidence

The archive-v8 sample's 19 partial sources exposed a pipeline-wide distinction, not an archive
exception. A source can contain reliable hymns, readings, sermon details or notices without
claiming to contain the complete running order. Discarding it loses evidence; projecting it as a
complete plan turns silence into false absence or order.

The Email extractor now returns a per-service `content_scope` of `full`, `partial` or `unknown`.
This is independent of extraction confidence. High-confidence partial plans use the ordinary Email
ingestion path but persist their source revision with `payload_complete=false`; their assertions
remain attached to the resolved service identity and the terminal outcome is `evidence_retained`.
They do not create, remove or reorder canonical service items. Unknown completeness remains in the
review inbox, where the operator can either approve a complete plan or choose **Retain as evidence**.

The rule is enforced again in `ChurchServiceProjector`: incomplete active machine records cannot
contribute to any later projection or audit. This makes `payload_complete` a safety property rather
than descriptive metadata. The same behavior applies to ordinary live emails. Archive-specific
code is limited to translating the curator-approved manifest scope and service identity into that
shared contract, plus reporting `evidence_eligible`/`evidence_retained` outcomes. Partial archive
entries no longer produce the generic `partial_source_scope` hold.

The prompt/schema and projection policies changed, so archive parsing is now `archive-v10`, the
portable Email plan projector is `email-plan-v2`, and the normalized projection policy is version
2. The 0.90 auto-import threshold is unchanged. Existing archive-v9 calibration evidence remains a
historical result; the next balanced sample must use archive-v10 before any clean full staging run
or mass review.

### 2026-08-12 — archive-v10 balanced calibration sample

The required archive-v10 calibration ran against the same 50-date cohort as archive-v8, selecting
the same 69 approved entries: 50 full and 19 partial. It used the normal versioned parse cache,
made no import or inbox mutation, and did not change the 0.90 auto-import threshold. Report:
`storage/scratch/archive-v10-balanced-sample-2026-08-12.json`.

All 69 entries produced results with no processing failure. Of the full sources, 44 were eligible
and 6 held for review. Of the partial sources, 15 were `evidence_eligible` and 4 held; none was
treated as a complete canonical plan. Date accuracy rose to 61/69 (88.4%), including 46/50 full
and 15/19 partial sources. All four wrong non-null dates and all four missing dates were held.
Morning recall was 100% and evening recall was 93.3%, both above the 0.85 recall floor.

The report records raw eligible-plan precision as 44/47 (93.6%). Its three apparent misses are
`service_beyond_manifest` plans in `2015-06-07`, `2016-04-24` and `2022-11-06`. These are a subset
of the seven verbatim-source cases adjudicated during the archive-v8 sample; each is a real,
separately bounded service in a multi-service email. Under the approved F1 rule, adjudicated
eligible-plan precision is therefore 47/47 (100%), above the 0.98 stop floor. The 0.90 threshold
remains unchanged.

This sample passes the archive-v10 calibration requirement. The next operator action is a fresh
clean-database full staging run to size the complete review and retained-evidence populations.
Do not begin mass review from the sample itself.

**Correction after hold-source diagnosis:** this conclusion is superseded. Archive-v10 invalidated
the parse cache but did not refresh an existing synthetic email's `received_at` when the approved
payload bytes were unchanged. Four relative-date results therefore used archive-v8's old
`resolved_date - 2 days` timestamp rather than the source email date, exactly producing the four
observed one-day errors. The other missing dates exposed an overly narrow archive fallback that
could bind an approved date only when the extractor returned one plan. `2015-12-20` was also
mis-curated as `other` even though its named Carols by Candlelight plan is the evening service.

The shared extractor now receives the actual received date with its weekday and a deterministic
two-week date/weekday calendar. Its prompt treats subject-level relative or named dates as evidence
for every non-contradictory plan, which applies equally to routine live emails. Archive-only code
now refreshes all verified synthetic message fields before any parse and may fill a missing date on
each plan from the approved manifest, but refuses the whole fallback if any explicit parsed date
contradicts it. These changes start parser `archive-v11`; archive-v10 does **not** authorise full
staging.

The corrected `oos-curated-2026-08-12` manifest reclassifies `2015-12-20` as `evening`, retaining
Carols by Candlelight in its title rather than the `other`-only `service_label`. The operator
approved this exact candidate on 2026-08-12. Its deterministic full-corpus validation passed with
534 included entries, 1 exclusion and zero identity disagreements. Manifest hash:
`474d32c44284af7d1ef35b20f5454a5feab5609dac2626e5ad7e66bfd6ed8451`; plan hash:
`6795f1497d54d85baac353d026544445f78a151ad0c77c254cf58ce9ba016cda`. The 2026-08-11 hashes are
now historical evidence for the preceding authority.

### 2026-08-12 — archive-v11 balanced calibration sample

With explicit operator authorisation for the configured external extractor, archive-v11 evaluated
the exact same 50-date, 69-entry cohort as archive-v10: 50 full and 19 partial sources. It used the
normal `(input_hash, parser_version, received_date)` cache policy and performed no canonical import
or inbox release. Report: `storage/scratch/archive-v11-balanced-sample-2026-08-12.json`.

All 69 entries produced results with no processing failure. Date accuracy was 69/69, including
50/50 full and 19/19 partial sources. Morning and evening recall were both 100%. All 50 full entries
were archive-eligible and all 19 partial entries were evidence-eligible; no entry failed archive
identity corroboration. Low parser confidence remains an ordinary plan-level review signal rather
than an archive identity failure, and the 0.90 automatic-import threshold remains unchanged.

The report's raw eligible-plan precision is 50/57 (87.7%). Its seven apparent misses are genuine
additional service sections flagged `service_beyond_manifest`: `2015-06-07` evening, `2015-12-20`
morning, `2016-04-24` morning, `2019-12-22-carols` morning, `2022-11-06` evening, `2023-12-17`
evening and `2025-07-20` evening. Their verbatim headings/content identify those services; the F1
rule permits them rather than treating a manifest's single naming identity as an exhaustive claim.
Adjudicated eligible-plan precision is therefore 57/57 (100%), above the 0.98 stop floor.

The archive-v11 calibration passes. The next operator action is a fresh clean-database full staging
run to measure the complete plan-level review and retained-evidence populations. Do not begin mass
review from the sample itself.

### 2026-08-12 — archive-v11 clean-database full staging run

> **Superseded 2026-08-13 by HIR2. Do not cite the populations below as current.** This run predates
> `271d68604` by a day and reused cached parses resolved under the pre-HIR2 contract, which the
> safety addendum's invalidation ledger classifies as unusable. Pre-HIR2 cache metadata is version 0
> — retained, never reusable. The replacement measurement, its differences from this one, and the
> parts of this entry that are **not** yet replaced are recorded under
> [2026-08-13 — HIR0–HIR7 landed](#2026-08-13--hir0hir7-landed-pre-hir-evidence-invalidated-and-partly-remeasured)
> below. The report file and its hash remain valid as an archived artifact of what this run did.

With explicit operator authorisation for the configured external extractor, the complete approved
Email corpus was staged into a freshly provisioned and certified `crockenhill_rehearsal` database.
The process-scoped `DB_DATABASE` override was resolved by Laravel before the run; the working
`crockenhill` database was not the import target. The run reused only matching archive-v11 parse
evidence and did not use `--fresh-parse`. Report:
`storage/scratch/rehearsal-staging-archive-v11-2026-08-12.json`; SHA-256
`22f97b3761a66bdfe93da1d9441b2531e1caa39374fef94bea235a136904a47a`.

All 534 approved sources were processed with zero processing or import failure and zero adjudicated
identity disagreement. Source dispositions were 109 `created`, 18 `evidence_retained` and 407
`held_for_review`. The actual inbox population is 419 pending sources containing **545 plans**: 299
`review_required`, 231 `invalid_extraction` and 15 `auto_importable` plans held by source-level
safeguards. The retained normalized-evidence population is **155 plan outcomes**: 126 `created` and
29 `evidence_retained`, producing 155 services, 155 source records, 1,572 canonical items and 1,783
item assertions. Partial sources remain evidence rather than being promoted to complete plans.

F1 holds against the staged writes. Of the 155 retained plan identities, 122 occupy a service slot
named by the manifest and 33 are same-date additional services permitted by
`service_beyond_manifest`; zero retained plan has the wrong date and zero extra identity is
unexplained. Report-level date accuracy is 525/534 (98.3%), morning recall 415/419 (99.1%) and
evening recall 39/40 (97.5%). The report's raw 442/573 auto-import precision again treats every
additional service as false; it is not the adjudicated F1 precision of the 155 writes.

The command exits 1 because F32 correctly treats every pending source as unsettled. There are zero
merge proposals. Step 4's proposal census would therefore describe only the 155 projected services,
not the approved corpus, while 419 sources and 545 plans remain pending. **Do not begin mass review
or claim G5.** First classify the pending plan population into automatable and irreducible classes;
then reset and restage before projection/census. This run completes F63's required authoritative
population measurement but closes no broader readiness gate.

### 2026-08-12 — F60: the historic hymn workbook is only partially integrated and is stale

**State: open; source-accounting and cross-source convergence blocker. Read-only audit finding.**

The reconciliation workbook contains two materially different evidence lanes. `Ambiguous Usage`
holds 1,941 date-only song statements with no morning/evening identity. `Known Usage` holds 5,759
date-and-service statements: 1,013 labelled already represented, 132 for review on an existing
service, 643 awaiting a pending import and 3,971 on candidate new services. The current reader
hard-codes `Ambiguous Usage`; no importer, manifest, closeout or accepted exclusion accounts for
the 5,759 known-service rows. Importing only the date-only lane is conservative, but it is not a
complete integration of the workbook unless the known-service lane receives an explicit terminal
disposition.

The workbook was generated on 2026-08-09 against the then-current database and pending OoS
manifest. Archive-v11 and `oos-curated-2026-08-12` now establish identities the workbook still calls
`Candidate new service`. At least 25 known-service rows are stale across `2015-06-07` evening,
`2015-12-20` morning/evening and `2016-04-24` morning. The workbook itself says to rerun the
reconciliation after the pending corpus lands, but the generation procedure is not retained in the
repository and neither its SHA-256 nor the hashes of its four source workbooks are operation
authority. The audited workbook digest is
`4a4a7a1524b867184864a334399426f86d0c770b3ff7562cd4b0832f35e2b3b7`; recording it here is evidence,
not approval.

A read-only comparison with the local `prod-20260326.sql` snapshot found no overlap between its
`play_date` song/date identities and the 1,867 catalogue-resolved ambiguous rows. Six known-service
song/date/service occurrences did overlap. This is useful evidence that the date-only lane does not
currently duplicate that snapshot, but it is not a production zero-loss/deduplication proof and it
reinforces that the known-service lane must reconcile all sources together.

**Required outcome:** after exact Email/OpenLP/Livestream convergence, regenerate the workbook from
an auditable retained procedure against the exact staged corpus and deployed song catalogue. Bind
the four source workbook hashes, generated workbook hash, catalogue fingerprint, service-corpus
membership/hash and generation policy into the operation evidence. Account for every row on both
usage sheets exactly once as imported evidence, linked canonical evidence, approved duplicate,
approved exclusion or unresolved review. Do not create a service merely because the hymn workbook
names one, and do not treat manifest identity overlap as song-level coverage.

**Proof required:** source-to-derived row counts and hashes reproduce; all 7,700 rows have one exact
disposition; the known-service lane is reconciled after canonical convergence; cross-source
duplicates including `play_date` are detected; every imported statement retains source workbook,
sheet and row; no omitted or duplicated occurrence can pass closeout.

### 2026-08-12 — F61: the hymn mutation path is outside the production operation controls

**State: closed 2026-08-13.** `--import` now requires `--operation`, calls
`HistoricImportProductionGuard::refusalFor()`, and refuses a workbook whose SHA-256 differs from the
owning operation's `historic_song_usage` manifest binding — checked before the read and again inside
the write transaction. `--expect-rows/--expect-resolved/--expect-unresolved` carry the recorded
dry-run contract and refuse count drift while the run is still a no-op. Written rows are bound to the
operation and land `quarantined`; both public read paths
(`SongUsageQuery::occurrences(publicOnly: true)`, `PublicSongUsageService`) filter to `published`,
and `historic-import:release-batch` gained `song_usage_report_ids` so the lane becomes public only
through the signed batch. Each run writes an operation-owned `song-usage-import-NNN` artifact naming
every row; `HistoricSongUsageCloseout` re-derives that membership from the database and
`HistoricImportOperationalCloseoutEvidence` (version 3) carries a `song_usage` block that refuses on
any difference.

**The maintainer decision this needed** — read-side visibility accepted immediately, or held to the
operation's release point — **was taken 2026-08-13: held.** Hence the quarantine default on
`song_usage_reports.publication_state`, which deliberately differs from the `sermons` and
`song_videos` default of `published`: those tables hold non-historic rows that were already public,
this one has no writer but the historic hymn importer.

**Original finding, retained.**

`service-tracking:import-historic-song-usage-reports --import` writes immediately. Unlike the OoS,
OpenLP and historic-video paths, it does not call `HistoricImportProductionGuard`, bind itself to a
persisted historic operation/freeze/approval, require an approved workbook digest, enforce the
recorded 1,941/1,867/74 dry-run contract, or contribute per-item outcomes to exact closeout. The
plans say the ordinary backup, freeze and approval gates apply, but code does not enforce
that claim. (As written in 2026-08-07 this sentence also said "witness"; D10 removed that gate
outright, so it is struck here rather than carried forward as work.) Resolved rows join public/admin song usage reads as soon as they are committed, so this
is also a controlled-release concern rather than an inert staging write.

**Required outcome:** make the date-only lane an explicit operation-owned source kind or artifact.
Production mutation must require the same signed approval, target/release identity, freeze and
expiry controls as the other importers; verify the exact workbook digest before reading and again
before commit; fail before writes on count/match drift; record every row's outcome and report digest;
include the lane in rollback, no-op and closeout evidence. Define whether its read-side visibility is
accepted immediately or held until the operation's release point.

**Proof required:** unapproved, expired, wrong-target, wrong-release, changed-workbook and wrong-count
applies fail with zero writes; the exact approved artifact imports under the operation; closeout
cannot pass without all 1,941 rows; backup/restore and visibility smoke are exercised; the second
apply is an exact operation-bound no-op.

### 2026-08-12 — F62: hymn reruns do not reconcile existing or newly matchable evidence

**State: closed 2026-08-13.** `firstOrCreate` on `source_fingerprint` is replaced by a read-only
planning pass over every row, then an apply pass. Each row resolves to one
`HistoricSongUsageRowOutcome`: created, unchanged, resolution available/applied/conflict, canonical
link available/applied/ambiguous, or source drift. Resolution conflicts and source drift refuse the
whole run before the first write; catalogue resolution and canonical linking apply only under
`--resolve-catalogue-matches` and `--link-canonical-occurrences`. The reported `resolved`/
`unresolved` counts are read back from the database after the apply, which is the specific defect:
they were previously computed from the fresh resolver call while the stored `song_id` stayed null.

**Original finding, retained.**

The importer calculates the current catalogue match and then uses `firstOrCreate` on only
`source_fingerprint`. An existing row is counted as `resolved` from the new calculation and
`Already present` from persistence without comparing or updating `song_id`, match method or any
other stored field. A row imported unmatched therefore stays unmatched after a catalogue correction,
even though the rerun reports it as resolved. The documented promise that 74 rows are retained “for
later matching” has no implemented reconciliation path. Likewise,
`resolved_church_service_item_id` prevents double counting once populated, but no historic-lane
workflow populates it when later source convergence proves a canonical occurrence.

**Required outcome:** define an exact, auditable reconciliation policy for existing reports. A no-op
rerun must compare every immutable source field and current resolution with stored state, fail on
unexpected drift, and distinguish true no-op from an authorised catalogue-resolution or canonical-
item linkage update. Define ambiguity handling and never silently move an occurrence between songs.

**Proof required:** unmatched-to-matched, match-change, source-field drift, canonical-item link and
already-correct cases have distinct outcomes; only approved resolution/link changes update; linked
reports disappear from the union exactly once; a second pass is all no-op; closeout records the 74
original unmatched rows' final or deliberately unresolved disposition.

### 2026-08-12 — F63: archive-v11 computes eligibility before applying curated scope

**State: reopened 2026-08-14, then closed 2026-08-14.** The 2026-08-12 closure was wrong: the
required outcome was never implemented and no test covered the stated proof. `resolvedDisposition()`
still read `$plan->contentScope` — the scope arriving with the plan — while
`withCuratedContentScope()` constructed the replacement with the curated scope. The classifier
therefore held the plan on the very value that call was discarding. The 2026-08-12 note that the
"corrected classifier" was in place describes work that did not land; treat any measurement that
relied on it as measuring the *unfixed* behaviour, including the 2026-08-13 F1 restage.

The proof the original entry demanded is now a test —
`OosArchiveIdentityResolverTest::a_corroborated_unknown_scope_plan_is_classified_against_the_curated_scope`
— which failed on the pre-fix tree at the `isAutoImportable()` assertion while the scope assertion
above it passed, isolating the defect exactly: scope applied, adjudication stale.
`resolvedDisposition()` now takes the scope the replacement plan will carry as an explicit argument;
the two identity call sites pass `$plan->contentScope` and so are unchanged in behaviour, and only
the curated path sees a different value. The existing guard that an extra, non-corroborated plan
keeps its own `unknown` scope still passes.

**Measured blast radius** (2026-08-13 restage, 691 selected-attempt plans): 11 plans carried a
parse-time `unknown` scope; curation supplied a real scope for 6 of them; for exactly 1 —
`2022-07-31`, consensus, confidence 0.78 against a 0.75 review threshold, curated `full` — the stale
read was the *sole* remaining blocker. The defect does fail safe, as originally judged. What the
original entry got wrong was believing it had been fixed.

**Original closure note, retained as the record of the error.** "The defect was safe over-hold, not a
wrong-write path. The corrected classifier, balanced archive-v11 calibration and clean-database full
staging measurement are recorded above."

Before the fix, `OosArchiveIdentityResolver::applyCuratedContentScope()` asked
`resolvedDisposition()` to classify the original plan and only then constructed a replacement
carrying the manifest-approved `full` or `partial` scope. If the extractor labelled a corroborated
plan `unknown`, the classifier saw that old scope and retained `ReviewRequired` even though curation
had just established the scope. Extra, non-curated plans correctly kept their own unknown scope. The
defect therefore failed safe, but inflated the review population until the corrected classification
and fresh staging run replaced that measurement.

**Required outcome:** classify each corroborated plan using the curated scope being assigned while
preserving every other disposition guard—invalid extraction, validation reasons, special service,
confidence and disagreement. Never apply the entry's scope to an extra service not corroborated by
its curated identity.

**Proof required:** a corroborated high-confidence unknown-scope plan becomes eligible as curated
`full` or `partial`; low-confidence/invalid/special plans still hold; extra unknown plans still hold;
the balanced calibration is rechecked if behavior changes its cohort result, then a fresh full
archive-v11 staging run measures the authoritative review/evidence populations.

### 2026-08-13 — HIR0–HIR7 landed; pre-HIR evidence invalidated and partly remeasured

The [safety remediation addendum](HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md) was raised
because a review of commits `ada4b0483e`..`ac1468b472` found eight defects, three High. Its code
packages **HIR0–HIR7 are all committed** as of 2026-08-13 13:20. HIR8 — the production-shaped
rehearsal that turns those fixes into gate evidence — is not, and **production remains NO-GO**.

Landing code does not close anything in this plan. The rows below record what shipped so that the
gates can be re-evaluated by their owners; each gate stays open until all of its other pre-existing
requirements are met too.

| Package | Commit | What it closed | Verified by |
|---|---|---|---|
| HIR0 | `d8a171af1` | Change-control baseline. Eight non-vacuous red tests, one per review finding, each proven to fail for its own finding's reason and tagged `#[Group('hir-red')]`. No production code. Records HIR-D1/D2/D3. | `HistoricImportReleaseCandidateBaselineTest` plus the seven per-finding red tests; `phpunit.xml` group |
| HIR1 | `8843cd245` | Finding 4. The guard compared a whole target fingerprint mixing identity with release id, migration batch/count and pipeline settings, so drift disarmed it. `HistoricImportResourceIdentity` isolates the stable half; the guard now compares the production **database** anchor alone and fails closed on a malformed anchor, one digest in both variables, a lingering `…_TARGET_FINGERPRINT`, or a driver with no stable server identity. | `HistoricImportProductionGuardTest`, `PrepareHistoricImportOperationCommandTest`, `RehearsalDatabaseProvisionerTest` |
| HIR2 | `271d68604` | Finding 5. The parse cache keyed a *resolved* parse on bytes/parser/date, none of which carry the curation plan, so a re-curation could not invalidate it — full→partial being the sharpest case. `OosArchiveParseCacheBinding` makes raw extraction cacheable and re-applies identity, scope and supersession from the current entry on every run. Pre-HIR2 metadata is version 0: retained, never reusable. | `ImportOosArchiveCommandTest`, `InboundEmailImportServiceTest` |
| HIR3 | `f4341d4d4` | Finding 6. A null `scripture_passage` with a missing outcome returned the same answer as an approved terminal absence, so an unsettled Bundle A passed the zero-write preflight. `keyFor()` is now an exact exclusive union; `scripture_passage_outcome` is **required** in `HistoricNormalOutputContract` (version 5). Two adjacent defects fixed: the metadata serializer dropped the outcomes entirely, and the inventory read raw model metadata where the destination stores the serialized view. | `HistoricScripturePassageRequirementsTest`, `EnrichHistoricScripturePassagesCommandTest`, `HistoricProcessingResultRoundTripTest` |
| HIR4 | `4ea6749b3` | Finding 2 (High). The acquisition gate accepted two writable sibling folders on one disk as two independent protected copies; its only check was that `realpath()` differed. Independence is now the failure domain — `sha256(mount source \| device)` — and protection is a real write probe, not a mount option. Custody artifacts are version 2; version 1 cannot satisfy the repaired gate. | `VerifyHistoricSourceAcquisitionCommandTest` (Darwin + Linux inspectors, `FakeHistoricSourceFilesystemInspector`) |
| HIR5 | `ed9da7df7` | Finding 3 (High). Placeholder digests and booleans in an unsigned JSON document satisfied the mandatory recovery gate, and one backup object was presented as both the on-host and off-host restore. Version 2 is signed and verified **before any path is opened**; `HistoricImportRecoveryArtifactResolver` recomputes size and SHA-256 and refuses two artifact ids resolving to one inode. Per HIR-D3 the signature reuses the approval key and **claims no verifier independence**. | `VerifyHistoricImportRecoveryCommandTest`, `HistoricImportRowManifestTest`, `BuildHistoricImportRowManifestCommandTest` |
| HIR6 | `3379e8f74` | Finding 7. `dispatched` records a queue handoff, not an outcome, but closeout accepted it as terminal — so an operation could complete exact closeout while an order of service that arrived during the freeze had not been imported. The contract is now pending → dispatching → dispatched → **processed**, with only `processed` plus a non-null `processed_at` terminal. One additive migration; no data repair in schema. | `ImportIngressGateTest`, `VerifyHistoricImportOperationalCloseoutCommandTest`, `HistoricImportOperationCloseoutTest` |
| HIR7 | `92c334bc7` | Finding 1 (High). `copyVerified()` decided ownership with `exists()` then `writeStream()` and compensated from a process-local list, so two attempts could both write and the loser's rollback could delete the winner's published asset. A claim now exists before any byte moves: `destination_identity` is `sha256(disk\|path)` with **global** uniqueness. Writes go through `HistoricReleaseObjectStore` — `fopen($path,'x')` locally, raw S3 `IfNoneMatch: '*'` on Spaces, never the Storage facade. | `HistoricSermonReleaseOwnershipTest`, `HistoricReleaseDestinationGuardTest`, `HistoricSermonReleaseBatchTest` |

#### What these packages invalidate in this plan

Per the addendum's §14 invalidation ledger, mapped onto this plan's evidence:

| Package | Invalidates here | Status |
|---|---|---|
| HIR1 | Any operation/approval prepared on the old fingerprint composition; G8 config proof | Open — no new operation prepared |
| HIR2 | The 2026-08-12 archive-v11 clean-database full staging run, and any G2 source/projection evidence derived from it | **Remeasured 2026-08-13** (below), partially |
| HIR3 | G1 output contract at version 5; affected media re-export and Bundle A; G4 preflight | Open — blocked, needs the mounted media source |
| HIR4 | Source acquisition acceptance and every source/inventory hash; v1 custody artifacts are ineligible | Open — needs the acquisition host and drive |
| HIR5 | G7/G8 recovery acceptance; all v1 recovery evidence is ineligible | Open — needs disposable restore targets |
| HIR6 | The ingress/deferred exercise and exact closeout evidence | Open — needs a real signed webhook during a freeze |
| HIR7 | Release dry run, concurrency and rollback exercises; signed release authority must be regenerated | Open — needs Spaces scratch keys |

Only the HIR2 row has been discharged, and only in part.

#### 2026-08-13 — replacement clean-database full staging run (HIR8 §14 step 3)

Ran under the HIR2 contract against a freshly provisioned and certified `crockenhill_rehearsal`,
with `--fresh-parse`, explicitly not reusing archive-v11 result hashes. Report:
`storage/scratch/hir8-step3-import-20260813.json`; SHA-256
`27b0614aae6234930f97b9230a1404c8ee754052fee554d380aea328e14a452a`.

The report proves its own provenance rather than asserting it: `parse_evidence.fresh_parse: true`,
`cache_policy: "raw-extraction-bypassed; curation always re-applied"`, `cache_version: 1`, and every
one of the 534 entries carries a `parse_cache` block naming its `raw_cache_key_hash`,
`raw_result_hash`, `entry_authority_hash`, `curation_plan_hash` and `resolved_result_hash`. That
per-entry binding is the auditable half of HIR2 and did not exist in the superseded run.

Authority unchanged: batch `oos-curated-2026-08-12`, manifest `474d32c4…d8451`, plan
`6795f149…16cda`, 534/535 included, zero adjudicated identity disagreements.

The re-parse produced materially different numbers, which is why reusing the old measurement would
have been wrong:

| Measure | 2026-08-12 (superseded) | 2026-08-13 (current) |
|---|---|---|
| Dispositions | 109 created / 18 evidence_retained / 407 held_for_review | **106 / 13 / 415** |
| Report date accuracy | 525/534 (98.3%) | **522/534 (97.75%)** |
| Morning recall | 415/419 (99.1%) | **417/419 (99.52%)** |
| Evening recall | 39/40 (97.5%) | **39/40 (97.5%)** |
| Raw auto-import precision | 442/573 | **443/572** |

As before, the raw auto-import precision treats every additional service as false and is **not** the
adjudicated F1 precision. Evening precision is 0.2254 against 134 false positives, consistent with
the same `service_beyond_manifest` accounting the superseded entry described.

**What this run did not replace at the time.** The superseded entry also carried an adjudicated F1
membership analysis over the staged writes — 155 retained plan identities, 122 occupying a
manifest-named slot and 33 explained by `service_beyond_manifest`, zero wrong-date and zero
unexplained. That analysis was computed against a live database which no longer existed. **This gap
was closed on 2026-08-14 by a further restage; see below.**

#### The rehearsal database was an invalid evidence base — resolved 2026-08-14

Checked directly on 2026-08-13: `crockenhill_rehearsal` held 440 `church_services`, 537
`church_service_source_records` (email = 110, openlp = 427) and 278 `inbound_emails`, of which 200
were still `pending` and only 78 `processed`. Every row was timestamped 19:14–19:52 on 2026-08-13.

That was **not** the step-3 database, and not any completed run: it was the residue of a later
combined Email + OpenLP restage killed roughly half way through the 534-entry Email corpus. Three
separate full-corpus Email attempts had by then been terminated by execution limits rather than by
any defect in the importer.

**Reprovisioned and restaged 2026-08-14** — see the next section. The standing rule remains: a
rehearsal database is not durable evidence. Reprovision with
`historic-import:provision-rehearsal-database` before any staging run, and never read counts out of a
database whose provenance you have not just established. Staging evidence survives as the report
artifact and its hash; the database is a working surface.

#### 2026-08-14 — F1 adjudicated membership recomputed against post-HIR2 writes

`crockenhill_rehearsal` was reprovisioned (certified clean; `publication_state`, both HIR7 release
ledger tables and HIR6's `dispatch_token` all verified present, so the restored schema dump is
current) and the approved Email corpus restaged under HIR2. Report:
`storage/scratch/f1-restage-20260813.json`; SHA-256
`a6767b99530be2e2bb4d50d22c75da39a8fd48d7dbacfd36b4f40e512c5bff07`.

Provenance is again self-proving: `fresh_parse: true`, `cache_policy:
"raw-extraction-bypassed; curation always re-applied"`, `cache_version: 1`, `parse_cache` present on
all 534 entries, authority unchanged at batch `oos-curated-2026-08-12` / plan `6795f149…16cda`, zero
adjudicated identity disagreements. The command exits 1 because F32 treats every pending source as
unsettled; that is the expected outcome, not a failure.

Dispositions were 109 `created`, 19 `evidence_retained`, 404 `held_for_review` and **2 `merged`** —
the `merged` disposition did not appear in either earlier run. Report date accuracy is 526/534
(98.5%), the best of the three runs. The database holds 158 services, 160 source records, 1,621
canonical items and 534 inbound emails.

**F1 holds.** Over the 158 Email-evidenced services actually written:

| Bucket | Count |
|---|---|
| Occupies a manifest-named service slot | **127** |
| Extra, explained by a hash-covered `service_beyond_manifest` | **31** |
| **Unexplained** | **0** |
| Staged on a date the manifest never approved | **0** |

All 31 extras are one coherent population: every one is an `evening` service on a date whose entry
the manifest curated as `morning`, where the order of service carried both. That is precisely the
case `service_beyond_manifest` and decision D9 exist to permit.

**The method, because the analysis artifact is not recoverable from git** (`storage/scratch/*` is
ignored). Take the manifest's approved identities as the distinct `(resolved_date,
resolved_service)` pairs over `disposition: include` entries — 521, which reproduces the recorded
figure exactly. Take the written population as `SELECT DISTINCT date, service` over
`church_services` joined to `church_service_source_records` where `source = 'email'`. A written
identity is approved if it is in that set; otherwise it is explained only if some report entry whose
*expected* date equals the written date carries `service_beyond_manifest` and lists that service
under `services.detected` but not `services.expected`; otherwise it is unexplained and F1 fails.

**Measure the database, not the report.** An attempt to recompute the superseded 2026-08-12 figures
from that run's own report yields 167/127/40 rather than the published 155/122/33, and no filter over
the report's plan list reproduces its 126 + 29 decomposition. The published analysis was always
measured over rows staging *wrote* — a gate-eligible plan does not always become a distinct written
service, because convergence collapses some. A report-only reconstruction is a different population
and must not be presented as this measurement.

**Verified non-vacuous.** Injecting an unexplained extra on an approved date, or a service on an
off-manifest date, each flips the result to failed. Stripping `service_beyond_manifest` from every
report entry turns all 31 explained extras into unexplained failures — so those 31 rest on real flag
evidence rather than on a permissive default.

**What this does and does not discharge.** It closes the *no-unexplained-excess* half of F1 against
post-HIR2 writes, which was the outstanding piece. It does **not** close the *completeness* half:
only 127 of the 521 approved identities are staged, because 404 sources remain `held_for_review`.
G2 additionally requires the proposal census and the declared-source-kind coverage, and no combined
Email + OpenLP + video corpus has been staged on current code. **G2 remains open.**

#### OpenLP staging and the parser fix

The OpenLP corpus is local after all — `storage/scratch/ServiceRecords`, not the CBC drive — and a
curation manifest over it validates against the real importer's dry run. A 427-archive staging run on
2026-08-13 processed all 427 with zero failures and flagged **58** for review. `e84e1e6b1` then fixed
the cause of 57 of them: `OpenLpServiceParser::parse()` treated *any* service disagreement with the
embedded `.osj` name as a mismatch, including OpenLP's auto-generated `Service YYYY-MM-DD HH-MM.osj`
names, which never encode AM/PM and always resolve `slot_known: false`. A slot-unknown embedded name
now takes a smaller "slot not detected" penalty (evidence of absence) instead of the full mismatch
penalty (evidence of contradiction); date disagreements are untouched.

Re-run after the fix: 427 processed, 0 failures, **5** flagged. All five were checked individually —
four are the genuine AM/PM contradictions the curation manifest had already identified by hash
comparison, and one is a genuine unresolved *date* disagreement the manifest records as such. None is
the bug. **The 58 figure is superseded; cite 5.**

This does not discharge anything on the OpenLP side of G1: populating the v2 curation fields and the
video manifest still need the drives, and no combined Email + OpenLP + video convergence has ever
been staged on current code.

### 2026-08-14 — the 404-source review backlog reviewed: it is the whole completeness gap

Read-only review of the 404 `held_for_review` sources from the 2026-08-13 F1 restage, against the
rehearsal database and `f1-restage-20260813.json`. Nothing was re-parsed and nothing was written.

**The backlog is exactly the completeness gap.** 521 approved identities, 127 staged, **394
missing** — and **393 of the 394 are covered by at least one held entry**. The single exception took
`evidence_retained`: a partial-scope entry that retains evidence without creating a service, which is
designed behaviour, not a loss. Clearing the 404 is therefore *sufficient* for F1's completeness
half. Nothing else is hiding.

| Hold family | Entries | Identities | Clearable by operator approval today? |
| --- | --- | --- | --- |
| Soft hold | 234 | 231 | Yes — `isManuallyImportable()` passes |
| Invalid extraction | 150 | 148 | **No** — approval cannot import these at all |
| Gate hold | 20 | 20 | Needs adjudication |

Identities sum to 399 against 393 distinct because correction chains put two entries on one identity.
Of the 150 invalid entries, 137 are wholly blocked and 13 carry a second plan that *is* approvable.

**The `low_confidence: 412` parse-flag count is a misleading work estimate and should not be cited as
one.** Confidence is not what holds most of this back.

#### Soft holds — 231 identities, and the identity is manifest-corroborated in every one

Causes: `attempts_disagree` 125, `no_consensus` 85, `below_review_threshold` 21, and one each of
`content_scope_unknown`, `service_other` and the F63 anomaly. Across all 234 entries, **0** disagree
with the curated date and **0** missed a curated service; 208 of 233 had both extraction attempts
return the same total item count. The residual risk of approving this cohort is item-list wording,
not misfiling.

The 125 `attempts_disagree` deserve their own note, because `extractionSignature()` hashes
`service_evidence_line_ids` alongside item type and line ids, and those evidence ids are optional for
single-plan emails and are **never written to a service**. Splitting by what stored attempt metadata
can see: **70** are identical at plan/item-count/scope level, so the disagreement lives entirely in
evidence citations, item typing or line attribution; 52 differ on item count or scope; 3 differ on
the number of plans.

#### Invalid extraction — 148 identities, and the blocker is bookkeeping, not content

`isManuallyImportable()` excludes `InvalidExtraction`, so no amount of operator review clears these.
This is a code decision, not operator time. Reason kinds, as plan occurrences: `line_assigned_twice`
92, `unclassified_source_line` 87, `ignored_line_inside_items` 48, `phantom_source_line` 36,
`items_out_of_source_order` 11, `missing_boundary_evidence` 5, `item_merges_lines` 4,
`evening_without_boundary` 1. Every one is a complaint about *provenance bookkeeping*, not a claim
that the extracted order is wrong. Three read in full against their source emails:

- `2015-06-28` — a complete, correct 15-item morning order, invalidated solely because line 1, the
  opening hymn, was cited both as an item and as service evidence.
- `2014-09-14-pm` — a complete evening order at confidence 0.92, invalidated solely because line 41,
  `Many thanks,`, was left off the ignored list. Line 43, `Mark`, *was* ignored.
- `2015-12-13-pm` — a complete evening order at 0.86, invalidated because a trailing "Details for
  Gareth" appendix, carrying the *morning* service's reading, was ignored inside the plan's span.

The largest cause decomposes exhaustively from stored provenance — all 105 plans carrying it, not a
sample: **48** evidence ∩ item (a redundant citation of a line extracted exactly once), **20**
evidence ∩ evidence across plans, **35** involving the ignored list and so not reconstructible
(ignored lines are never persisted), and **2** item ∩ item, the only genuine double-counting of
content. The rule that invalidates more of this corpus than any other is firing on real content
duplication in 2 cases out of 105.

#### Gate holds — all 20 explained, only 6 editorial

- **8 × `no_corroborated_plan` on a wrong date.** These are *exactly* the 8 date-extraction errors in
  the whole 534-entry corpus — verified as a set equality against `aggregate.false_date_cases`, with
  zero date mismatches anywhere outside this bucket. The gate caught every one and none wrote
  anything. Manifest corroboration has perfect recall on date failure, which is a positive result
  worth keeping.
- **6 × `superseded_predecessor_not_imported`**, all `-revised`, each chained to a predecessor that
  is itself in this backlog (3 behind invalid extractions, 3 behind soft holds). They clear
  automatically; they are not independent work.
- **6 × `curated_service_not_parsed`** — the only true identity adjudications: `2016-06-26`,
  `2017-03-26`, `2017-07-23-pm`, `2018-05-27`, `2018-12-23-carols`, `2024-11-03`. Two of them
  (`2018-05-27` at confidence 0, `2024-11-03` at 0.35) parsed no service at all.

#### Method, so this is reproducible without the scripts

The analysis scripts live in gitignored `storage/scratch` and are **not recoverable**, so the method
is recorded here as prose, as was done for F1. Spine is the evaluation report, which alone carries
`gate_reasons` and the manifest expectations; join to `inbound_emails.processing_metadata` on
`message_id` for per-plan disposition, validation reasons and consensus. Attribute each held plan to
the *first* rule that fired in `planDisposition()`'s precedence, and **take the stored `disposition`
as the authority for invalid-versus-review**: `OosEmailParserService` appends the "two valid
extraction attempts disagreed" sentence to `validationReasons` *after* `planDisposition()` has run,
so reading reasons first misattributes all 125 disagreements as validation failures. Count only
plans the report marks `gate_eligible`, since only those were offered to `import()`. Approved
identities are distinct `(resolved_date, resolved_service)` over `disposition: include` manifest
entries (521, reproduced exactly); staged identities are `SELECT DISTINCT date, service` from
`church_services` joined to `church_service_source_records` where `source = 'email'` (158).

**What this review did not establish.** Nothing was re-parsed; every conclusion comes from stored
artifacts of the 2026-08-13 run. Four entries were read source-against-extraction, so the defensible
general claim is about the *nature of the validator's complaint* plus the duplicate anatomy, which is
exhaustive — **not** that all 150 invalid extractions are content-correct. The ignored-line list is
never persisted, so 35 of the 105 duplicate clashes and the whole `unclassified_source_line` count
cannot be decomposed further without a re-run.

#### Re-run cost, measured rather than assumed

The 2026-08-13 run made **1,048** model calls, not 534: **514 of the 534 entries (96%) took a
corrective second call**. `retryReasons()` fires on any validation reason *and* on any plan below the
0.90 auto-import threshold, and the confidence condition alone catches the large majority. Budget any
re-run accordingly.

The retry earns it and should not be trimmed to save time: 154 corrected attempts carried fewer
reasons than the first, **81 went from having reasons to none at all**, and the corrected attempt was
selected 462 times. 52 came back worse and were correctly discarded. Retrying is also what produces
consensus, which is what lets a 0.75–0.89 plan auto-import at all.

#### What this creates

F63 is reopened and fixed above. Two new defects, F64 and F65, are raised below. The intended
sequence is: land F64 and F65, re-run staging, and let the cleared backlog fall out automatically;
only then hand the operator the 6 identity disagreements and 8 date corrections, which is the whole
of the genuinely manual work — about 14 items, against 404 today.

### 2026-08-14 — F64: OoS extraction requests a schema the API is never asked to enforce

**State: implemented and corpus-proven 2026-08-14.** The request now sends
`'strict' => true`. Strict mode narrows the permitted keywords, so `minimum`, `maximum`, `pattern`
and `minItems` were removed from the schema and each is enforced in PHP instead — `service_count`
clamped in `resultFromResponse()`, `confidence` already clamped in `normaliseServices()`, the date
format already in `OosEmailParserService::validatedDate()`, and "every item cites a line" already by
the validator. `OpenAiOosEmailItemExtractorTest::it_enforces_its_response_schema_with_strict_structured_output`
walks the whole schema and asserts the keyword set exactly, so an unsupported keyword cannot be
reintroduced anywhere in the tree without a named failure.

**Live acceptance is proven, corpus behaviour is not.** One real `gpt-5.4-nano` call on a synthetic
order confirmed the API *accepts* the strict schema — including the integer line-id enums, which the
sibling service never exercised — returned a well-formed extraction and cited no phantom line id.
That closes the risk that a re-run would 400 on every request. It does **not** establish the
corpus-wide result: `phantom_source_line` reaching zero over the same 534 entries still requires the
staging re-run.

**Original finding, retained.**

`OpenAiOosEmailItemExtractor::attempt()` sends a `json_schema` response format without
`'strict' => true`. The sibling structured-output caller in this codebase,
`OpenAiServiceStructureService::responseFormat()`, does set it, so this is an internal inconsistency
rather than a considered choice. Every line-id field in the schema is enum-constrained to the real
source line ids via `lineIdArraySchema()`, which means the **47 phantom line-id occurrences** in the
2026-08-13 corpus — 40 ignored-line, 6 item, 1 service-evidence — reference ids the model should have
been structurally unable to emit.

The obvious alternative explanation was tested and **refuted**: enum size. The highest line id
anywhere in the corpus is 179, and emails carrying a phantom reference median 27 non-blank lines
against 18 for clean ones — nowhere near the limits at which enum enforcement degrades. Without
`strict`, the schema is advisory, which is consistent with what the corpus shows.

**Required outcome:** the extraction request enforces its schema, so a line id outside the source
document cannot be returned. Enabling `strict` constrains which keywords the schema may use, so this
needs a compatibility pass rather than a one-word change — `minItems` in `lineIdArraySchema()` is the
first thing to check — and any keyword that has to be dropped must be re-enforced in PHP, not
silently lost.

**Proof required:** a request built for a known document is asserted to carry `strict`; the
schema is exercised against the live API on at least one real corpus entry to prove it is accepted,
not merely well-formed; `phantom_source_line` reaches zero on a fresh staging run over the same 534
entries; and any validation moved out of the schema has its own test.

### 2026-08-14 — F65: the extraction validator cannot distinguish bookkeeping from content

**State: implemented and corpus-proven 2026-08-14.** `OosEmailExtractionValidationResult`
now carries the content subset of its reasons alongside the full list, and `planDisposition()`
invalidates only on content. A bookkeeping-only plan becomes `ReviewRequired`: still held, never
auto-imported, but reachable by review — which is the whole of the 148-identity unlock. Three rules
changed shape:

- `assignLine()` no longer reports an evidence/item overlap or evidence shared between plans at all.
  Only two *items* claiming one line is a content finding; a line both ignored and claimed is
  recorded as bookkeeping.
- `validatePlanSpan()` now ends at the plan's last item line instead of running to the next plan or
  the end of the document, so a trailing appendix is not mistaken for a dropped item. What remains —
  an ignored item-like line *between* two items — stays a finding, but a bookkeeping one.
- `extractionSignature()` drops `service_evidence_line_ids`.

**Scope correction to this entry's own required outcome.** It asked that the consensus signature
"compare only what is written to a service". What was built drops the evidence citations but keeps
each item's `type` and `source_line_ids`, which are provenance rather than stored fields. They are
retained deliberately: they identify *which source content* became an item, and comparing item type
alone would let two materially different extractions register as agreeing. The defensible statement
is narrower — the signature must not compare optional provenance that no service is built from.

**Proven by test:** `OosEmailExtractionValidatorTest` (7 cases, covering each rule above in both
directions) and `OosEmailParserServiceTest::two_attempts_differing_only_in_evidence_citations_reach_consensus`,
which was checked non-vacuous by restoring the old signature and watching it fail. The pre-existing
`it_holds_an_item_like_line_ignored_inside_a_service_sequence_for_review` was renamed and now asserts
the plan is manually importable but not auto-importable.

**Projected over the stored 2026-08-13 extractions, without re-parsing.** Each stored validation
reason was reclassified under the new rules; two needed the plan's own provenance rather than the
reason text (the duplicate-assignment clash kind, and the ignored line's position relative to the
plan's last item line, both of which the reason strings carry as line ids). Of the **193**
invalid-extraction plans in the held backlog, **141 become reachable by review and 52 stay invalid**,
unblocking **110 of the 148 blocked approved identities**. **54** of the 141 end up with no reason at
all, so they rejoin the ordinary confidence gate and some will auto-import.

The residue is the interesting part. Of the 52 that stay invalid, **40 are phantom line-id
references** — 34 ignored-line and 6 item-source — which is precisely the class F64 exists to
eliminate. The genuinely content-flawed remainder is about 12 plans: 11 with items out of source
order, 5 merged lines, 5 missing boundary evidence, 3 item-on-item duplication, 1 evening boundary
(a plan may carry more than one). So the two findings are complementary rather than parallel, and
F64 lands most of what F65 leaves behind.

**This is a measurement of the design against observed failures, not a prediction of the re-run**,
which will produce different extractions — F64 alone changes them.

**Not yet proven:** the corpus-scale effect. The held population by family after a fresh staging run,
the resulting staged-identity count against the 521 approved, and the 8 date errors still being held
by the corroboration gate all require the re-run.

**Original finding, retained.**

`OosEmailExtractionValidator` returns one undifferentiated list of reasons, and any non-empty list
makes the plan `InvalidExtraction`, which `isManuallyImportable()` refuses. The consequence measured
above is that **148 approved identities — 38% of the completeness gap — are unreachable by any
operator action**, on the strength of complaints that are overwhelmingly about provenance
bookkeeping rather than about the extracted order being wrong. The exhaustive decomposition of the
largest rule found 2 genuine content duplications in 105 plans; the other 103 are redundant evidence
citations, cross-plan evidence sharing, or clashes with the ignored list.

Three specific rules carry nearly all of it. `assignLine()` treats an evidence/item overlap as
identical to an item/item overlap, though only the latter double-counts content and evidence line ids
are never written to a service. The unclassified-line rule invalidates a complete order over an
unlisted sign-off. `validatePlanSpan()` cannot tell a dropped item from a trailing appendix that
legitimately carries another service's details.

The same conflation drives `validAttemptsDisagree`, because `extractionSignature()` hashes
`service_evidence_line_ids` — optional metadata for single-plan emails — so two attempts that agree
on every item can be recorded as disagreeing and held forever. 70 of the 125 disagreements are
identical at plan/item-count/scope level.

**Required outcome:** validation distinguishes reasons that impeach the extracted content from
reasons that impeach only its provenance bookkeeping. Content failures keep invalidating the plan.
Bookkeeping failures must not make a plan unreachable by review: they should hold it, annotate it and
leave it approvable, and where the bookkeeping is provably harmless — an evidence/item overlap on a
line extracted exactly once, evidence shared between plans — they should not fire at all. The
consensus signature must compare only what is written to a service. Never weaken the item/item
double-counting rule, the out-of-source-order rule, or the date and identity gates, all of which are
doing real work: the corroboration gate caught 8 of 8 date errors.

**Proof required:** an evidence/item overlap on a singly-extracted line no longer invalidates; an
item/item overlap still does; two attempts differing only in evidence line ids reach consensus; a
trailing appendix after the last item does not invalidate while a dropped item inside the sequence
still does; a fresh staging run over the same 534 entries reports the held population by family, with
the invalid-extraction family reduced and the 8 date errors still held by the corroboration gate; and
the resulting staged-identity count is measured against the 521 approved, not asserted.

### 2026-08-14 — F66: the acquisition gate had no producer, and now has one

**State: found and fixed the same day; workstream D1/D3 tooling blocker; drive-free.**

HIR4 hardened `historic-import:verify-source-acquisition` and left it consuming a signed custody
artifact **nothing in the application could produce**. Only test fixtures built one. That made the
first command of the import — the one run with the drive mounted read-only, where a retry means
re-mounting the original — unexecutable in practice.

The artifact is not hand-authorable, which is why this went unnoticed as a documentation gap rather
than a code one. `copies.{evidence,working}.inventory_hash` must equal `CanonicalJson::hash()` over
the verifier's own per-path walk (type, mode, device, inode, hard-link count, symlink target,
observed xattrs, NFC-normalised name); `capacity_plan.source_bytes` must equal the observed byte
total exactly; `dispositions` needs one entry per path in the whole tree; and `exactKeys()` guards
nine separate blocks. An operator would have had to reimplement a 773-line private walk
byte-identically at the prompt.

**What landed.** Two commands, deliberately split so that enumeration and signing are separate acts:

- `historic-import:draft-source-dispositions` walks one protected copy read-only and emits a
  worksheet naming every path with its observed type, size, mode, link target and hard-link count,
  and **every disposition null**. It decides nothing: a default disposition is how a path nobody
  looked at ends up signed for. Read errors, dangling or escaping links and case/Unicode collisions
  fail the draft and name the paths, which is workstream D2's "record health/read errors" at the
  only point where the remedy is still "re-copy the source".
- `historic-import:capture-source-acquisition` consumes the completed worksheet plus an operator
  facts file and emits the signed custody artifact. Everything observable is observed and never
  accepted as a claim — both inventory hashes, both failure domains, write protection, the extended
  attributes and the source byte total. Everything that is a human act — scope and reasons, the
  drive's identity and health, the malware scan, retention, capacity acceptance — must arrive from
  the operator, and a missing one is a refusal.

**Where D5's written reasons live.** The custody schema allows a path only `disposition` and
`xattrs`, so a per-path reason cannot go in it. Reasons are recorded once per disposition *class* in
the worksheet, every class in use must have one, and capture prints the worksheet's SHA-256 so the
two documents file as one piece of evidence. A tree with tens of thousands of files needs a
defensible reason per decision, not per file.

**Three refusals moved earlier than the gate**, because each one is cheaper to fix before the
expensive pass than after it: a copy a write probe can still write to; two copies sharing one
failure domain; and a worksheet whose path set no longer matches the copy — the gate refuses that
last one as "unobserved paths" without saying which, and only after hashing every file in both
copies. Capture diffs first and names the paths and the direction.

**Extended attributes are claimed only where both copies agree.** The gate compares the claim
against each tree in turn, so a one-sided attribute would make the artifact unverifiable. The
dropped ones are reported rather than swallowed: an attribute on one copy and not the other usually
means a copy was made with the wrong tool.

**The honest limit, recorded so it is not overclaimed later.** Capture computes its hashes with the
verifier's own public `inventory()`, so producer and gate cannot disagree at capture time. A passing
end-to-end run proves the schema is right, not that the gate is discriminating. What the gate still
proves independently is **drift** — anything that changes between capture and verification, on
either copy, on a different host or at a later date — and that is pinned by a non-vacuity test that
mutates a copy after signing and asserts the refusal.

**Acceptance:** the two commands run in sequence and the gate accepts the result, asserted end to
end rather than by inspecting the document. The draft walk is pinned to the verifier's enumeration
by a dedicated test over hidden files, nested directories, spaces, mixed case and accented names, so
a divergence surfaces in the suite instead of on the acquisition host.

**Gates:** 14 capture tests, 7 draft tests, the 16 existing HIR4 verifier tests unchanged after the
create-once/private-path extraction into `App\Support\PrivateEvidenceFile`; `pint --dirty` applied,
`composer phpstan` clean, `artisan test --parallel` green (6,600 tests), `artisan dusk` green (55).

**This closes no gate.** It removes a blocker from workstream D, which remains open on its operator
half: the acquisition procedure document, the named custodian, the malware tooling, the physical
device identification and the two genuinely independent protected copies. What changed is that those
steps now have a command to run at the end of them.

**Still without a producer:** the historic **video** curation manifest. `HistoricVideoCurationManifest`
validates `relative_path`/`sha256`/`byte_size` per file and `sermons:import-historic-videos` requires
an approved manifest plus its `plan_hash`, but nothing builds one — the OpenLP equivalent was built
by `storage/scratch/build_openlp_manifest.php`, which is gitignored and not recoverable. That is the
same defect class as this one and is the next drive-free item in workstream D.

## 5. Decisions required from the maintainer or church

**All rows are now decided.** The 2026-08-11 evidence-log entry above records each decision, the
measurements behind it and the work it creates. A decision is not a gate: every row still needs its
implementation and its rehearsal, production or operator evidence.

> **Citing these decisions from other plans.** This plan's D1–D8 are referred to elsewhere as
> **FR-D1–FR-D8**, because the [safety remediation](HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md)
> plan (HIR-D1–HIR-D5) and the
> [architectural maintainability](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) plan
> (AM-D1–AM-D4) each maintain their own D-numbering. An unprefixed `Dn` inside this file always means
> this file's own decision.
>
> Two rows below carry post-decision scope corrections dated 2026-08-12 — see **D6** (release writer)
> and **D7** (log record unbuilt). Neither reverses a decision; both narrow what the decision proves.

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
| Backup/object rollback design, RPO/RTO and retention window | Maintainer/operator | **Decided 2026-08-11 (D6).** Pre-window dump verified by real restore; RTO 30 min, RPO to the freeze; object rollback met by operation-owned keys **for the apply step only** — the release writer is reopened under HIR7/HIR-D1 (see D6 scope correction) |
| Production deploy/admin/config freeze and approval protection | Maintainer/operator | **Decided 2026-08-10 + 2026-08-11 (D7).** 503 refusal + scheduler skip; required reviewers on `production`; log-only operation record **formally accepted** in place of Sentry — **but that record is unbuilt; AM3 Delivery 1 blocks the window** (see D7 blocking correction) |
| Evidence retention and source-drive custody duration | Maintainer/operator | **Decided 2026-08-11 (D8).** Small corpora and artifacts permanent; video original returned to the church, working copies deleted on exact audit + smoke |
| Whether any gate may require a second human | Maintainer | **Decided 2026-08-14 (D10).** No. One-person project: every multi-person control is removed permanently — role distinctness checks, witness, second operator, two-person approval. Roles survive as accountability fields one person may hold. **Do not reinstate; see D10 for what replaced each one** |

## 6. Final go/no-go checklist

This is the final checklist for the investigation. A failed technical invariant is **not waivable at
all** during the production window — not by the maintainer, not by anyone. This replaces the original
"no single person may waive" wording, which assumed a second person could; under D10 there is none,
so the rule is stated as the absolute it always should have been.

> **Safety-remediation status (2026-08-13).** HIR0–HIR7 are committed; HIR8 is not. Several items
> below now have their code half done and their evidence half outstanding — notably F36 (HIR4
> custody v2), F45 (HIR5 recovery v2 and HIR7 conditional object creation), F52/F57 (HIR6 closeout)
> and F59 (HIR3 settled Scripture outcomes). **None of them may be ticked on landed code.** Each
> needs evidence produced on the exact release candidate under HIR8, and every artifact predating its
> package is ineligible: custody and recovery version 1, and any parse resolved before HIR2.

- [ ] F1 decided, PR26 implemented, and G2 certified against all declared source kinds. The F1
      adjudication was recomputed against post-HIR2 writes on 2026-08-14 and **holds** — 158 written
      services partition as 127 manifest-named, 31 explained extras, zero unexplained, zero
      off-manifest dates. Still open: completeness (only 127 of 521 approved identities are staged,
      404 sources remain held for review), the proposal census, and declared-source-kind coverage.
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
- [ ] F46 operation binding, deploy/admin/config freeze and alerting/watchboard passed rehearsal;
      F47 durable local runtime passed forced-crash recovery. (D10 removed F46's two-person control;
      single-operator control is the standard here and must not be raised again.)
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
- [ ] F60 regenerates and hash-binds the hymn reconciliation against the exact converged corpus;
      every row on `Known Usage` and `Ambiguous Usage` has one source-backed disposition, including
      cross-source duplicate handling.
- [x] F61 makes the hymn apply operation-owned and production-guarded, verifies the exact workbook
      and count contract before writes, records row-level outcomes and includes visibility,
      rollback and no-op evidence in closeout.
- [x] F62 proves existing hymn reports reconcile exactly: source drift fails, authorised catalogue
      resolution and canonical-item linking are explicit, linked reports do not double count, and
      the second pass is a true no-op.
- [x] F63 classifies corroborated Email plans using curated content scope without admitting extra
      unknown plans; a fresh full archive-v11 staging run then measures the authoritative held and
      retained-evidence populations.
- [ ] HIR0–HIR7 acceptance is green together on one exact release candidate, and the HIR8 §14
      rehearsal has produced one linked ledger of fresh source, cache, Bundle A/B, operation,
      approval, recovery and closeout hashes with no superseded hash presented as current. Step 3
      (clean staging under HIR2) is done; steps 1, 2, 4 and 6–11 are not.
- [ ] Clean production-shaped full rehearsal, exact audit, full no-op rerun and restore/rollback
      repetition all pass on the release candidate.
- [ ] Measured throughput, cost, capacity and production-window/rollback budgets are accepted.
- [ ] Command-exact production runbook was executed verbatim in rehearsal, with its output checked
      against the documented expected output/exit code for every step. (D10: "independently checked"
      meant a second reader; verbatim execution against written expectations replaces it.)
- [ ] Private batch ledger contains every manifest, hash, fingerprint, approval, backup, report and
      run identifier, with no secrets or inappropriate personal data.
- [ ] G3-G8 are evidenced on the exact release and operation; production approval is time-bounded and
      names that operation.
- [ ] The named operator — who under D10 also holds the incident, pastoral/content and takedown
      roles — is available for the window and closeout.
- [ ] Editorial truth-set thresholds and all user journeys pass; import does not broaden the
      audience beyond the existing accepted visibility.

## 7. Out of scope for this investigation

- Running an importer, extractor, media processor or production mutation.
- Connecting or mounting the CBC drive.
- Changing application code, configuration, dependencies or infrastructure.
- Treating later cleanup/one-shot deletion as import readiness; cleanup remains gated by G9.
