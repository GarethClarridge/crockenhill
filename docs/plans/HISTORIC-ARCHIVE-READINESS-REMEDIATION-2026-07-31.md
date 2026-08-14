# Historic Archive Import Readiness Remediation Plan

> **Status (implementation snapshot 2026-08-06; read-only audit updated 2026-08-12): PRs 1–17 are merged, WP0–WP8 have landed, and every gate-acceptance audit
> gap listed on 2026-08-06 is closed.** "Merged" is still not a gate certification — see §17's Status
> column and the "Acceptance and gate readiness" audit.
>
> **2026-08-12 addendum:** the complete current working tree, including the unstaged archive-v11
> changes, was audited read-only. Final-readiness findings F60-F63 add required work to the historic
> hymn lane and archive-v11 scope handling. No importer, test or database command was run for that
> audit. The next operation remains a fresh clean-database full archive-v11 staging run, but only
> after F63; the hymn apply remains prohibited until F60-F62 are green.
>
> **PR21 landed 2026-08-06**, closing the Email manifest gap described in §7.5: the manifest class,
> its schema and validation, the dry-run identity reconciliation, and `ImportOosArchiveCommand`
> repointed at the approved plan with `OosArchiveMarkdownParser` deleted.
>
> **Two drive-free code slices remain**, both identified on 2026-08-06 and neither previously given a
> PR number. They are now PR22 and PR23 in §17:
>
> - ~~**PR22 — §12.4 production evidence-coverage audit.**~~ **Done 2026-08-06.**
>   `audit:service-evidence-coverage` plus the lineage preflight, both whitelisted into
>   `production-audit.yml`. Obtaining the counts is now operator work behind the approval gate.
> - ~~**PR23 — §13.4 deterministic-promotion benchmark.**~~ **Done 2026-08-06.** The convergence
>   ledger now records durations — it previously carried no timestamp at all — and
>   `service-tracking:promotion-budget` derives §15.2's five values from them. The instrument is
>   complete; the numbers need a rehearsal apply, rollback and closeout to have run.
>
> - ~~**PR24 — the G8 prohibition's scope.**~~ **Done 2026-08-06.** Added after the PR22/PR23 sweep,
>   when resolving the header ambiguity below showed the prohibition was unenforced in code and
>   over-broad in prose. `HistoricImportProductionGuard` plus four call sites; §7.5's read-only claim
>   corrected.
>
> ---
>
> **Readiness audit, 2026-08-07.** The 2026-08-06 sweep closed the last scheduled slice and declared
> the drive-free critical path to be "§12.4's audit dispatch, the OoS rule-set approval and
> `expected_services`, then the Email half of steps 2–4". Re-verifying that path against the code and
> the corpus found **four blockers, three of them new code**, and the second item on it is not
> executable as written. The claim "no scheduled code slice remains" was true of the 2026-08-06
> backlog and is **no longer true**; PR25–PR27 below are the replacement.
>
> All four quality gates are green on `master` at this revision: Pint clean, PHPStan 0 errors over
> 665 files, `artisan test --parallel` 6215 passed / 80589 assertions, `artisan dusk` 54 passed. The
> gate instruments were exercised and fail closed exactly as documented — `services:proposal-census
> --gate` exits 1, `service-tracking:promotion-budget` exits 1 on four unmeasured phases, and
> `audit:service-evidence-coverage` exits 0 by design. The OoS dry run still reproduces the approved
> `plan_hash` `c13b8b67…` over all 404 entries with 0 identity disagreements **for the unchanged
> 2026-08-06 corpus snapshot only**. The 2026-08-09 inventory update below adds 131 files outside
> that authority, so the old hash cannot certify the current roots. **The blockers below are design
> and data gaps, not breakage.**
>
> | # | Blocker | Where | Status |
> |---|---|---|---|
> | F1 | `expected_services` is not derivable from the approved manifest | §7.5, §9.4.6 | **Decision closed 2026-08-09** — implementation joins F53 exact membership |
> | F2 | Staging into the current local database fabricates the historical 219-proposal census class | §13.5 step 3 | **Closed 2026-08-07** (PR28) |
> | F3 | The G2 gate cannot distinguish Email-only staging from Email + OpenLP | §9.4.6 | **Closed 2026-08-07** (PR25) |
> | F4 | `OpenLpCurationManifest` hard-codes the accounting §13.1 says to remeasure | §13.1 | **Closed 2026-08-07** (PR27) |
>
> Each is recorded in full at its own section and carries a row in §17's acceptance audit.
>
> **F1's maintainer decision and replacement Email authority are now closed.** The approved
> replacement holds 535 entries — **534 included and 1 excluded after the 2026-08-12 correction** —
> and 521 distinct identities, as batch `oos-curated-2026-08-12` with manifest hash
> `474d32c44284af7d1ef35b20f5454a5feab5609dac2626e5ad7e66bfd6ed8451` and plan hash
> `6795f1497d54d85baac353d026544445f78a151ad0c77c254cf58ce9ba016cda`. (Superseded authorities,
> retained as historical evidence: batch `oos-curated-2026-08-11`, manifest `f4b6b833…ee013`, plan
> `03d40e46…2de8c1`; and pre-D1 batch `oos-expanded-2026-08-09`, manifest `928dccb5…823e83`, plan
> `ebf486c1…18618a`, 535 included.) The baseline is the exact
> approved identity set; only identities explicitly explained by `service_beyond_manifest` may
> exceed it. Implementation is part of F53's exact per-batch/per-source certification, because a
> scalar exception would still allow missing identities to be offset by unrelated rows. F2 was recorded as a precondition needing no code; on
> implementation it became one, for the reason PR24 already established — an unenforced precondition
> has to be interpreted, an enforced one simply answers. It is now `UnevidencedCanonicalItemGuard`,
> refusing `oos:import-archive --import` when the curated identities already hold items no source
> explains.
>
> ---
>
> **Outstanding operator work, drive-free:**
>
> - ~~**Approve the OoS curation rule set.**~~ **Approved 2026-08-06 for the then-current snapshot.**
>   Promoted to `storage/scratch/oos-curation-manifest.json` as `oos-curation-v1`, plus seven
>   maintainer rulings on the service enum where the draft rules contradicted themselves. It validates
>   over those 404 entries with 0 identity disagreements, but the approval is superseded as current
>   authority by the 131 raw files added on 2026-08-09. See §7.5's retained approval record.
> - ~~**Re-inventory and re-curate the expanded Email roots.**~~ **Completed and approved
>   2026-08-09; re-curated 2026-08-11 under decision D1; corrected and re-approved
>   2026-08-12:** 535 entries, 534 included and 1 excluded, 521 identities, including the three
>   current-era entries; current batch `oos-curated-2026-08-12`, manifest
>   `474d32c4…d8451`, plan `6795f149…16cda`.
> - **Repeat `audit:service-evidence-coverage` manually on production** after the evidence back-fill
>   and re-projection. The initial read-only measurement ran manually on 2026-08-09 and found 3
>   services, 0 retained source records, 32 canonical items on unevidenced services and 0 proposals.
>   The maintainer decided that manual SSH audits are the accepted permanent operational path;
>   `production-audit.yml` does not need production credentials.
> - ~~**Decide the `church.historic_corpus.expected_services` rule (F1).**~~ **Decided 2026-08-09:**
>   reconcile exact membership against the approved 521-identity baseline and permit only explicitly
>   explained `service_beyond_manifest` identities. F53 owns the required item-level implementation.
>
> **Outstanding operator work, needs the CBC drive:** rehearsal step 1 (protect/hash the source
> drives), populating the v2 OpenLP curation fields, the §13.1 remeasurement of the OpenLP accounting
> and its broken symlinks, and the historic-video manifest.
>
> **The rehearsal itself has not started.** §13.5 steps 3–15 — evidence staging, the §9.4 census,
> calibration and the per-era truth set, the bulk media pass, bundle export, the different-PK import,
> the exact audit, the second no-op run and the rollback proof — are all unrun, and G4–G9 are all
> unclaimed. That, not the remaining code, is the schedule.
>
> Historic-video dispatch and every production mutation remain blocked behind the later gates. The
> expanded Email inventory, hashing and manifest curation are safe to continue drive-free. **Local
> Email/OpenLP evidence staging into a rehearsal database is safe only after the current Email roots
> have been re-curated and the clean-database/F1 gates are respected**; the last of those is §13.5
> steps 3–4 and was clarified on 2026-08-06. See the G8 scope note below.
>
> **Amended 2026-08-02** after a business-design review. The engineering content of the 2026-07-31
> audit is unchanged; what changed is everything around it:
>
> - **§0** states the value case, beneficiaries and before/after. The plan previously required every
>   PR to declare who benefits while declaring nothing for itself.
> - **§9.4** makes human review a designed-down quantity with its own loop, census and gate. There is
>   deliberately no review-hour budget: the corpus runs to its first review point, stops, and that
>   *class* of decision is automated if it can be. §13.5 is reordered so this converges before the
>   media pass, not after it.
> - **§12.4** brings current-era re-projection into scope. The repaired projector's defects are on
>   the live weekly path today, so existing services carry them and must be re-projected and audited.
> - **§14 / PR1** moves public service history ahead of the import; it is deliverable now against
>   data production already holds and depends on nothing the import produces.
> - **§5, §15.4** replace every gate that blocked on elapsed calendar time with the evidence that
>   waiting was meant to produce. Retention continues; blocking does not.
> - **§13.3, §13.4** add bulk-pass throughput and content-accuracy sampling, because those — not
>   implementation effort — set the schedule and the quality floor. §13.3 also **rescopes the
>   processing fingerprint off the git commit**: a commit-scoped fingerprint would mark media stale
>   on every projector commit and force a full reprocess per §9.4 iteration. §8.1's projection policy
>   version and the processing fingerprint are now explicitly disjoint.
> - **§17** re-sizes by review surface and blast radius instead of engineer-days, since the work is
>   agent-executed.
>
> Value-tiering the corpus and an opportunity-cost comparison were considered and rejected (§0).
> Editorial, copyright, consent and safeguarding policy is deferred, and recorded in §14.4 so that
> silence is not later mistaken for clearance.
>
> This is the **single implementation plan of record** discovered by the 2026-07-31 readiness
> audit. It consolidates and preserves the goals and ownership boundary of
> [Historic Media Acquisition and Result Promotion](../archived-plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
> and [R8 Data Convergence Correctness](../archived-plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md), but
> supersedes their work-package numbering, implementation-readiness claims and implementation
> sequence. Those documents are retained as decision records and detailed prior art only; do not
> execute their HM/R8 work packages independently. Historic-media concepts still own
> current-pipeline acquisition and Bundle A. R8 concepts still own source convergence, final
> canonical review, Bundle B and the only production runbook.
>
> **Do not run, against production,** canonical OoS/OpenLP archive imports,
> `sermons:import-historic-videos`, Bundle A or Bundle B persistence, or the R8 production mutation
> sequence until Gate G8. Do not delete the one-shot commands needed to complete or verify this work.
>
> **The scope of that prohibition was decided on 2026-08-06 and is now enforced in code (PR24).** It
> previously read as a prohibition on the *commands*, which was unexecutable: staging Email evidence
> is only reachable through `oos:import-archive --import`, because `EmailSourceAdapter` is invoked
> from nowhere but `InboundEmailImportService::import()`. Under the literal reading §13.5 steps 3–4 —
> the drive-free work the plan calls next — were forbidden, and with them G5, which those steps are
> the route to. A prohibition that blocks the only path to its own exit gate cannot have meant that.
>
> So the boundary is production, and `HistoricImportProductionGuard` is where it is now stated rather
> than described: outside production it is silent, because a rehearsal database is where the corpus
> is meant to be staged, projected and re-projected; in production it fails closed until
> `church.historic_corpus.production_import_approval` names the approved G8 operation, which the
> closeout report then quotes as the run's authority. Dry runs and preflights are deliberately **not**
> blocked in production — revalidating the production-window prerequisites is what G8 is, so a guard
> that blocked them would make the gate unreachable. The guard covers `oos:import-archive`
> (`--import`, `--import-bundle`, `--apply-bundle`), `service-tracking:import-openlp-services
> --apply`, `sermons:import-historic-videos` dispatch and
> `service-tracking:converge-historic-service --apply`.
>
> It does not cover storage isolation, which is the per-batch staging root's job (PR17), nor an
> operator who deliberately points a non-production `APP_ENV` at production infrastructure. It
> catches the realistic mistake: a production `.env` in the shell a rehearsal command was typed into.
>
> **No dependency changes are authorised.** Do not invest in the deleted or deletion-scheduled
> heuristic service-structure path.

## 0. Value

**Who benefits:** visitors looking for a past sermon, talk or song; the operator planning services
and answering "when did we last sing this"; the church's own record of its worship; and future
maintainers who need a complete, explainable processing history.

**What observably improves:** today a visitor can find and play an individual sermon, and can browse
the song catalogue. The service that sermon sat in is admin-only ([`web.php:163`](../../routes/web.php#L163)),
so nothing connects the sermon to the songs sung around it, the children's talk that preceded it, or
the scripture read. After this operation a service is a first-class public object linking all four,
song usage history extends back across the historic corpus instead of starting at the livestream
era, and historic sermons gain the transcripts, sections and video the current pipeline produces.

**Before/after, in the terms a visitor would notice:**

| Today | After |
|---|---|
| Sermon pages, individually findable | The same, plus the service each sat in |
| Song catalogue with recent usage | Usage history across the full archive |
| No public service history | Browsable/filterable service archive |
| Historic sermons without transcript, sections or video | Historic sermons with the current pipeline's durable output |

**Deliberately not claimed here:** an opportunity-cost comparison against the other open plans, and
value-tiering of the corpus by era. Both were considered and set aside on 2026-08-02 — the work is
being executed by agent rather than by hand, so the sequencing pressure those devices relieve does
not apply. The whole corpus is imported at full fidelity.

**Deferred, not resolved:** editorial, copyright/CCLI, consent, personal-data and safeguarding
policy for publishing a decade of services. This plan does not address them and WP8 must not be
taken as having cleared them. See §14.4.

## 1. Outcome

The operation must leave local, and then production without reprocessing, in the durable state each
service would have reached if its Email order of service, OpenLP plan and livestream had arrived
through the current application at the time.

The resulting history must include, where the sources support it:

- one canonical service per explicit date/service identity;
- a best-supported order retaining planned-only and observed-only occurrences;
- independently auditable Email, OpenLP and Livestream evidence;
- repeated songs and same-title items as distinct occurrences;
- complete processing runs, steps, segments, sections and retained artifacts;
- published sermons and children's talks with current metadata and media;
- confirmed song occurrences and `song_videos` without loss of public usage history;
- explicit human decisions only where evidence is genuinely ambiguous;
- exact, rerunnable production promotion with no media, transcription, AI, notification or cleanup
  work running there; and
- a public service history linking services, sermons, children's talks and songs while raw evidence
  and private review data remain admin-only.

## 2. Success criteria

The programme is complete only when:

1. Every source revision describes only what that source asserted.
2. Source arrival, queue completion and bundle application order cannot change the result.
3. A corrected revision deterministically supersedes its predecessor.
4. Source silence never removes another source's occurrence.
5. Plan-only material is inserted between its nearest observed anchors.
6. Repeated identical items retain cardinality and independent review identities.
7. Compatible evidence finalises automatically without a performative Manual review.
8. A Manual revision is required only for unresolved ambiguity and freezes the canonical result.
9. Bundle A contains the complete durable current-pipeline result and uses no database ID, local
   path or runtime correlation value as portable identity.
10. Bundle B contains the exact final decision, including proposal dispositions and exclusions.
11. Bundles A/B are linked to the same batch, evidence, pre-review result and fingerprints.
12. Production application dispatches zero queues, media tools, APIs, AI, mail or notifications.
13. Destination assets are verified, create-only and attributable to one import attempt.
14. A second complete import revalidates the live graph/assets and returns all exact no-ops.
15. Local/production item and relationship manifests match; aggregate counts are insufficient.
16. Existing production sermons and public song usage are preserved or safely enriched.
17. Public pages expose only publication-safe history under existing media exposure policies.
18. Every proposal class raised across the corpus is either automated away or explicitly recorded as
    irreducible ambiguity with a reason. Residual human review is a designed quantity, not a residue.
19. Current-era services already projected by the defective projector are re-projected after the
    repair and audited to the same standard as historic ones.
20. No gate blocks on elapsed calendar time. Every gate is satisfied by obtainable evidence.

## 3. Boundaries

### 3.1 Ownership

| Domain | Enforcing code seam | Must not do |
|---|---|---|
| Local recording acquisition/current pipeline | `HistoricVideoImporter` -> current processing orchestrator | Write final Manual decisions |
| Bundle A graph/assets/readiness | `HistoricProcessingResult*` services | Re-run processing in production |
| Evidence and canonical projection | `IngestChurchServiceSourceRevision` + `ChurchServiceProjector` | Read canonical rows back as source assertions |
| Manual authority and Bundle B | `ReviewChurchServiceEvidence` + `ChurchServiceConvergenceBundle*` | Copy local user/database IDs |
| Production sequencing | `ConvergeHistoricChurchService` + its command/runbook | Create a second ad-hoc run sequence |
| Public archive | Public exposure/query service + controllers | Expose raw evidence, private review or unpublished media |

### 3.2 Deployment and schema

- Use additive expand/contract migrations; never edit deployed migrations.
- Keep compatibility fields/readers until WP10 retires them on the evidence in §16, not after a
  fixed waiting period. Retention costs disk; it must never cost schedule.
- Separate DDL, data backfill and later contract/deletion releases.
- Every migration is focused and reversible or documents its forward-fix rollback.
- Production stays blocked until code/schema are deployed and worker configuration is proven.

### 3.3 Privacy and source safety

- Portable payloads contain no raw email body, secret, local path, user ID or private review note.
- Private reports/bundles use a unique batch root with directory `0700` and files `0600`.
- Reject absolute, traversal, symlink and out-of-root paths.
- Exact approved manifests, never globs or directory rescans, are mutation authority.
- Source archives remain read-only.

## 4. Existing implementation and blocker evidence

This is a repair programme, not greenfield construction. Approximately 25 recent commits already
provide the evidence schema, projector, review UI, portable bundle surfaces and commands. The
disposition below prevents parallel replacements and makes prior art explicit.

### 4.1 Existing implementation disposition

| Existing surface | Disposition | Reason |
|---|---|---|
| [`IngestChurchServiceSourceRevision`](../../app/Actions/IngestChurchServiceSourceRevision.php), source-record/assertion models and migrations | **Keep and repair invariants** | Correct immutable-evidence seam; active-lineage and adapter inputs need repair. |
| Email/OpenLP source adapters and import services | **Repair** | Parse assertions from the source payload before projection; remove canonical-row feedback. |
| [`ChurchServiceProjector`](../../app/Services/ChurchService/ChurchServiceProjector.php) | **Repair in place** | Keep one projector; replace active-revision, matching and anchored-order behavior. |
| Proposal stager, evidence review action and existing class-based Livewire workbench | **Repair in place** | Keep one *per-service* review surface; fix visibility, validation, decision completeness and automatic finalisation. §9.4 adds a cross-service proposal queue alongside it — that is a second surface over the same actions and models, not a second workbench. |
| `ChurchServiceCanonicalManifest`, Bundle B exporter/importer/auditor | **Repair in place** | Preserve R8 ownership and extend exact automatic/manual/proposal state. |
| Historic bundle validator, readiness service and command surfaces | **Keep and repair schema** | Existing seams are useful; the durable contract and binding preparation are incomplete. |
| `HistoricProcessingResultInventory` and metadata serializer | **Repair in place** | Adopt the consolidated contract matrix and portable identities; do not create a second inventory. |
| [`HistoricProcessingResultAssetTransfer`](../../app/Services/HistoricMedia/HistoricProcessingResultAssetTransfer.php) | **Repair immediately** | Replace whole-file reads with the already-proven streaming pattern. |
| [`HistoricMediaGraphPersister`](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php) | **Repair immediately and add a direct MySQL suite** | It has one real but minimal importer smoke test, no direct suite, and no published graph coverage. |
| `HistoricProcessingResultBundleImporter` | **Keep as Bundle A owner** | Repair classification and live equality; do not replace it with the sermon-only importer. |
| `ChurchServiceConvergenceBundleImporter` | **Keep as Bundle B owner** | Repair automatic/manual/proposal semantics and adopt the shared classification vocabulary. |
| [`SermonPromotionBundleImporter`](../../app/Services/Sermon/SermonPromotionBundleImporter.php) | **Reuse concepts, not wholesale** | Reuse strong-identity/preflight/reclassification conflict logic; it is sermon-only, has no enrichment, and does not copy Bundle A assets. |
| [`SermonPromotionAssets`](../../app/Services/Sermon/SermonPromotionAssets.php) | **Extract/reuse streaming hash pattern** | It already uses `readStream()` plus incremental SHA-256. |
| `ConvergeHistoricChurchService` and its command | **Repair as sole orchestrator/entry point** | Make dry run call real prepare and bind apply to the complete state. |
| Legacy merge writers and spent one-shots | **Retain temporarily, then delete after G9** | Required for compatibility/operation; never source truth for new assertions. |

Use one target classification vocabulary everywhere:

- `already_present` — current live graph and assets are exactly equal;
- `create` — no related target identity exists;
- `safe_enrichment` — the same natural identity can receive non-conflicting missing durable data;
- `blocked_difference` — related target data differs and requires an approved decision; and
- `conflict` — identity/invariant/corruption prevents safe application.

`apply` is an operation, not a classification. Manifest `included`/`excluded` is corpus curation,
not target classification. Extract shared classification value objects/policy where useful rather
than widening the sermon-only importer or maintaining three incompatible string vocabularies.

### 4.2 Blocker evidence and required red tests

Each blocker remains live until its named test fails for the recorded reason and then passes with
the fix. Line links are audit anchors, not permanent documentation of implementation behavior.

| ID | Severity and evidence | Required red test | Owner |
|---|---|---|---|
| B1 | **Certain crash.** Publications are created before the run at [`HistoricMediaGraphPersister.php:28`](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php#L28) and carry the missing FK at [line 81](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php#L81); MySQL enforces it at [`mysql-schema.sql:840`](../../database/schema/mysql-schema.sql#L840). | `HistoricMediaGraphPersisterTest::it_creates_the_run_before_linked_publications` | WP5 immediate tranche |
| B2 | **Certain crash.** Published sections are inserted without extraction media/timestamp at [`HistoricMediaGraphPersister.php:199`](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php#L199); the check fires at [`mysql-schema.sql:972`](../../database/schema/mysql-schema.sql#L972). | `HistoricMediaGraphPersisterTest::it_transitions_a_section_to_published_only_after_required_media_exists` | WP5 immediate tranche |
| B3 | **Data corruption.** Email reads all canonical items at [`InboundEmailImportService.php:533`](../../app/Services/Email/InboundEmailImportService.php#L533); OpenLP does the same at [`ImportChurchServiceFromOpenLp.php:211`](../../app/Services/ChurchService/ImportChurchServiceFromOpenLp.php#L211). | `IndependentSourceEvidenceTest::it_does_not_attribute_another_sources_items_to_email_or_openlp` | WP1 |
| B4 | **Non-portable identity.** Projector falls back to local `song-id` at [`ChurchServiceProjector.php:155`](../../app/Services/ChurchService/ChurchServiceProjector.php#L155); section signatures include item IDs at [`ServiceSection.php:195`](../../app/Models/ServiceSection.php#L195). | `HistoricPortableIdentityTest::it_produces_identical_song_and_section_keys_with_different_database_ids` | WP1/WP4 |
| B5 | **Wrong revision.** Active revisions sort by timestamp plus lexical hash at [`ChurchServiceProjector.php:98`](../../app/Services/ChurchService/ChurchServiceProjector.php#L98). | `ChurchServiceProjectorTest::explicit_revision_lineage_wins_when_capture_times_match` | WP1 |
| B6 | **Wrong order.** Missing observed time becomes `INF` and is globally sorted at [`ChurchServiceProjector.php:299`](../../app/Services/ChurchService/ChurchServiceProjector.php#L299). | `ChurchServiceProjectorTest::planned_only_items_are_inserted_between_observed_plan_anchors` | WP2 |
| B7 | **Needless/incorrect separation.** Base matching is limited to exact stable/title identities at [`ChurchServiceProjector.php:155`](../../app/Services/ChurchService/ChurchServiceProjector.php#L155). | `ChurchServiceProjectorTest::compatible_incomplete_sources_match_without_a_proposal` | WP2 |
| B8 | **Guaranteed equality failure after remap.** Inventory hashes local paths at [`HistoricProcessingResultInventory.php:52`](../../app/Services/HistoricMedia/HistoricProcessingResultInventory.php#L52), paths are remapped at [`HistoricMediaGraphPersister.php:257`](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php#L257), then compared at [`ConvergeHistoricChurchService.php:278`](../../app/Services/ChurchService/ConvergeHistoricChurchService.php#L278). | `HistoricProcessingResultRoundTripTest::logical_hash_survives_path_and_metadata_remapping` | WP4/WP5 |
| B9 | **Non-equivalent output.** Section inventory omits durable state at [`HistoricProcessingResultInventory.php:126`](../../app/Services/HistoricMedia/HistoricProcessingResultInventory.php#L126) and publication inventory is incomplete at [line 195](../../app/Services/HistoricMedia/HistoricProcessingResultInventory.php#L195). | `HistoricNormalOutputContractTest::fails_when_any_durable_canary_field_or_relationship_is_unclassified` | WP0/WP4 |
| B10 | **Lost logical owners.** Distinct roles are emitted, then later roles sharing a path are discarded by `unique('path')` at [`HistoricProcessingResultBundleExporter.php:276`](../../app/Services/HistoricMedia/HistoricProcessingResultBundleExporter.php#L276). Shared paths are normal: sermon options reuse section media at [`SermonCreationOptions.php:170`](../../app/Data/SermonCreationOptions.php#L170), and song publication assigns one promoted path to section and `SongVideo` at [`SongPublicationHandler.php:173`](../../app/Services/ChurchService/SectionPublication/SongPublicationHandler.php#L173). The role is lost before import; duplicate destination allocation is not the current symptom. | `HistoricProcessingResultBundleExporterTest::it_preserves_all_logical_roles_for_shared_physical_content` | WP4 |
| B11 | **Unnecessary human gate.** Bundle B export requires a completed review/Manual source at [`ChurchServiceConvergenceBundleExporter.php:67`](../../app/Services/ChurchService/ChurchServiceConvergenceBundleExporter.php#L67). | `ChurchServiceConvergenceBundleExporterTest::it_exports_a_conflict_free_automatic_finalisation_without_manual_review` | WP2/WP3 |
| B12 | **Unclosable ambiguity.** Bundle B omits proposal dispositions at [`ChurchServiceConvergenceBundleExporter.php:162`](../../app/Services/ChurchService/ChurchServiceConvergenceBundleExporter.php#L162) and imports an empty proposal list at [`ChurchServiceConvergenceBundleImporter.php:212`](../../app/Services/ChurchService/ChurchServiceConvergenceBundleImporter.php#L212). | `ChurchServiceConvergenceBundleImporterTest::it_reproduces_every_reviewed_proposal_disposition` | WP3/WP6 |
| B13 | **Silent false acceptance plus hidden work.** A proposal selected into the review but absent from the resolution map defaults to `accepted` at [`ReviewChurchServiceEvidence.php:132`](../../app/Actions/ServiceReview/ReviewChurchServiceEvidence.php#L132), then attention is cleared. The UI also pre-seeds selected proposals as accepted at [`ShowChurchService.php:398`](../../app/Livewire/Admin/ChurchServices/ShowChurchService.php#L398). Omission is incorrectly recorded as a positive human decision; a proposal deselected from the review is the narrower case that currently remains pending. | `EvidenceReviewTest::selected_proposal_without_an_explicit_resolution_fails_closed_and_remains_pending`; `EvidenceReviewTest::partial_review_keeps_the_service_in_the_attention_inbox` | WP3 |
| B14 | **Unbound apply.** Dry run hashes envelopes/indexes without real preparation at [`ConvergeHistoricChurchServiceCommand.php:37`](../../app/Console/Commands/ConvergeHistoricChurchServiceCommand.php#L37). | `ConvergeHistoricChurchServiceCommandTest::plan_hash_changes_with_every_prepared_target_or_asset_state` | WP6 |
| B15 | **TOCTOU.** OoS apply reloads mutable staged parse state at [`OosArchiveAssertionBundle.php:235`](../../app/Services/Email/OosArchiveAssertionBundle.php#L235); OpenLP opens files after plan creation in [`ImportOpenLpDirectoryCommand.php:69`](../../app/Console/Commands/ImportOpenLpDirectoryCommand.php#L69). | `HistoricSourceApplyIntegrityTest::changed_oos_or_openlp_content_aborts_before_canonical_writes` | WP1/WP6 |
| B16 | **Wrong service identity risk.** Historic video command accepts directory/date filters but no approved work manifest at [`ImportHistoricVideoBatchCommand.php:15`](../../app/Console/Commands/ImportHistoricVideoBatchCommand.php#L15). | `ImportHistoricVideoBatchCommandTest::every_dispatch_uses_the_approved_manifest_identity_not_filename_inference` | WP1/WP7 |
| B17 | **Certain resource failure at scale.** Asset copy/verify uses whole-file reads at [`HistoricProcessingResultAssetTransfer.php:44`](../../app/Services/HistoricMedia/HistoricProcessingResultAssetTransfer.php#L44); exporter does likewise at [`HistoricProcessingResultBundleExporter.php:281`](../../app/Services/HistoricMedia/HistoricProcessingResultBundleExporter.php#L281). | `HistoricProcessingResultAssetTransferTest::large_assets_are_hashed_verified_and_copied_only_as_streams` | WP4 immediate tranche |
| B18 | **Duplicate/conflict risk.** Graph persister unconditionally creates publications at [`HistoricMediaGraphPersister.php:56`](../../app/Services/HistoricMedia/HistoricMediaGraphPersister.php#L56); existing strong-identity prior art starts at [`SermonPromotionBundleImporter.php:141`](../../app/Services/Sermon/SermonPromotionBundleImporter.php#L141). | `HistoricPublicationConvergenceTest::existing_publications_are_reused_enriched_or_blocked_never_blindly_created` | WP5 |
| B19 | **False no-op.** Bundle A trusts cached import hash instead of rebuilding live state at [`HistoricProcessingResultBundleImporter.php:123`](../../app/Services/HistoricMedia/HistoricProcessingResultBundleImporter.php#L123). | `HistoricProcessingResultBundleImporterTest::already_present_revalidates_the_live_graph_and_destination_assets` | WP5/WP6 |
| B20 | **Worker isolation unproven.** Guard checks process config/disk name at [`HistoricStagingGuard.php:35`](../../app/Services/HistoricMedia/HistoricStagingGuard.php#L35), not the resolved storage identity used by each worker. | `HistoricWorkerStorageIsolationTest::queued_dispatches_write_only_below_the_resolved_batch_root` | WP7 |
| B21 | **Product gap, not a production-import blocker.** Service routes exist only inside the admin group at [`web.php:163`](../../routes/web.php#L163). Fixable now against current-era data; needs nothing from the import. | `PublicChurchServiceArchiveTest::published_service_history_links_service_sermon_talk_and_songs_without_private_evidence` | WP8 / PR1, ships first |

## 5. Delivery order and gates

```text
WP8 public service history [ships first, against data production already holds]
WP0 contract canary
  -> immediate crash/scale fixes: B1 + B2 from WP5 and B17 from WP4
  -> WP1 independent evidence and manifests
  -> WP2 deterministic projector and automatic finalisation
  -> WP3 exceptional review, review-load automation loop and Bundle B decisions
  -> remaining WP4 portable Bundle A and assets
  -> remaining WP5 persistence and existing-record convergence
  -> WP6 binding preflight, atomic convergence, current-era re-projection and exact audit
  -> WP7 isolated acquisition and full rehearsal
  -> WP9 production operation and closeout
  -> WP10 contract/delete spent compatibility and one-shots
```

The work-package order describes dependency ownership; the implementation order is risk-first.
B1, B2 and B17 were landed immediately after the canary because they were certain first-use failures
and small enough to repair without waiting for the full graph redesign. Their tests remain in the
permanent WP0 canary suite. The fixed code still cannot be used for a batch until later gates pass.

**WP8 moved to the front on 2026-08-02.** It was previously sequenced after G9 on the correct
observation that it is not an import-readiness gate. That is a gating argument, and it had been
doing duty as a sequencing argument. Public service history is buildable now against the current-era
services production already holds; it needs nothing from the import. Shipping it first delivers the
only visitor-visible outcome in the programme immediately, and forces the §14.2 exposure and policy
questions to be answered against real content before the import budget is spent. The import then
extends a working feature backwards in time instead of being the precondition for one existing.

**No gate in this plan blocks on elapsed calendar time.** Where a predecessor revision required a
soak period before cleanup, the requirement is now the evidence that soak was intended to produce:
the exact auditor green, a second fully no-op import, and passing smoke tests. Backups, staging,
bundles and ledgers are still retained — you cannot un-delete — but retention never gates progress.

| Gate | Meaning | Mutation allowed? |
|---|---|---|
| G0 | Plan accepted; read-only corpus work only | No |
| G1 | Output contract pinned; blocker tests recorded; B1/B2/B17 red then green | No |
| G2 | Evidence/projector/review tests green; §9.4 corpus proposal census triaged to its stopping condition | No |
| G3 | Different-PK Bundle A/B canary round trip green | No |
| G4 | Binding no-write whole-operation preflight green | No |
| G5 | Complete local rehearsal, rollback and second no-op import green | No |
| G6 | Existing public sermon/talk/song and admin smoke; all quality gates green | No |
| G7 | Maintainer accepts private manifests/reports/operation | No |
| G8 | Production-window prerequisites revalidated | Production once |
| G9 | Exact post-run audit, second fully no-op import and smoke tests green | Cleanup allowed |

## 6. WP0 — Pin the current-output contract

**Purpose:** define “as if processed normally” using current code before changing portability.

### Scope

Add a deterministic canary using the real persistence path and MySQL constraints. It contains:

- a completed full-service livestream run and all main/fan-out processing steps;
- source segments and multiple service sections;
- a published sermon;
- a published children's talk with resolved speaker metadata;
- a confirmed repeated song and `SongVideo`;
- a planned-only item between observed anchors;
- full-service transcript/RMS/service artifacts;
- shared media referenced by section/publication/song video;
- scripture, preacher, quality, visibility and thumbnail state; and
- every normal segment/section/item/publication/run relationship.

Provider calls may use deterministic fixtures; the persistence chain and fan-out composition must
be real, not a hand-built minimal Bundle A array.

Adopt and consolidate the historic plan's HM0 transport taxonomy. The one versioned contract matrix
has independent columns:

| Dimension | Allowed values |
|---|---|
| Presence | `required`, `nullable` |
| Transport treatment | `portable`, `deterministically_rebuilt`, `ephemeral` |
| Remap strategy | `none`, `natural_key`, `asset_path`, `local_foreign_key` |

`production-remapped` is not a fourth transport treatment; it is a remap strategy for portable or
deterministically rebuilt data. This matrix replaces both predecessor prose taxonomies. The test
fails whenever a model field/relationship is unclassified or receives an invalid combination.

### Red tests before fixes

Write and demonstrate a failing regression for every B1–B20 issue, especially:

- publication foreign-key and section check-constraint failures;
- shared paths retaining all roles;
- different-PK section/song identities;
- path remapping changing the current logical hash;
- corrected email revision losing to its predecessor;
- planned A–B–C plus observed A–C becoming A–C–B; and
- a stream spy proving no whole-file media read is used.

### Acceptance

- The canary manifest is stable and complete.
- Removing a required field/relationship fails the contract.
- All known blockers have red reproducers.
- Exporter/importer behavior is not changed in this first package, except for the deliberate Bundle A
  portable-section-identity cutover below.

### Bundle A portable-section-identity cutover

WP0 introduces a portable Bundle A section identity in place of the legacy export key's database-ID
inputs, using service-item and children's-talk-speaker identities instead. This necessarily changes
every affected `section_key`, its derived publication keys and the enclosing logical hash. **All
Bundle A archives exported before this cutover are void**: do not import or audit them. Regenerate
them from the source processing result only after the WP4 Bundle A schema/round-trip work is ready.
This is the sole intentional exporter/importer compatibility break in WP0; Bundle B's canonical
manifest remains unchanged.

The portable Bundle A key is deliberately distinct from `ServiceSection::classificationSignature()`.
The latter remains a live approval/provenance fingerprint, including local mutation state so a
changed association invalidates an approved publication. Reusing it for Bundle A would reintroduce
the non-portable identity defect. The portable key independently retains children's-talk speaker
name/slug/source, so a speaker change remains distinct without copying a local preacher ID.

### Immediate crash/scale repair tranche

Directly after the canary/red-test PR, fix B1, B2 and B17 before broader architecture work:

1. Add `tests/Integration/Services/HistoricMedia/HistoricMediaGraphPersisterTest.php`. The current
   importer test at
   [`HistoricProcessingResultBundleImporterTest.php:90`](../../tests/Unit/Services/HistoricMedia/HistoricProcessingResultBundleImporterTest.php#L90)
   executes the real persister, but its fixture has no publications/song videos and uses only a
   `not_applicable` section. It is a minimal smoke test, not persister coverage.
2. Create the processing log before any sermon/talk carrying its processing foreign key.
3. Persist extracted paths/timestamps before transitioning a section to approved/published.
4. Replace exporter/transfer whole-file reads using the streaming hash pattern already implemented
   in [`SermonPromotionAssets`](../../app/Services/Sermon/SermonPromotionAssets.php).
5. Cover sermon, children's talk, confirmed song/`SongVideo`, shared roles, different destination
   IDs, rollback compensation and pre-existing identical/different assets in the direct suite.

Landing these fixes does not release the batch command. It merely removes certain crashes and scale
failure while later correctness gates remain closed.

## 7. WP1 — Independent source revisions and authoritative manifests

**Purpose:** make each source truthful, immutable and safe to replay.

### 7.1 Pure source adapters

Refactor Email and OpenLP ingestion into:

1. parse/normalize assertions directly from that source payload;
2. persist the source revision; and
3. invoke the projector.

Remove `sourceItems($churchService)` from source-adapter paths. Legacy merge services may survive
temporarily as compatibility readers, but cannot manufacture assertions or write canonical items
before evidence ingestion.

Assertions carry portable values where known: raw/normalized title, semantic type, section type,
`song_canonical_key`, scripture key/reference, source position, occurrence ordinal, observed timing
and allowlisted source metadata. Production-local foreign keys may be resolved after import but are
not portable/hash identity.

### 7.2 Revision lineage

The active revision is the unique leaf of an explicit supersession chain per `source + source_key`:

- identical normalized payload is an idempotent no-op;
- changed payload links to its exact predecessor;
- multiple active leaves are a hard conflict;
- `captured_at` is audit data, not authority; and
- correction tests retain the same original email timestamp.

Add any necessary lineage constraint/index through a focused additive migration. Never select an
active revision by lexical hash.

### 7.3 Versioned corpus manifest

Use one strict manifest format across Email, OpenLP and livestream acquisition. Each item has:

- stable batch/item key and source kind;
- path relative to the approved root, byte size and SHA-256;
- explicit date and service enum;
- include/exclude and reason;
- duplicate-of identity;
- parse/concatenation decision;
- aliases/title overrides;
- expected occurrence/count information; and
- decision author/time or approved rule version.

Filename/time heuristics may propose entries, but the approved manifest is mutation authority. All
single-file, concatenated and re-encoded dispatches receive explicit manifest date/service
overrides.

Evidence staging resolves the service by that natural identity under a lock. Zero existing rows
creates one skeletal service for projection; one reuses it; more than one is a hard data-integrity
failure. A livestream-only historic service must therefore remain promotable without requiring an
Email/OpenLP row to have been imported first.

### 7.4 OoS and OpenLP bundles

OoS export transports normalized revisions/assertions, not a parse cache later reloaded from a
mutable `InboundEmail`. Raw bodies stay out of production. Apply compares the exact staged revision
with the approved payload.

OpenLP dry run parses every included file and validates song resolution/projected effects against a
disposable database. It rechecks path, size and hash before staging and under the apply lock. A
change aborts before the first canonical write.

### 7.5 The Email corpus and its manifest — added 2026-08-06

§7.3 says "one strict manifest format across Email, OpenLP and livestream acquisition". Two of the
three were built — `OpenLpCurationManifest` and `HistoricVideoCurationManifest` — and the Email one
was not. The gap survived the 2026-08-06 audit because that audit asked whether the manifest schema
carried §7.3's curation-authority fields, and it does; it did not ask which sources had a manifest at
all. `OpenLpCurationManifest` rejects any entry whose `source_kind` is not its own, so the class is
structurally single-source and cannot absorb Email by configuration.

**The Email corpus needs no drive.** The formatted root is `storage/scratch/oos/`: 261 markdown files,
one per order of service, spanning 2014-09-14 to 2026, each carrying YAML frontmatter (`title`, `date`, `year`,
`source`, `extraction`, and sometimes `service`, `source_subject`, `date_source`). Its raw
counterpart is `storage/scratch/oos-verbatim/`, now 533 files; the old 402-file count belongs to the
historical 2026-08-06 snapshot.

The earlier aggregate `storage/scratch/crockenhill_orders_of_service_archive.md` (102 entries) is
**superseded** and must not be curated. It survives only as the cited `source:` of 14 `oos/` files.

`ImportOosArchiveCommand` **was repointed at the manifest on 2026-08-06** (see "The command now
takes an approved plan", below). It no longer accepts an aggregate markdown path at all, and
`OosArchiveMarkdownParser` is deleted.

#### Current inventory, measured 2026-08-09

The 2026-08-06 approval is no longer the current Email authority. The local roots now measure:

| Population | Count |
|---|---:|
| Verbatim files (`oos-verbatim/`) | 533 |
| Formatted files (`oos/`) | 261 |
| Verbatim files claimed by the approved manifest | 402 |
| **Unmanifested verbatim files** | **131** |
| Paired (one email, both extractions) | 259 |
| **Verbatim with no formatted counterpart** | **274** |
| **Formatted with no verbatim counterpart** | **2** |
| **Root-level reconciliation target** | **535 entries** |

All 131 added files are non-empty raw evidence. None has a formatted counterpart in the current
`oos/` root, so the target is `533 + 261 - 259 = 535` entries before curation decisions. An
exclusion is still an entry: §13.1 requires every discovered file to be explicitly included or
excluded, with a reason. The additions span 2014-08-31 through 2026-08-09, with most new files in
2015–2021; they therefore change the corpus's historical coverage rather than merely extending its
latest tail.

The new material also includes current-era files (`2026-08-02-am.md`, `2026-08-02-pm.md` and
`2026-08-09.md`) and documents containing partial, revised or multiple-service content. Their
historic/current operation boundary, resolved service identity and any supersession must be decided
before F1's service baseline is recalculated. The old 391-identity count, 219/391 local match and
56% rehearsal estimate below are historical measurements against the superseded snapshot.

The existing `storage/scratch/oos-curation-manifest.json` and its `manifest_hash`/`plan_hash` remain
valid only for the unchanged 2026-08-06 roots. `OosCurationManifest::plan()` must reject the current
roots until a replacement manifest claims every file; do not quote the old `plan_hash` for a new
dry run, bundle or import.

#### Historical inventory measured 2026-08-06

Measured 2026-08-06 against the two directories, then corrected the same day when the draft
generator exposed the pairing rule below. These are the reconciliation target for §13.1's
`discovered = included + excluded`, on the Email side:

| Population | Count |
|---|---|
| Verbatim files (`oos-verbatim/`) | 402 |
| Formatted files (`oos/`) | 261 |
| **Manifest entries** | **404** |
| Paired (one email, both extractions) | 259 |
| **Verbatim with no formatted counterpart** | **143** |
| **Formatted with no verbatim counterpart** | **2** |

**Pair by provenance and by email, never by filename.** A first pass paired the two roots on
filename stem and got 247/155/14. Every one of those figures is wrong, and the way they are wrong is
the finding.

Only **161** formatted files carry a `source:` frontmatter naming a verbatim path. The other **100**
cite the superseded aggregate archive and declare
`extraction: email body via Gmail (second-hand, spot-checked)`. The two roots do not agree on
filenames: the same email is filed as `2022-02-20.md` in one root and `2022-02-20-am.md` in the
other, so stem-pairing both misses real pairs and invents false ones. Matching on the **email** —
same date and same `source_subject` once `Re:`/`Fwd:`/`Fw:` prefixes are normalised — recovers them.

Two entries had to be matched on subject alone, across a date the filename got wrong, and both are
confirmed by a `note:` the corpus's own curator left:

| Formatted file | Matched verbatim | Why |
|---|---|---|
| `oos/2026-03-15-2.md` | `oos-verbatim/2026-02-15.md` | Subject and body heading both read "15th March"; the email was sent Fri 13 Feb for the following Sunday. It is the **15 February** order. |
| `oos/2026-06-05.md` | `oos-verbatim/2026-07-05.md` | Subject reads "5th June 2026"; content (Joshua 5:1-12, Andrew Wilson preaching) confirms **5 July**. 5 June 2026 is a Friday. |

That fallback is only safe because it requires the subject to be **unique across the whole verbatim
corpus**; "order of service for sunday" recurs 36 times and is excluded by construction.

One stem remains a genuine collision — `2026-03-15-am` names a *different email* in each root, the
original in verbatim and its revision in formatted. Stem-pairing merged the revision into its own
predecessor, destroying exactly the §7.2 supersession the manifest exists to record. It is split into
two entries.

Where an email exists in both roots, **the verbatim body is the payload**: it is first-hand, whereas
a formatted file citing the aggregate is second-hand and spot-checked. Where the formatted file
genuinely derives from the verbatim (`source:` names it), the formatted file is the payload. Where
the two disagree about the date, the payload's own frontmatter wins — it is the document being
parsed that must be believed, not its companion.

The residuals are real curation decisions, not tidying:

- **In the historical 2026-08-06 snapshot, the 143 verbatim-only files were almost entirely 2022 and later.** Formatting covered 2014–2021
  densely and then thinned out. Each is either an include the manifest must account for or an
  exclusion carrying a reason; §13.1 forbids an unresolved included item. Including them is what
  makes the corpus's yearly distribution even (roughly 42–55 services a year from 2022 on) instead of
  collapsing after 2021.
- **The 2 formatted-only entries** — `2024-11-03` and `2026-03-15-am-second-hand` — have no raw body
  at all, and the manifest records why in `verbatim_absence_reason`.

The 2026-08-09 additions supersede the first bullet as a description of the current corpus: the
new raw-only material is concentrated in 2015–2021 as well as adding a small current-era tail.

*(Corrected 2026-08-07: this bullet pair previously read 144 and 3. The table above, §13.1 and the
manifest itself all say 143 and 2, and the dry run's `counts` block agrees; the prose was the only
place carrying the older figures.)*

#### Four concepts are conflated in one free-text field

`service:` appears on 55 of the 261 files with **24 distinct values**, and they are not all services:

| Concept | Observed values |
|---|---|
| Service identity | `am`, `pm`, `Easter Sunday`, `Good Friday`, `Palm Sunday`, `Remembrance Sunday`, `Christmas morning`, `Carols by Candlelight`, `Baptismal service`, `family service` |
| Content completeness | `details`, `hymns`, `songs`, `song`, `partial order details`, `hymns and headings`, `pm hymns` |
| Revision lineage | `revised` |
| Extraction provenance | `order attached to email`, `email body with order attachment noted`, `Adventurers' Play`, `Adventurers participation` |

Decomposing that field is most of what the Email manifest is for. `SermonService` has three cases —
`morning`, `evening`, `other` — so every named service above resolves to `other` with the name
preserved as a title override, and none of the completeness, lineage or provenance values is a
service at all.

The lineage row is the sharpest. `2015-12-27-revised.md` carries `service: "revised"` and is a
*corrected revision of the same morning service* as `2015-12-27.md` — reading its own body confirms
it ("here is the revised order of play"). Under §7.2 that is a supersession chain, and encoding it as
a service identity would produce two canonical services on one date where one exists. 13 dates carry
multiple files; three of them are `-revised` pairs.

#### Dates inferred from the liturgical calendar

Nine files declare `date_source: derived from liturgical calendar (heading carried no explicit
date)` — Maundy Thursday and Good Friday 2023, Easter 2023/2025/2026, Christmas 2023/2024/2025.
§7.3 already governs these: "filename/time heuristics may propose entries, but the approved manifest
is mutation authority." Each becomes an explicit approved manifest date, with the inference recorded
as its reason rather than trusted silently. `2026-03-15-2.md` is the same case in a louder form: its
own title says `[email title likely intended 15 February]`.

#### Historical draft manifest, 2026-08-06

The historical draft was generated from filename and frontmatter signals and **validated against
`OosCurationManifest`**: 404 entries, all `include`, 363 full and 41 partial, 10 supersessions,
9 inferred dates. It lives at `storage/scratch/oos-curation-manifest.draft.json`, generated by
`storage/scratch/draft_oos_manifest.php`. It does not claim the 131 files added on 2026-08-09.

It carries `decision_rule_version: oos-curation-draft-v1` and **no `decided_by` on any entry**. That
is deliberate and is what §7.3's two authority forms are for: the operator approves the *rule set*,
which covers the bulk mechanically, and rules individually only on the residue. Nothing in the draft
claims a human decided it.

Three things about the draft that a reviewer should not have to discover:

- **`expected_item_count` is optional here, and that is a deliberate divergence from OpenLP.**
  Resolved 2026-08-06; see the dry-run section below.
- **Ambiguity is resolved downwards, never upwards.** Where the rules cannot rank two full orders for
  one service, the extra is demoted to `partial` rather than being asserted as a supersession.
  "This document asserts part of the service" is true of any of them, and §8.4 keeps a partial's
  silence from being read as disagreement, so the weaker claim is the safe one.
- **A named service that has an `-am`/`-pm` suffix keeps the enum value the suffix implies.**
  Easter Sunday morning resolves to `morning`, not `other`, with "Easter Sunday" preserved in
  `title_override`. Whether the named-service identity should instead win is an operator call the
  draft does not make.

The twelve proposed entries — nine revision-signal supersessions, the two corpus-unique subject
matches above, and `2017-06-11`'s demotion to partial — were **confirmed by the maintainer on
2026-08-06**. They keep the draft rule version, since a rule reached each of them.

#### Rule set approved, 2026-08-06

The maintainer approved the rule set on 2026-08-06. The draft was promoted to
`storage/scratch/oos-curation-manifest.json` — `decision_rule_version: oos-curation-v1` on the same
402 entries, `batch_key: oos-approved-2026-08-06`, and the 2 individually-decided entries untouched.
It validates through `OosCurationManifest` over all 404 entries with **0 adjudicated identity
disagreements**:

| | |
|---|---|
| `manifest_hash` | `2d96938c9d54483f15c7a1bd82a123a2cfe9370634bc52be655418bd3e645330` |
| `plan_hash` | `c13b8b67d108f0c9bc3934bd2bea5cb15bf8889dfa5ba3760fde6b2732c28ded` |

Neither file is committed: `storage/scratch/*` is gitignored, the manifest carries source paths, and
this repository is public (§3.3). This section is the retained approval record; the plan hash above is
what an `--import` run for that unchanged snapshot would have had to quote back. It is not a valid
plan hash for the expanded roots.

Promoting the rule version does not re-identify anything. `OosCurationEntryFactory::messageId()`
derives the synthetic message id from `item_key` alone, so neither the rule version nor the batch key
feeds it — the same corpus staged before and after approval reaches the same rows.

##### The service enum: seven maintainer rulings, 2026-08-06

Cross-checking `resolved_service` against the actual weekday showed the draft rules contradicting
themselves at the liturgical edges — two Palm Sundays classified differently, and "Christmas Morning"
resolving to `morning` in 2025 but `other` in 2020 and 2024. The maintainer settled it with a rule the
draft never had: **a morning service is `morning` whatever the weekday, and `other` is reserved for
services outside the Sunday morning/evening cycle.** Seven entries were overridden and now carry
`decided_by`/`decided_at` alongside the rule version, since a rule reached every other field:

| Entry | Weekday | Was | Now |
|---|---|---|---|
| `2026-03-29` Palm Sunday | Sun | `other` | `morning` |
| `2021-12-26` Boxing Day | Sun | `other` | `morning` |
| `2020-12-25` | Fri | `other` | `morning` |
| `2022-12-25-christmas` | Sun | `other` | `morning` |
| `2023-12-24` | Sun | `other` | `morning` |
| `2023-12-25` | Mon | `other` | `morning` |
| `2024-12-25` | Wed | `other` | `morning` |

`2025-12-25` already resolved to `morning` and is unchanged. The five entries still on `other` are all
Good Friday or Maundy Thursday, which genuinely sit outside the Sunday cycle:
`2018-03-30-good-friday`, `2022-04-14-maundy-thursday`, `2023-04-06-maundy-thursday`,
`2023-04-07-good-friday` and `2023-04-07-good-friday-revised`.

Three things the rulings established that are worth carrying forward:

- **`2021-12-26`'s draft `service_label` was simply wrong.** It read `Christmas Morning` for a Boxing
  Day service whose own source subject is "BOXING DAY order of service (Sunday morning)". A filename
  and frontmatter heuristic produced a label the source contradicts, which is the clearest available
  argument for §7.3's rule that the approved manifest — not the heuristic — is mutation authority.
- **`2022-12-25` holds one service, not several.** Read against the source it is a single "Christmas
  Morning Order of Service"; nothing is being collapsed by giving it one enum value.
- **`2023-12-24` holds three orders across two dates** — the Sunday 24th morning order (complete), the
  Sunday 24th evening carol list (titles only, the email says the order "is to follow"), and a
  Christmas morning order for the **25th**. It resolves to the 24th's `morning` because that is its
  primary complete order. The evening carols are a second service on the same date and arrive through
  §7.5's `service_beyond_manifest` path. The Christmas morning tail is dated outside the curated
  service and will be skipped — correctly, because `2023-12-25` carries the identical order
  authoritatively, sent on the day. Nothing is lost.

`service_label` was cleared on all seven, because the schema requires it exactly when
`resolved_service` is `other` (`OosCurationManifest.php:587`) — an invariant that caught the first
attempt at this edit. The named identity survives in `title_override`, which is the pattern §7.5
already describes for Easter Sunday. `2021-12-26` deliberately gets no `title_override`: its own
frontmatter title is correct, and an override's absence means "use the source".

#### 2026-02-22: the one decision a rule could not reach

`2026-02-22` was the draft's only unrankable service, holding three documents. The maintainer looked
up the original email and supplied the order, which settles it — and corrects a reading in an earlier
revision of this section.

The supplied order ends **"Hymn NIP I will glory in my Redeemer"**. That line appears in exactly one
of the three documents:

| Document | Final hymn | What it is |
|---|---|---|
| `oos-verbatim/2026-02-22-am.md` | `Hymn (Mark)` | the original, final hymn unresolved |
| `oos-verbatim/2026-02-22-am-revised.md` | `Hymn NIP I Will Glory in My Redeemer` | **the active order** |
| `oos/2026-02-22.md` | `Hymn (Mark)` | second-hand extraction of the *original* |

So `oos/2026-02-22.md` is **not** a merge of both emails, as its thread subject
`Order of Service / Revised Order of Service` implied — it carries the original's body. It is paired
with the verbatim original as its second-hand formatting, the revision supersedes the original, and
nothing is excluded. The automatic revision-signal rule independently proposed the same supersession
once the third document left contention, which is a useful cross-check rather than a coincidence.

These two entries carry `decided_by` and `decided_at` rather than the draft rule version, because a
person really did decide them by reading the source. The supplied order also gives the corpus its
**first human-verified item list** — 13 items — and is worth carrying into §13.4's per-era truth set,
where it is currently the only Email-side ground truth that exists.

#### Dry-run reconciliation, and why it stops short of items

Added 2026-08-06. `OosCurationManifest::validateIncludesForDryRun()` is the OoS analogue of
OpenLP's, and it is deliberately narrower.

**Item-level reconciliation cannot live in a dry run here.** OpenLP parses an `.osz` locally: free,
deterministic, so requiring `expected_item_count` and failing on a mismatch costs nothing and catches
a dropped item. An order of service is turned into items by an **LLM** —
`OosEmailParserService` calls `OosEmailItemExtractor` and may issue a corrective second attempt, and
its own code path contemplates two structurally valid attempts disagreeing. Reconciling a count
against that would make a dry run cost money per entry and return a different answer on different
days. A gate that fails on model weather is worse than no gate.

So the two halves are split:

- **Identity reconciliation — deterministic, free, runs always.** The manifest's `resolved_date` and
  `resolved_service` are compared against the payload file's own frontmatter. `strict` fails closed
  on a disagreement; `manifest-authoritative` records that an operator has ruled on it. This is the
  exact analogue of OpenLP's embedded-`.osj` filename-mismatch rule. Only `am`/`pm` are compared from
  the corpus's `service:` field, because the other 22 values in it are not services at all.
- **`expected_item_count` is nullable, and asserting one requires `decided_by`.** Null means "no
  count asserted". A heuristic line count recorded in this field would be a machine guess sitting in
  a field that means "a person decided this" — the B13 defect in miniature — so the draft emits none,
  and the generator's proposed counts stay in its report as a starting number for whoever verifies.
  Exactly one entry asserts a count today: `2026-02-22-am-revised`, at 13, from the maintainer's
  transcription.

**The 2026-08-06 dry run passed over all 404 entries with zero disagreements**, and it found a real
rule error on its first run — see below. The 2026-08-09 replacement now passes the same check over
all 535 entries with zero disagreements, as does the 2026-08-11 D1 re-curation over its 534 included
entries; the current hashes are recorded above.

#### The occasion is a theme; the service is a slot

The dry run's first execution failed on `2022-11-13-remembrance`: the manifest said `other`, the
source frontmatter said `am`. The source was right and the rule was wrong.

Easter Sunday, Palm Sunday, Remembrance Sunday and Christmas morning are the **ordinary Sunday
morning service with an occasion attached**, not separate services. The corpus agrees wherever it
speaks — `2023-04-09-easter.md` says "Easter Sunday morning" and `2022-11-13-remembrance.md` simply
says `am`. `other` is now reserved for services genuinely outside the morning/evening slots: Good
Friday and Maundy Thursday fall on weekdays, and Carols by Candlelight is a distinct evening event.
The occasion is never lost; it survives in `title_override`, and in `service_label` for the `other`
cases. This moved `other` from 22 entries to 12.

The payload's own frontmatter now outranks every filename inference when it names `am` or `pm` — it
is the document telling us which service it was for, and it is what the dry run compares against.

#### The command now takes an approved plan — added 2026-08-06

`ImportOosArchiveCommand` reads the manifest, not markdown. It requires `--manifest=` (the corpus
has no default authority), defaults `--verbatim=`/`--formatted=` to the two corpus roots, and
iterates `OosCurationPlan::$includes` via a new `OosCurationEntryFactory`. `OosArchiveMarkdownParser`
and its test are **deleted**: it inferred date, service and completeness from heading text, and the
manifest now decides all three. That is the last filename heuristic gone from the Email path.

Four consequences a reviewer should not have to discover:

- **The `blocked` route is gone, and so is `unresolved_date`.** Both existed because the aggregate
  markdown could contradict itself about a date, leaving an entry nobody could act on.
  `validateIncludesForDryRun()` now runs in *every* mode, before any email is written, and a
  `strict` disagreement fails the whole run. A contradiction is loud and up front instead of a
  quiet per-entry report line. `OosArchiveEntry::$groundTruthDate` is consequently non-nullable,
  and `OosArchiveAssertionBundle::isBlocked()` is deleted.
- **`ordered_item_quality` is gone, because it could only have lied.** The manifest asserts item
  *counts*, never item *lines* — §13.4's truth set is where lines belong. `sequenceQuality([], …)`
  returns a score, not null, so every entry would have reported **0.0**: "the parse got every item
  wrong" where the truth is "nobody said what right was". In its place each plan reports
  `expected_item_count` / `item_count_matches`, both null unless a person asserted a count, and the
  aggregate carries `item_count_reconciliation`. Today that measures exactly one entry.
- **`--import` requires `--plan-hash`,** matching `ImportOpenLpDirectoryCommand`. The assertion
  bundle's `archive_artifact_hash` becomes `curation_plan_hash`, bound to `OosCurationPlan::$planHash`
  rather than a markdown file's digest — the §7.4 binding.
- **The DTO uses the manifest's vocabulary.** `heading`/`headingDate`/`correctedDate`/`flags` are
  gone; `labelQuality` (`full`/`unverified`) becomes `contentScope` (`full`/`partial`); a `curation`
  block carries the decisions into the report. Feeding a resolved date into a field named after the
  heading it used to be guessed from is the same defect as a heuristic count in `expected_item_count`.

Re-running against the manifest creates **new** synthetic emails: message ids now derive from
`item_key`, so rows from earlier aggregate-archive runs are orphaned rather than mutated. The
parser version is `archive-v7`, which invalidates every cached parse — correctly, since identity,
completeness and the input hash all have different provenance now.

#### One email, two services — the manifest names the document, it does not cap it

Added 2026-08-06, correcting a claim made earlier the same day. Wiring the command, the first
reading was that a manifest entry resolves to exactly one `resolved_service` and `reconcileRoot()`
forbids two entries claiming the same file, so an email carrying both that Sunday's orders could be
curated as only one of them — and that roughly nine evening orders were therefore out of reach.

**That was wrong, and the maintainer corrected it.** Two services in one email is the ordinary shape
of a Crockenhill Sunday, and the live pipeline has always handled it: `OosEmailParserService` returns
one `OosEmailServicePlan` per service, `InboundEmailImportService::import()` creates a
`ChurchService` for each, and
`OosMultiServiceImportTest::the_job_imports_both_morning_and_evening_orders` proves it end to end
today. Nothing was broken. What was wrong was the *archive* path, which filters parsed plans through
`onlyPlanKeys`: that filter was written against the old parser's multi-valued `servicesPresent`
(read from `####` sub-headings), and re-sourcing it from the manifest's single `resolved_service`
silently narrowed it from "one plan per curated service" to "at most one service per email".

The fix is to reuse the live behaviour rather than work around it. `importablePlanKeys()` — and the
assertion bundle's `eligiblePlanKeys()` — now gate on **the manifest's resolved date and non-empty
items, not its resolved service**. Every plan the parser finds on the curated date goes through the
same import the live path uses, and the live auto-import bar decides each one.

**The division of authority this settles is the same one §7.5 already draws for item counts.** The
manifest is authority over the *source's identity* — which document this is, and which date it
belongs to. How many services the document describes is *parse content*, produced by an LLM, and
gating on parse content is precisely what §7.5 refuses. The date gate stays, because a date is a
manifest decision and deterministic. `resolved_service` keeps its two real jobs: identity
reconciliation against the payload's own frontmatter (`strict` / `manifest-authoritative`), and
naming the service for §14's public archive.

Two report signals come out of it, replacing the "silent loss" flag the earlier reading called for:

- `service_beyond_manifest` (parse flag, counted in the aggregate's `parse_flag_counts`) — the email
  carries an order for a service beyond the one the manifest names it by. Not a loss; it imports.
  It is curation feedback that `resolved_service` describes only part of the document.
- `curated_service_not_parsed` (review reason) — the manifest says this is the morning service and
  the parse found no morning order at all. A genuine identity disagreement, so it holds the entry
  for a human.

The nine emails identified — `2018-12-09`, `2019-08-04`, `2019-08-18`, `2019-10-27`, `2022-12-04`,
`2023-01-22`, `2023-01-29`, `2026-02-15`, `2026-05-03`, counted as files carrying both a bare
`Morning service` and a bare `Evening service` heading, so a floor rather than a total — need no
schema change. They import both orders, exactly as they would have if received today.

**Remeasured 2026-08-07 for the historical 404-entry snapshot: the floor is roughly a quarter of the population.** Sweeping all 404 payload
files for a *heading-style* line naming a morning service and another naming an evening one — the
same criterion, widened only to accept `Sunday morning`/`Sunday evening` and `am`/`pm service`
spellings — finds **36 such files, 35 of which the manifest names by one service only**. All nine
above are in that set, and each has exactly one (morning) manifest entry. A looser scan that also
counts in-body service times reaches 76, so 35 is the defensible figure and 9 was low by about
four times.

This is a heading count, not a parse: the actual number of extra services is whatever
`OosEmailParserService` returns on the curated date with non-empty items, and that is LLM output.
The point is not the exact figure — it is that **there is no exact figure available in advance**,
which is what F1 below turns on.

#### Scope of PR21

The manifest **class, its schema, its validation and its reconciliation report**, plus extracting the
source-kind-agnostic half of `OpenLpCurationManifest` so a third near-copy is not created. Populating
the entries for all 261 files is curation that follows it and is likewise drive-free.

Out of scope: running `oos:import-archive --import` **against production**, which the status header
forbids until G8 and `HistoricImportProductionGuard` now refuses. Running it against a local
rehearsal database is not out of scope — it is §13.5 step 3, and it is the only way to stage Email
evidence at all.

**Corrected 2026-08-06.** This section previously said "dry-run and evaluation modes are read-only".
Only `--dry-run` is: it takes no database or extractor access. The three modes actually differ like
this, and it matters, because the middle one is where `expected_item_count` and `parse_decision` are
derived:

| Mode | Writes | Creates a service? | Reaches the review inbox? |
|---|---|---|---|
| `--dry-run` | Nothing | No | No |
| Evaluation (neither flag) | `InboundEmail` rows and parse caches, at `ArchiveEval` | No | No |
| `--import` | The above, plus canonical services and items | Yes | Yes |

Evaluation mode writes: `synchroniseEmail()` saves an `InboundEmail` and `parseResult()` stores the
parse and its `parser_version`. What makes it a staging activity rather than an import is narrower
than "read-only" — it creates no canonical service and `releaseToInbox()` returns early, so nothing
is handed to the operator. `ImportOosArchiveCommandTest::evaluation_mode_writes_evidence_but_creates_no_service_and_releases_nothing`
pins exactly that, so the distinction this plan relies on cannot drift.

### Tests and acceptance

Cover all six source-arrival permutations; source-only and complementary services; corrected
same-timestamp revisions; duplicate/divergent leaves; file replacement between phases; traversal,
symlink and out-of-root paths; and absence of raw bodies/local IDs/absolute paths in bundles.

Acceptance requires source assertions to remain logically identical regardless of canonical state,
identical re-import to be a no-op, and every corpus item to have approved identity/hash and a
reconciled include/exclude/duplicate report.

## 8. WP2 — Deterministic projection and automatic finalisation

**Purpose:** combine compatible incomplete sources without unnecessary human work.

### 8.1 Pure projection

Projection is a pure function of active source revisions, the latest active Manual revision (if
present) and a versioned policy fingerprint. Current canonical rows, arrival timestamps, queue
order and primary keys cannot affect it.

The **projection policy version** is a real artifact to be implemented here — `ChurchServiceProjector`
currently has no such constant. It covers matching tiers, cardinality, anchored order and field
authority: everything whose change alters a projection. It is deliberately **disjoint** from the
§13.3 processing fingerprint, which covers everything whose change alters durable media output.
Nothing may appear in both. That disjointness is what allows the §9.4 loop to advance the projector
repeatedly over a corpus processed exactly once.

### 8.2 Matching tiers

Apply deterministic tiers and stop before unsafe guesses:

1. exact stable identity: canonical song, normalized scripture or source-stable key;
2. compatible semantic type plus normalized title;
3. constrained title compatibility inside matching plan anchors;
4. timing/position compatibility within the same anchor window; and
5. unresolved proposal if multiple candidates or material conflict remain.

Never merge incompatible types merely because titles resemble each other. Record the tier and every
contributing assertion.

### 8.3 Cardinality and order

- Number repeated assertions within each source.
- Pair nth compatible occurrences monotonically.
- Preserve extra planned occurrences as planned-only and extra observed occurrences as
  observed-only.
- Keep repeated identical titles independently reviewable.
- Order observed items by timing.
- Insert planned-only items relative to nearest matched predecessor/successor plan anchors.
- Define deterministic behavior for one/no anchor, repeated anchors and Email/OpenLP order conflict.
- Do not use infinity in a global comparator for absent observed timing.

### 8.4 Field authority and conflicts

Define authority separately for title, type, scripture, song, timing and occurrence. Silence is not
disagreement. Require a proposal only for incompatible equal-authority values, multiple credible
matches, unsafe observed classification, unresolved song/scripture identity, or a required
speaker/preacher decision.

### 8.5 Automatic finalisation

A service with zero unresolved proposals and a complete projection audit enters an explicit
machine-final state. It needs no synthetic Manual revision or review click and can export Bundle B
with evidence/policy/result hashes. A later conflicting source reopens it. A genuine Manual
revision remains final authority until explicitly superseded by Manual.

The proportion of the corpus reaching this state without a human is the primary quality measure of
the matching tiers in §8.2, and the §9.4 census is how it is observed. A proposal class large enough
to appear in that census is first treated as a defect in these tiers, not as work for the operator.
Tier changes are the preferred remedy over corpus-specific rules, because they remove the proposal
for every future service as well as every historic one.

### Tests and acceptance

Cover arrival/queue permutations, A–B–C/A–C anchors, repeated items, compatible null/non-null
fields, equal-authority conflicts, different PKs, Manual freeze and automatic Bundle B export.

Add a structural test that the two versioned quantities stay disjoint: changing the projection
policy must not change any service's `processing_fingerprint`, and re-projection must complete
without dispatching a job, opening a media file or calling a transcription or analysis provider.
This is the regression guard for §9.4's no-reprocessing property — the loop's affordability depends
on it, so it must fail loudly if a future change couples the projector to media.

Acceptance requires one canonical hash across permutations, zero human decisions for compatible
evidence, one visible proposal per real ambiguity and a portable explanation manifest.

## 9. WP3 — Exceptional human review and Bundle B

**Purpose:** make rare human intervention sufficient, auditable and impossible to hide.

### 9.1 Review-state correctness

- Pending proposals atomically set service attention state.
- Inbox counts include normalized evidence proposals, not only legacy flags.
- Attention clears only when all active proposals and publication-blocking decisions are resolved.
- Only proposal IDs carrying an explicit submitted disposition may change status; a proposal absent
  from the submission remains `Pending` with null resolver/time and is never default-accepted or
  default-rejected.
- The workbench initializes proposal disposition as unresolved, not `accepted`. Selecting a proposal
  requires an affirmative accept/reject/replace choice. A selected proposal without that choice
  fails validation and rolls back the complete review transaction.
- A partial session may save explicit decisions and a non-final Manual revision, but cannot be
  marked complete, freeze the service, or export Bundle B. Finalisation requires an exhaustive
  disposition for the active proposal set under the service lock.
- Superseded proposals remain auditable but do not count as active.
- Proposal disposition and final canonical choice are separate explicit facts.
- **Explicit does not mean individually clicked.** The B13 defect is that a disposition was *inferred
  from omission*. A disposition authored once and applied to an enumerated set of proposal IDs is
  still explicit, still attributable, still reversible and still exhaustive. §9.4 depends on this
  distinction; nothing in it weakens the rule above.

### 9.2 Admin workbench

Extend the existing class-based admin Livewire workbench, not a parallel screen. Support portable
assertion selection (including duplicate titles), include/exclude with rationale, add/remove/reorder,
title/type/section type, song/scripture identity, occurrence resolution, proposal
accept/reject/replace, and explicitly authorised service-field corrections.

Every mutation calls `authorizeAdmin()`. Validation proves each assertion, song and proposal belongs
to the locked service and active revision set. Forged Livewire requests cannot make cross-service
provenance.

Reuse existing admin shells/cards/controls/buttons and include loading, error, success, mobile,
keyboard and focus states.

### 9.3 Complete decisions and Bundle B

Review sessions record included and excluded assertions, final position, evidence, custom values,
song/scripture/occurrence decisions, proposal disposition and rationale. Do not infer negative
or positive decisions from absence.

Bundle B supports:

- `automatic`: active evidence set, projector/policy fingerprint, pre/result hash and manifest;
- `manual`: the same plus Manual revision, reviewer hash, all decisions and all proposal
  dispositions by portable identity.

Production resolves reviewer by approved email hash and assertions/proposals against active
imported evidence. Missing, extra or differently resolved proposals fail closed.

### 9.4 Review load is designed down, not budgeted

The rest of WP3 is correct about integrity and, on its own, wrong about scale. §9.1's exhaustive
per-proposal disposition is delivered through the existing per-service workbench
([`ShowChurchService.php`](../../app/Livewire/Admin/ChurchServices/ShowChurchService.php)): one
screen, one lock and one submit per service, with resolutions keyed by individual proposal ID. For
the current era — roughly one service a week — that is invisible and right. Pointed at a corpus of
several hundred services it costs O(services x proposals per service) page loads and submits, and
becomes the schedule. The B13 fix did not change the per-service cost; it changed the corpus the
workbench must survive. No cross-service proposal surface exists in
`app/Livewire/Admin/ChurchServices/`, and none of the predecessor plans added one.

#### 9.4.1 The stopping rule

There is deliberately **no operator review-hour budget**. A budget treats review as a fixed cost to
absorb and rewards grinding through it. The rule instead is:

> Run the corpus to its first review point, stop, and establish whether that **class** of decision
> can be automated. Only decisions proven irreducible are made by hand.

Every review point is a bug report against the projector until proven otherwise — **or, added
2026-08-14, a corroboration that was available and never consulted.** Cross-source agreement is an
automation class in exactly the sense this section means: it removes review load by construction
rather than by budgeting for it, and it costs no media work and no model calls. On the 2023–2026 era
it is near-total (188 of 195 Email identities corroborated), and across the Email corpus it reaches
72%. A review point on an identity that two independent sources already agree on is a defect in the
census, not work for an operator. Before any class is declared irreducible, check whether §13.4's
corroboration table covers it.

This is affordable
because §8.1 makes projection a pure function of active source revisions and a versioned policy
fingerprint: re-projecting the whole corpus after a matcher change is cheap, deterministic and
side-effect free.

**No iteration of this loop reprocesses media.** That is a load-bearing property, so it is stated
mechanically rather than assumed:

| Change | Redone by | Touches Whisper/ffmpeg? |
|---|---|---|
| Matching tiers, cardinality, anchored order, field authority | Re-projection from stored source revisions | No |
| How a completed run becomes Livestream assertions | `LivestreamSourceAdapter::adapt()` against the persisted `MediaProcessingLog` | No |
| Transcription model, analysis prompt, section detection, ffmpeg parameters | Reprocessing the affected recordings (§13.6 row 5) | **Yes** |

The middle row is the one worth noting: even a change to how observed items are derived from a
recording is replayed against rows already in the database, because
[`LivestreamSourceAdapter`](../../app/Services/ChurchService/SourceAdapters/LivestreamSourceAdapter.php)
adapts a completed processing log and performs no media work. Only a change that would make the
pipeline emit *different durable output* costs a reprocess, and §13.3 scopes the processing
fingerprint so that exactly those changes — and no others — trigger one.

#### 9.4.2 Proposals are cheap to surface, so surface them first

Proposals arise from **projection**, not from media processing. Email and OpenLP evidence staging
requires no Whisper, no LLM analysis and no video. The entire loop below therefore runs to
convergence **before** the bulk media pass of §13 ever starts, which takes human review off the
critical path instead of serialising it against 9–18 days of wall clock.

Two proposal populations exist and are triaged in this order:

1. **Email x OpenLP** — available as soon as WP1 staging works, at zero media cost. Converge here
   first.
2. **Livestream-involving** — needs the media pass. Re-run the loop after each §13.4 checkpoint as
   Livestream assertions arrive.

#### 9.4.3 Corpus proposal census

Produce a census over the whole staged corpus, not per service. Each row is a proposal **class**,
keyed by the §8.2 matching tier that hesitated plus the normalized subject of the disagreement.
Record class key, tier, occurrence count, distinct services affected, a representative sample and
the candidate resolutions. The projector already records the tier and every contributing assertion,
so the class key falls out of data the matcher must produce anyway.

The census is the working document of the loop:

1. Project the whole corpus.
2. Take the largest remaining class.
3. Decide: automatable, or irreducible?
4. If automatable, improve the §8.2 tiers or add an approved rule; re-project; re-census.
5. If irreducible, record it as such with a reason, and resolve it as in §9.4.5.
6. Repeat.

A class that shrinks the census by hundreds of proposals for one matcher change is the expected
shape of the early iterations. Casing and punctuation variants of the same song title, and the same
scripture reference in two notations, are the archetypes.

#### 9.4.4 Cross-service proposal queue

Add a cross-service review surface grouped by class, alongside — not replacing — the per-service
workbench, which remains correct for weekly operation and for inspecting one service in full.

The queue shows each class with its occurrence count and affected services, an inline sample of
representative proposals with their evidence, and the available dispositions. `Be Thou My Vision`
versus `Be thou my vision` across 31 services is **one** human judgement, not 47.

Reuse the existing admin shell, cards and controls per §9.2, and carry the same loading, error,
success, mobile, keyboard and focus states.

#### 9.4.5 Rule-level dispositions

A disposition may be authored once against a class and applied to an enumerated set of proposal IDs:

> accept tier-2 title match where normalized titles are equal and semantic types agree — applied to
> proposal IDs [...], by reviewer, at time, rationale "casing variant"

This satisfies §9.1 exactly. The IDs are enumerated and submitted, not omitted; the disposition is
attributable, reversible and exhaustive. What is shared across the set is the *authoring act and its
rationale*, not the decision's explicitness.

Bundle B gains a `decision_rule` reference so that many dispositions carry one rationale and one
authorising act. Its per-proposal disposition list by portable identity (§9.3) is unchanged in shape
and remains the unit of verification: production still checks every proposal individually and still
fails closed on missing, extra or differently resolved proposals.

An automatable class should usually become a **matcher improvement** rather than a rule disposition,
because a matcher improvement removes the proposal for every future service too. Prefer a §8.2 tier
change; use a rule disposition when the judgement is genuinely corpus-specific.

#### 9.4.6 Gate

The loop's stopping condition, required at G2:

- The census covers the corpus it claims to: the approved manifest's service count is recorded, every
  one of those services is staged, and every staged service carries a projection at the current policy
  version. An empty or near-empty census is otherwise indistinguishable from a corpus nothing has been
  staged for, and would clear this gate without a census having run.
- Every class in the census is marked `automated` or `irreducible`, with a reason.
- No class marked `irreducible` has a candidate resolution that a §8.2 tier change would settle.
- The residual hand-review set is enumerated, with its per-decision time measured rather than
  assumed — a song-identity proposal is seconds, a genuine order conflict is minutes.

This mirrors §13.1's corpus rule. Review load is not "small enough" at an informal percentage; every
class is accounted for, even when some are deliberately left to a human.

Measure per **decision**, not per service. Instrument the loop so the residual figure is observed
from the census rather than estimated, and so a later matcher regression shows up as the census
growing.

##### F1 — the approved manifest does not determine a service count (2026-08-07; old baseline superseded 2026-08-09)

The figures and reasoning in this subsection describe the unchanged 2026-08-06/07 snapshot only.
After the expanded Email roots are re-curated, the replacement manifest's approved identity set
becomes the baseline; do not carry the old 391-identity number forward.

The first bullet says "the approved manifest's service count is recorded". The Email manifest has no
such number, and cannot have one.

`OosCurationManifest` yields **391 distinct `(resolved_date, resolved_service)` identities across 404
entries**. But `ImportOosArchiveCommand::importablePlanKeys()` gates on the manifest's *date* and
deliberately not its *service* — that is the division of authority §7.5 settles, and
`ImportOosArchiveCommandTest` pins its consequence with
`assertDatabaseCount('church_services', 2)` for a single entry. The historical §7.5 measurement put
the number of entries that would produce a second service at **up to 35**, and the true figure is
parse content that only exists after the extractor has run.

So `expected_services` as currently specified is unsatisfiable in both directions: 391 fails the gate
on `STAGED_ABOVE_EXPECTED`, and the number that would pass cannot be known before the staging run it
is meant to gate. `OosCurationPlan` has no service-count accessor, so no code derives it either, and
adding the discovered services as their own manifest entries is blocked — `reconcileRoot()` rejects
two entries claiming the same source file, exactly as §7.5 says.

**This needs a maintainer decision recorded in §19, not a value.** The two coherent shapes are:

- **Reconciliation rule.** `expected_services` stays the replacement manifest's approved identity
  set (391 was the old snapshot baseline), and the gate
  accepts `staged >= expected` provided every excess service is accounted for by a
  `service_beyond_manifest` flag on the entry that produced it. The unexplained-excess case keeps
  failing closed. This preserves the "100% accounted for" property §13.1 insists on, and moves it
  from an integer to a reconciliation — which is what the Email side has always actually been.
- **Post-staging observation.** `expected_services` is recorded from a completed staging run and the
  gate reconciles later runs against it. Simpler, but it makes the first run ungated, so the property
  is only enforced from the second run onwards.

The first is preferred, because it keeps the gate meaningful on the run that matters. Either way the
census gate's `STAGED_ABOVE_EXPECTED` blocker needs to learn the difference between a service the
corpus explains and one it does not.

##### F3 — the gate cannot tell which sources are staged (2026-08-07)

`ChurchServiceCorpusCompleteness::stagedServices()` is
`ChurchServiceSourceRecord::query()->distinct()->count('church_service_id')`. It counts services
carrying *any* source record and never asks which kind.

§9.4.2 names **Email × OpenLP** as the population to converge first. But set `expected_services` from
the Email manifest, stage Email alone — which is exactly what §13.5 steps 3–4 do while the drive is
unmounted — and `staged == expected` with an empty census, so **G2 passes over a corpus holding zero
OpenLP evidence**. That is the same defect the 2026-08-05 fix closed one level down: an absence that
is indistinguishable from a clean result.

The evidence therefore has to carry per-source coverage, and the gate has to require the source kinds
the census claims to have converged.

**Closed 2026-08-07 by PR25.** `ChurchServiceCorpusCompleteness::evidence()` now reports
`staged_services_by_source` — distinct services per source kind — alongside `declared_source_kinds`
and `unstaged_source_kinds`. `church.historic_corpus.census_source_kinds` declares what the census
covers, and the gate adds two blockers: `census_source_kinds_undeclared` when nothing is declared,
and `declared_source_kind_unstaged` when a declared kind has no staged services at all.

Three decisions a reviewer should not have to reconstruct:

- **Unset is undeclared, not "all kinds" and not "no requirement".** Same principle as an unset
  corpus size: the absence of a decision is not a decision.
- **An unrecognised kind reads as undeclared rather than being dropped.** A typo that quietly
  narrowed the requirement would defeat the check while appearing to satisfy it.
- **The per-kind counts deliberately do not sum to `staged_services`.** A service evidenced by both
  Email and OpenLP is one staged service and appears under both kinds. The total answers "how much of
  the corpus is evidenced at all"; the breakdown answers "by what".

The counts are reported by `services:proposal-census` and on the cross-service review queue, so a
reviewer reading "391 staged, 391 projected" can also see that none of it is OpenLP.

### Tests and acceptance

Cover automatic export/import, accept/reject/replace, exclusions, duplicate-title selection,
forged IDs, concurrent/stale review, omitted-proposal pending state/resolver, partial review
attention/non-final state, different-PK round trip, and Dusk keyboard/loading/validation/completion
behavior.

For §9.4 additionally cover: census class keys are stable across re-projection; a matcher
improvement provably shrinks the census and never silently resolves a proposal it did not match; a
rule disposition applies to exactly its enumerated IDs and leaves every other proposal `Pending`; a
proposal added to a class *after* a rule was authored is not retroactively dispositioned; rule
dispositions round-trip through Bundle B with per-proposal verification intact; and the cross-service
queue authorizes, locks and validates cross-service provenance to the same standard as §9.2.

Acceptance requires inbox/query parity, resolution of every modeled ambiguity without database
edits, no human click for automatic cases, identical local/production finalisation/proposals, and a
census in which every class is marked `automated` or `irreducible` with a reason.

## 10. WP4 — Portable Bundle A and streaming assets

**Purpose:** transport the complete durable result without local deployment identity.

### 10.1 Versioned logical graph

Build a versioned schema from WP0's allowlist, separating domain content, portable relationships,
asset content/roles and destination paths. Logical hashes exclude local paths, database IDs and
production import metadata. Normalize production back to the portable form before comparison.

Use stable keys: processing UUID/content hash; segment index; versioned section occurrence key;
canonical song key; preacher slug/name; publication key from run/section/content type; and artifact
kind/SHA. Reject ID/path-shaped identity fields not explicitly allowed.

### 10.2 Complete durable content

Classify run AI/structure/degradation/timing state; all processing steps; segment metadata; section
metadata, expected/matched relationships, extraction/publication timestamps and review/song state;
sermon/talk source, preacher review, scripture, quality, visibility and thumbnail state;
song-video state; service artifacts; and every remappable asset role.

Unknown metadata containing path/ID-shaped keys fails closed.

### 10.3 Multi-role asset manifest

Represent each physical content object once by content identity with a non-empty list of logical
roles. Never drop roles when run, section, publication and song video share a path. Each entry has a
relative source path, size, SHA-256, media kind and roles. Destination allocation resolves every
role explicitly.

### 10.4 Streaming

- Incrementally hash streams.
- Copy through Flysystem streams or a safe driver-level copy.
- Never use `Storage::get()` for historic media.
- Verify size/hash at export, staging and immediately before destination commit.
- Close streams on all exception paths.
- Use `cursor()`, `lazyById()` or `chunkById()` for large DB inventories.

### Tests and acceptance

Round-trip WP0 into a different-PK database; test multi-role shared media, path-independent hash,
unknown fields, corruption at every boundary, whole-read prohibition and a defined memory ceiling.

Acceptance requires complete WP0 representation, no IDs/paths in portable identity, every role
resolved and identical logical hashes across different paths/PKs.

## 11. WP5 — Constraint-safe persistence and richness convergence

**Purpose:** satisfy real database constraints and preserve richer production records.

### 11.1 Persistence order

Within the caller-owned transaction and consistent lock order:

1. classify/lock natural identities;
2. create/validate the processing log without publication foreign keys;
3. create steps and segments;
4. create sections in a check-safe pre-publication state;
5. allocate create-only destinations;
6. copy/verify assets and record objects created by this attempt;
7. create or richness-converge publications/song videos;
8. set extraction paths/timestamps before publication status;
9. link run/publication/section/item/song-video relationships;
10. apply production-local import metadata; and
11. rebuild/compare the normalized portable graph.

On failure, roll back DB changes and compensate only objects created by this attempt. Never delete a
pre-existing matching object.

### 11.2 Existing-record convergence

Define publication natural identity from date, service, content type, source media and/or an
approved legacy mapping. Classify `already_present`, `safe_enrichment`, `blocked_difference`,
`conflict` or `create` using the shared vocabulary in §4.1.
Never create blindly by slug. Preserve URLs and richer compatible metadata; conflicting non-null
values appear in preflight and fail closed.

Match song videos by canonical song, portable section/publication and asset hash. Preserve featured
choices unless the bundle explicitly carries a reviewed replacement. Verify public usage
eligibility.

### 11.3 Live already-present verification

Rebuild the current logical graph and verify every destination asset and canonical link. Cached
historic metadata may aid reporting but cannot decide correctness.

### Tests and acceptance

Exercise MySQL constraints with sermon/talk/song; legacy publication exact/enrichment/conflict;
slug collision; rollback compensation; pre-existing same/different objects; concurrency; damaged
previous import; and exact second no-op.

Acceptance requires successful realistic persistence, no silent duplicate, detection of damage and
no committed DB/untracked asset residue on failure.

## 12. WP6 — Binding preflight, atomic convergence and exact closeout

**Purpose:** ensure the approved dry run is exactly what apply executes.

### 12.1 Real no-write prepare

The final dry run calls the actual preparation path for both bundles and every service. Its token
binds bundle/schema/batch/fingerprints; service identities/indexes; evidence/pre/result hashes;
current target classification/row revision; reviewer/proposal/assertion remapping; staged asset
path/size/hash; allocated destinations and current object classifications; resolved storage
driver/bucket/root/prefix; and deploy identifier/operation ID/expiry.

The whole batch must classify without writes before the first service applies.

### 12.2 Apply and transaction

Apply verifies token, rehashes every input/asset, locks identities, re-runs classification and
compares the complete current plan with the approved plan before writing. One orchestrator owns the
per-service transaction across Bundle A, Livestream evidence, projection, Bundle B final revision,
links and equality gates. Production mode asserts zero jobs, events, providers, mail and
notifications; after-commit behavior remains disabled for this operation.

Use one complete batch preflight plus an atomic transaction per service, a private append-only
ledger, stop-on-first-hard-failure and a resume flow that re-preflights remaining and revalidates
completed services.

### 12.3 Exact auditor

Compare local/production service identity/hash, active evidence set, finalisation/proposals,
ordered occurrences/provenance, songs/scripture/type/title/timing, processing relationships,
publications/song videos, assets and public song-usage membership. Aggregate-equal/item-different
must fail. Bundle A/B audit is one closeout operation.

### 12.4 Current-era re-projection

The defects repaired by WP1 and WP2 are not historic-only. `IngestChurchServiceSourceRevision` and
`ChurchServiceProjector` are on the **live weekly path** today, reached from
[`ProcessInboundOosEmail`](../../app/Jobs/ProcessInboundOosEmail.php) via
[`InboundEmailImportService.php:451`](../../app/Services/Email/InboundEmailImportService.php#L451),
from [`ImportChurchServiceFromOpenLp.php:99`](../../app/Services/ChurchService/ImportChurchServiceFromOpenLp.php#L99),
and from [`SaveChurchServiceFromAdmin`](../../app/Actions/SaveChurchServiceFromAdmin.php). Every
current-era service projected before the repair therefore carries the same defects the historic
import exists to avoid: B3 cross-source attribution, B5 lexical-hash revision selection, B6 order
and B13 dispositions recorded as human decisions that no human made.

`ChurchServiceConvergenceBackfillService` already exists and no predecessor plan ever scheduled it.
Re-projection of the existing corpus is in scope here, on the same terms as the historic import:

- Re-project every existing service once WP1/WP2 land, under the same pure-projection contract.
- Diff pre- and post-repair canonical results and classify every change with the §4.1 vocabulary.
  Aggregate-equal/item-different fails, exactly as in §12.3.
- **Reverse B13's false acceptances rather than inheriting them.** A disposition attributed to a
  resolver who never made it is not evidence; those proposals return to `Pending` with null
  resolver/time, and the affected services return to the attention inbox.
- Reopened proposals join the §9.4 census and are triaged by the same automate-first loop. Expect
  this to be where the loop earns most of its value, since these classes recur weekly.
- Audit the result to the standard of §12.3 and include it in the §13.7 private reports.

This is production mutation and is governed by the same gates; it is not a separate release train.
It was considered as one and rejected on 2026-08-02, because the historic corpus is a far stronger
validation set for the repaired projector than waiting for live services to arrive one week at a
time.

#### Local dry run, 2026-08-06 — re-projection has almost nothing to re-project

`service-tracking:reproject-current-era` was run locally, drive-free, against 408 services. It
completed with all four report gates true, and its summary is the finding:

| Classification | Services |
|---|---|
| `conflict` — "The service has no normalized source evidence to re-project." | 407 |
| `already_present` — the current canonical result already matches the repaired projector | 1 |

`b13_proposals_reopened` was **0**, and `services_with_item_differences` was **0**. The whole local
database holds exactly **one** `church_service_source_records` row, against 408 services and 2,743
items; 400 of those services carry `source = openlp` from archive-import runs that predate WP1's
evidence ingestion.

**The command is right and the assumption above may not be.** §8.1 makes projection a pure function
of active source revisions, so a service with no normalized evidence cannot be re-projected at all —
only refused, which is what happened, and nothing would have mutated without `--apply`. But the
fourth bullet above predicts re-projection is "where the loop earns most of its value". If a service
never went through evidence ingestion, it has no projection to repair, no proposal to reopen and no
B13 disposition to reverse. It has a different and larger problem: no evidence, so its canonical
result can never be re-derived, audited or converged from sources.

**Production result and maintainer decision, 2026-08-09 — back-fill before re-projection.** The approval-gated workflow still
needs its `PROD_*` credentials, but the operator ran the same read-only command directly on the
production host. It reported 3 services, 0 services with any or non-Manual source record, 32
canonical items on unevidenced services, and 0 proposals or resolvers. The current-era corpus is
therefore entirely unevidenced and `service-tracking:reproject-current-era` has nothing it can
legitimately re-project **yet**. **Decision:** back-fill retained evidence for all three services;
do not exclude or accept them as legacy. Recover each service's authoritative source material and
ingest it through the normal source-revision path, with its provenance and hash, rather than
manufacturing a record from the existing canonical items. Once all three have active retained source
revisions, run the re-projection and item-level audit over that three-service corpus. The manual
invocation used `--details`; its service IDs are intentionally not recorded here.

The production measurement resolves the uncertainty noted by the local dry run. Its two possible
outcomes were:

- **Production is mostly evidenced.** §12.4 stands as written.
- **Production resembles local.** **Observed 2026-08-09.** §12.4 is a no-op until retained evidence
  exists, and the services are outside the §2 success criteria because criterion 1 presumes every
  result has source revisions describing it. **Decided 2026-08-09:** back-fill all three from their
  recovered authoritative sources, then re-project and audit; neither exclusion nor legacy
  acceptance is permitted.

### Tests and acceptance

Invalidate tokens on DB/reviewer/source/bundle/asset/storage changes; prove no-write dry run and
zero-dispatch apply; test locks, failure after each phase, resume, item-level audit and full no-op
rerun. Cover current-era re-projection: unchanged services stay exactly equal, B13-affected
proposals reopen with null resolver, and the corpus diff is item-level.

Acceptance requires apply to execute only the approved state, per-service atomicity with asset
compensation, a privacy-safe explanatory ledger and green exact/no-op closeout.

## 13. WP7 — Isolated local acquisition and full rehearsal

**Purpose:** run current processing once locally under an approved reproducible environment.

### 13.1 Corpus availability and completion gate

**The drive is a hard dependency for two of the three sources, not all three.** Clarified
2026-08-06: OpenLP archives and historic video live on the external drive; the **Email corpus does
not**. `storage/scratch/oos/` and `storage/scratch/oos-verbatim/` are on local disk, so Email
inventory, hashing, manifest curation and — once §7.5's manifest exists — the whole Email half of
rehearsal step 2 proceed while the drive is unmounted. §13.5's ordering already wants this: steps 3
and 4 are marked "No media required", and the Email population is the one that reaches them first.

The authoritative external drive is a hard dependency for the OpenLP and video corpora, not an
assumed prerequisite. Previous operator feedback indicates some OpenLP paths/symlinks may resolve
only while that drive is mounted; the tracked repository does not establish a reliable broken-link
count. Remeasure it against the mounted, read-only source before approving the manifest.

#### Video is the only possible corroboration for 2019–2022 — added 2026-08-14

§13.4's era table establishes that 136 of the 146 uncorroborated Email identities fall in 2019–2022:
the hymn workbooks have no sheet for those years and OpenLP does not start until 2021. That window
is also, exactly, the livestream era.

This changes what the **video curation manifest** is for. It is currently tracked as a missing
producer — a validator with no builder, noted at step 5 of the final-readiness plan's acquisition
sequence (F66) — which frames it as tooling tidy-up. It is better understood as **the only source
that could ever corroborate the least-evidenced four years of the archive**, and therefore as an
input to the §13.4 publication decision for that era rather than only to acquisition custody.

**One measurement decides whether the builder is worth writing.** It was originally framed as a
production-database question — does production hold usable 2019–2022 video? — because the drive was
unmounted when this subsection was written. That framing was a proxy. The corroborating video is an
*acquisition* corpus: it lives on the external drive, and production holds at most whatever subset
was already imported. With the drive mounted the real measurement is available, and it is the one
that governs.

##### Measured 2026-08-14 against the mounted drive

Source: `Services/` on the Sonnics drive (353 dated folders, 347 with `Morning/`, 181 with
`Evening/`), crossed per identity against the Email plan keys in
`storage/scratch/archive-v12-restage-20260814.json`. Coverage counts a `Morning/`/`Evening/`
directory present for that identity's date and service.

| Year | Morning covered | Evening covered | Total |
|---|---:|---:|---:|
| 2019 | 0 / 24 | 0 / 14 | **0 / 38** |
| 2020 | 42 / 52 | 0 / 7 | 42 / 59 |
| 2021 | 50 / 50 | 1 / 3 | 51 / 53 |
| 2022 | 46 / 47 | 20 / 23 | 66 / 70 |
| | | | **159 / 220** |

**Coverage is substantial, so the producer is worth building — but 2019–2022 is not one era for
video purposes.** Video begins at 2020-03-22, the first lockdown Sunday; the window splits there and
each half takes a different decision:

- **2019-01-01 → 2020-03-21: zero video.** No sheet in the hymn workbooks, no OpenLP, no recording.
  This sub-era has *no* corroborating source and is **hand-verified-or-unpublished** regardless of
  what gets built. Size its manual truth set deliberately; do not carry it into the per-era accuracy
  figures as though it were measurable.
- **2020-03-22 → 2022-12-31: corroborable on the morning side**, weakly on the evening side until
  2022 (0/7, then 1/3, then 20/23).

Two qualifications constrain the builder:

- **Presence is not corroboration.** Email plans are orders of service — hymn and song lists. Much of
  2020 is sermon-only (`Sunday 5 April_ Sermon`, 22 min); the drive's own
  `morning_service_recording_status.csv` grades only 29 of the 42 covered 2020 morning dates as
  `single_full_recording`. A sermon clip corroborates preacher and date and nothing about song
  membership, which is the dimension §13.4 asks the census to record. The manifest must therefore
  carry a **graded** corroboration field, not a presence boolean.
- **Evening has never been inventoried.** `morning_service_recording_status.csv` is morning-only
  despite 181 `Evening/` folders existing. A producer that reuses that CSV would silently
  under-report exactly the identities that are least corroborated elsewhere.

**Denominator discrepancy, unresolved.** §13.4's era table states 193 Email ids and 136
uncorroborated for 2019–2022, against 521 identities overall. Extracting plan keys directly from the
same archive-v12 report yields 220 and 662. The era table was presumably computed over a filtered
subset (approved-and-dated identities under the curation authority). The *shape* above is unaffected
— the 2020-03-22 boundary and the evening gap hold under either denominator — but the two counts must
be reconciled before these figures enter the publication decision.

**Scope decided 2026-08-14: the manifest's raw root is `Services/` only.** `CovidServiceArchive/`
(30 flat folders, no service split), `_Duplicates/` (172 files) and `_Rejected/` (392 files, both
quarantined by the operator's 2026-07-30/31 reorg with move logs) stay outside the root as
already-adjudicated. Measured cost of that exclusion: **one** identity, `2020-12-20 evening`, is the
only uncovered identity for which a `CovidServiceArchive/` folder exists.

##### `_Rejected/` is not a duplicates bin — corrected 2026-08-14

The exclusion cost above was measured on **folder presence**, and on completeness it is understated.
Drafting the worksheet and comparing its grades against
`morning_service_recording_status.csv` showed 290 of 347 morning grades agreeing and **55
disagreeing in one direction**: the CSV records those dates as `fragmented_only` across 2–5 files,
while `Services/` now holds exactly one. `_Rejected/` mirrors the `Services/` tree, and its
2026-07-31 move log shows the pass kept the largest file per service and rejected the rest.

Those rejected files are **service segments, not duplicates**. A service captured by OBS as
`10-31`, `10-42`, `10-47`, `11-05`, `11-44` is one service in five parts; keeping only `11-05` keeps
one part. Across the 55, the kept file runs 22.9–39.2 minutes (median 29.4) and **49 lose more than
ten minutes of recorded service time** — 2023-11-19 keeps 36.6 of 80.9 minutes.

The scoping decision nonetheless stands for the purpose that drove it. Only **5** of the 55 fall in
the 2019–2022 corroboration window, and only **2** (2020-05-17, 2021-06-06) would reach the
full-service grade if their fragments were restored. The remaining 46 sit in 2023–2026, the era that
already has Email, OpenLP and hymn corroboration and needs video least.

What must not happen is a later reader treating `_Rejected/` as junk. If whole-service video is ever
wanted for 2023–2026 — for transcript quality rather than corroboration — those fragments are the
missing material and the concatenation decisions for them have not been made.

##### The producer, built 2026-08-14 (closes F66's video half)

Curation runs in two stages, because hashing and adjudication have opposite iteration costs:

1. `historic-import:draft-video-curation` — enumerates the corpus into a worksheet from paths, sizes
   and container headers. **No file contents are read**, so a full pass over the corpus takes about a
   minute. Every entry is proposed as an include with empty editorial facts; the operator adjudicates
   the worksheet by hand.
2. `historic-import:capture-video-curation` — hashes every declared file once, at freeze, and emits
   the manifest, which it then validates so a bad capture fails here rather than at import. It
   refuses a corpus that moved since drafting (missing file, or a byte size that no longer matches),
   because decisions adjudicated against one file must not be frozen against another.

Splitting them matters at this scale: the corpus reads at **87.7 MB/s** and runs to **1.0 TB**, so a
hashing pass is upwards of three hours. Folding it into the draft would have made every re-draft cost
a working afternoon *and* overwritten the adjudication it took that afternoon to produce. Note that
`HistoricVideoCurationManifest::verifiedPath()` re-hashes on **every** `plan()` call, so that
three-hour read recurs at each validation, import and rehearsal — worth costing into the rehearsal
schedule independently of this work.

First real run, 2026-08-14: **462 service identities from 506 recordings** (347 morning, 115
evening) — 278 full, 172 short partial, 12 fragmented, 0 unknown.

**A tenth of the corpus needed a second duration method.** WebM files pulled down as YouTube backups
carry no duration in either the format or the stream header, so an ordinary probe returns nothing and
would have graded 35 full-length services "unknown", silently dropping them from corroboration.
Counting packets and dividing by frame rate recovers the real length and reproduces the operator's
hand-measured durations exactly (2020-09-06: 98,100 packets at 30 fps = 54.5 minutes, against 54.5 in
the CSV). It costs about a second per file.

The 40-minute full/partial boundary is not invented: across the 286 dates the operator hand-labelled,
the shortest `single_full_recording` is 41.4 minutes and the longest `short_partial` is 39.8.

This is a corpus-availability fact and belongs in the completion gate: an era whose only
corroborating source has not been inventoried is not an era whose accuracy can be certified.

The tracked OpenLP accounting is 536 archive entries, 105 byte-identical nested duplicates, 431
unique sources and 428 curated inclusions
([remainder plan](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md#L314)).
Those counts are a reconciliation target, not proof that every current path/symlink resolves.

#### F4 — the code freezes the accounting this paragraph says to remeasure (2026-08-07)

`OpenLpCurationManifest` carries those figures as a **private class constant** and throws on anything
else:

```php
private const ExpectedCounts = ['raw' => 536, 'include' => 428, 'duplicate-of' => 105,
                               'exclude' => 3, 'aliases' => 7];
...
if ($counts !== self::ExpectedCounts) { throw new RuntimeException('...accounting mismatch...'); }
```

The paragraph above instructs a remeasurement against the mounted drive and says plainly that the
tracked counts are not proof. The code makes any other answer unrepresentable. Since what is staged
locally is 431 broken symlinks — already de-duplicated, so the 105 nested duplicates exist only in the
untouched drive directory — a remeasurement returning something other than 536 is the *expected*
outcome, not the exceptional one. As it stands, drive day would begin with a code edit to a constant
in order to record what the drive actually holds, which inverts the authority §7.3 assigns: the
approved manifest is mutation authority, not a class constant it must agree with.

The Email manifest has no equivalent — `OosCurationManifest` derives its counts from its entries and
binds them into the manifest and plan hashes, which is where an approved number belongs. The OpenLP
manifest should do the same: declare the accounting in the manifest envelope, hash-cover it, and
verify the inventory against the declaration rather than against the class.

Two properties must survive the change, because they are the reason the constant was added:
`discovered = included + excluded` still has to hold, and the raw directory still has to match the
manifest exactly — no extras, none missing. What moves is only *where the expected numbers come
from*.

**Closed 2026-08-07 by PR27.** The manifest is now version 3 and must carry `expected_counts` in its
envelope; `ExpectedCounts` is deleted. The declaration is validated strictly — every key present, no
key extra, every value a non-negative integer — and then checked against the entries, so a manifest
that has silently lost entries between approval and apply still fails. That was the constant's real
job and it is kept; what is dropped is only the assumption that one accounting is correct for all
time. A v2 manifest declares nothing, so it is rejected rather than defaulted to 536/428/105/3/7, on
the same principle the class already applied to v1.

Drive day therefore no longer begins with a code edit: the remeasured inventory is recorded in the
approved manifest, which is where §7.3 says mutation authority lives.

Note also that `inventory()` calls `getRealPath()` and rejects anything resolving outside the root,
so the corpus must be **materialised as real files** on drive day; a directory of valid symlinks will
still be refused.

The current Email reconciliation target is in §7.5 and is measurable locally: 533 verbatim files,
261 formatted, **259 paired, 274 verbatim-only and 2 formatted-only**, totalling 535 manifest
entries before curation decisions. The old 402/261/259/143/2/404 figures describe the historical
2026-08-06 snapshot only. Unlike the OpenLP figures, these are measured rather than tracked,
because the source is local.

**Corrected 2026-08-06.** This paragraph previously read 247 paired, 155 verbatim-only and 14
formatted-only. Those are the first pass's filename-stem figures, which §7.5 supersedes: the two
roots do not agree on filenames, so stem-pairing both missed real pairs and invented false ones.
Pairing by email — same date and normalised `source_subject` — gives the figures above. Because this
paragraph is the reconciliation gate for `discovered = included + excluded`, leaving the superseded
numbers here would have stated a target the approved manifest is built to fail.

Create a signed inventory reporting regular files, symlinks, resolved targets, missing targets,
duplicates, bytes and hashes. A path is eligible only if it resolves inside the approved mounted
root. Permanently unavailable material must become an explicit maintainer-approved exclusion; it
cannot remain an unresolved included item.

Corpus completion is falsifiable:

- `discovered = included + excluded`, with every exclusion carrying a reason;
- `included = promoted_exact + already_present_exact` at G5/G8;
- unresolved, failed, unclassified or hash-mismatched included items must equal zero; and
- the counts and SHA-256 inventory must reconcile independently for Email, OpenLP and recordings.

The archive is not “complete enough” at an informal percentage. It is 100% accounted for under the
approved manifest, even when some source material is deliberately excluded.

### 13.2 Staging and worker isolation

- Use one private storage root per batch, never a shared global root.
- Compare resolved driver/bucket/root/prefix identities; disk aliases to one storage are not
  distinct.
- Serialize approved staging and manifest identity into every queued job context.
- Restart workers after config changes and run a worker-executed canary proving writes remain below
  the batch root.
- Block public/CDN URL generation for staging paths.

### 13.3 Queue safety, readiness and throughput

- Stable unique job identity/locks per manifest item.
- Queue `retry_after` exceeds every timeout.
- Bounded exponential backoff and explicit `failed()` results.
- Record main-chain and fan-out identities.
- Export waits for all required chains/fan-outs, not just the parent log.
- Pin the processing fingerprint as scoped immediately below.

#### What the processing fingerprint pins — and what it must not

The fingerprint exists to answer one question: **would re-running the pipeline on this source produce
different durable media output?** It must therefore pin only the inputs that determine that output:

- transcription service, model and parameters;
- analysis service, model, prompt and schema hashes;
- ffmpeg/codec parameters and the concatenation decision;
- section-detection and structure algorithm versions;
- the configuration those stages actually read; and
- source file hashes.

It must **not** pin the git commit, and the predecessor plan's wording that it "pins the git commit"
([historic plan, invariant 4](../archived-plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)) is superseded
here. A commit-scoped fingerprint changes when the projector, review UI, bundle exporter or any
unrelated code changes, which would mark correctly-processed media stale and force reprocessing for
a change that cannot affect it. Under the §9.4 loop — which iterates the projector deliberately and
often — that would mean reprocessing the entire corpus per matcher improvement, and the loop would
be unusable.

Projector, review, bundle, export and auditor code sit **outside** the fingerprint. They are covered
by the separate projection policy version in §8.1. Keeping these two versioned quantities distinct is
what makes the §13.6 tiering mechanically enforceable rather than a matter of reviewer judgement.

This also protects convergence: `ConvergeHistoricChurchServiceCommand` requires Bundle A's and
Bundle B's `processing_fingerprint` to hash-match, so a projector-sensitive fingerprint would make
the two bundles diverge and refuse to converge after any projector fix.

#### Throughput is the schedule

Every bullet above is queue *safety*. None of them is queue *width*, and no predecessor plan
specified one. That omission matters more than any implementation estimate in §17, because the bulk
pass — not the code — sets elapsed time.

[`docs/operations/llm-structure-promotion-soak.md:197`](../operations/llm-structure-promotion-soak.md#L197)
records **30–60 minutes of pipeline time per service**. Against the tracked 428 curated inclusions —
a proxy only, pending the §13.1 remeasurement against the mounted drive — a serial pass is
**214–428 hours, or 9–18 days continuous**. §13.4's checkpoint cap of 25 recordings or 12 forecast
hours implies **17–36 checkpoints**, each with an operator verification step before advancing.

So a concurrency design is required, and calibration is where it is established:

- Measure which stage actually binds. Local Whisper is native `whisper.cpp` on the host over Metal
  and is effectively a single-GPU serial resource; ffmpeg work is CPU-bound; LLM analysis is a
  remote API call and parallelises freely. These have different optimal widths.
- Set per-stage worker width rather than one global concurrency number, and record it in the pinned
  configuration fingerprint so the pass is reproducible.
- Re-forecast §13.4's total from the concurrent figure, not the serial one.
- Concurrency must not weaken §13.2 isolation or the job identity/locks above. It multiplies
  pressure on the temp disk, which is the known capacity constraint in this pipeline, so the §15.1
  capacity check applies at the chosen width, not at width one.
- If a stage cannot be widened safely, say so and accept the serial figure. An honest 18 days beats
  an unachievable estimate.

### 13.4 Calibration, forecast and checkpoints

Before the bulk recording pass, process a representative calibration slice covering short/long
recordings, each codec/container family, concatenation/re-encode cases and likely sermon/talk/song
fan-outs. Record per-hour wall time, CPU/GPU utilization, Whisper/AI/API cost, network bytes,
temporary/staging growth and failure/retry rate.

From that sample, publish p50/p95 throughput and a total elapsed-time/cost/capacity forecast with at
least 30% contingency. The maintainer accepts the forecast before the full pass. Divide the corpus
into deterministic manifest checkpoints of at most 25 recordings or 12 forecast processing hours,
whichever is smaller. Each checkpoint:

- is independently resumable from the operation ledger;
- never rescans/reclassifies already approved paths;
- waits for every fan-out to settle;
- verifies current assets and readiness before advancing; and
- records actual versus forecast time/cost/capacity so later checkpoints can be re-estimated.

#### Content accuracy, not just fidelity

Every definition of "correct" elsewhere in this plan is **fidelity**: production matches what the
local pipeline produced, proven by hashes, fingerprints and exact parity. Not one of them asks
whether that output is any good. `HistoricNormalOutputContractTest` passes on a 40%-wrong transcript,
because the contract governs field presence and classification, not correspondence to the real
service.

That gap is tolerable for current livestreams and not for 2013-era recordings, which were captured
on far worse equipment than the pipeline's models assume. Without a check, the programme will
deterministically, verifiably and exactly transport poor output, and the §20 definition of done will
be satisfied.

#### Eras are defined by evidence regime — added 2026-08-14

This subsection and §13.1 say "per era" without ever defining one. Defining eras by decade or by
recording equipment would be arbitrary; the archive already partitions itself by **which sources
survive**, which is also what determines whether accuracy can be measured at all. Measured
2026-08-14 against the archive-v12 staging report, the OpenLP curation manifest and the 2026-08-09
hymn reconciliation:

| Era | Surviving sources | Email ids | Accuracy method | Uncorroborated |
|---|---|---:|---|---:|
| 2004–2008 | Hymn, date-only (`●`) | 0 | Song membership by date; no service split | — |
| 2009–2013 | Hymn only (504 ids) | 0 | Song membership; no cross-check exists | — |
| 2014–2018 | Email + Hymn | 133 | Cross-source song membership | **3** |
| **2019 – 2020-03-21** | **Email only — no video, no OpenLP, no hymn sheet** | **54*** | **Hand-verified truth set — nothing else exists** | **all** |
| **2020-03-22 – 2022** | **Email + video (OpenLP from 2021)** | **166*** | **Video corroboration, graded by recording completeness** | **see §13.1** |
| 2023–2026 | Email + OpenLP + Hymn | 195 | Count, sequence and membership all checkable | 7 |

\* **Split 2026-08-14 at the first video-covered Sunday** (see §13.1). These two counts are taken
over plan keys extracted directly from `archive-v12-restage-20260814.json`, which totals **220** for
2019–2022 against the **193** this table originally carried, and 662 identities overall against 521.
The boundary and the ordering hold under either denominator; the denominators themselves are
unreconciled and must be settled before these figures enter the publication decision.

**375 of 521 email identities (72%) carry at least one corroborating source**; the union of all three
sources is **1,594 service identities** against 521 from Email alone.

The 2019–2022 gap is a source gap, not a tooling gap: no hymn source workbook holds a sheet for
those years (`Hymn Database @ 31.12.2023.xlsx` runs 2004–2018 then jumps to 2023) and OpenLP does not
begin until 2021. It is also, precisely, the livestream era — and video, measured 2026-08-14, closes
the second half of it but none of the first. See §13.1 on what that implies for the video curation
manifest.

The two corroborating sources prove different things and the census must record which fired:

- **OpenLP** — 427 included entries, every one carrying a curated `expected_item_count`, and the
  `.osz` files carry item sequence. The only source that can validate item **order**.
- **Hymn `Known Usage`** — 5,759 song occurrences over 1,306 date+service identities with hymn
  number and resolved catalogue song ID. Songs only, and because the source is a crosstab it proves
  song **membership**, never sequence.

Add to the calibration slice, at negligible cost since the slice already exists:

- **A truth set that is derived before it is built.** Cross-source agreement already labels 72% of
  the Email corpus, so the human-verified truth set is not the first step and must not be sized as
  though it were. Derive corroboration first, publish per identity which source corroborated it and
  on which dimension (membership, count, sequence), and hand-verify only what corroboration cannot
  reach — which collapses the manual effort onto 2019–2022 and the single-source hymn eras. Where
  hand verification is required, reuse the `truth.md` pattern already established by the July corpus
  testing in `docs/operations/livestream-corpus-testing.md`.
- Measured accuracy per era for transcript quality, section classification and song identification,
  **stated with its evidence basis** — derived-from-corroboration or hand-verified — because an era
  with no accuracy evidence must never be reportable as an era that scored well.
- A publication threshold. Below it, a service is still imported at full fidelity — the archive stays
  complete — but its media is withheld from public listing using the exposure inputs the application
  already models (`SermonVideoQualityStatus` and `SermonVideoVisibilityOverride`, both declared
  exposure attributes in [`SermonExposurePolicy`](../../app/Services/Sermon/SermonExposurePolicy.php)).
  This is an existing publication mechanism, not a new tiering concept.
- The per-era accuracy figures in the §13.7 private reports, so a decision to publish an era is made
  on evidence.

The different-PK production-shaped rehearsal must separately benchmark deterministic promotion:
per-service p50/p95 apply time, asset-copy throughput, preflight/audit time and rollback/ingress
recovery time. These measurements set the numeric production-window budget accepted at G7; local
Whisper/AI throughput is not a proxy for production apply time.

### 13.5 Rehearsal order

Reordered on 2026-08-02 so that the cheap, media-free work that generates proposals happens *before*
the expensive media pass. Steps 3–4 need no Whisper, no LLM analysis and no video, so the §9.4 loop
converges while the drive is still only being hashed — taking human review off the critical path
instead of serialising it behind 9–18 days of processing.

1. Protect/hash source drives.
2. Build and approve the complete manifest.
3. Stage Email/OpenLP normalized evidence **into a clean rehearsal database** (see F2 below).
   **No media required.**
4. Project the whole staged corpus and converge the §9.4 census over the Email x OpenLP **x Hymn**
   proposal population: automate each class, re-project, re-census, repeat.
   **Corrected 2026-08-14** — this step read "Email x OpenLP", which silently excluded the hymn lane.
   That lane corroborates 297 Email identities, including effectively all of 2014–2018 where OpenLP
   contributes nothing, and reaches 888 identities neither other source covers. As previously
   written, step 4 could not see the only evidence that survives for a decade of the archive. The
   census must record, per identity, which source corroborated it and on which dimension (§13.4).
5. Process a calibration set containing every structural edge case; establish the §13.3 per-stage
   concurrency design and the §13.4 per-era accuracy baseline.
6. Fix only current-path defects demonstrated by retained regression tests.
7. Process remaining recordings locally once, at the chosen concurrency, by checkpoint.
8. Re-project and re-census after each checkpoint as Livestream assertions arrive, automating each
   new class as it appears.
9. Review only the residual ambiguity the census recorded as irreducible.
10. Complete current technical publication review requirements.
11. Export linked Bundles A/B.
12. Import into a clean production-shaped DB with deliberately different PKs.
13. Run exact audit and public/admin smoke tests.
14. Run the entire import again and require all no-op.
15. Restore from backup and repeat apply/rollback proof.

#### Date-only historic song usage import

The 2026-08-09 hymn reconciliation workbook is a separate, media-free evidence lane. Its
`Ambiguous Usage` sheet contains **1,941** song/date statements for which the source does not record
morning or evening. They are not complete service orders and must not create `ChurchService` rows,
use `SermonService::Other`, or guess a service. The dedicated `song_usage_reports` table preserves
the statement and source coordinates; public/admin song usage reads include unresolved reports,
while a report linked later to a canonical service item is excluded to prevent double counting.

**2026-08-12 audit correction (F60-F62): this subsection is not currently an executable production
procedure.** The same workbook also contains 5,759 `Known Usage` rows, and the current importer reads
none of them. Their workbook classifications are 1,013 already represented, 132 review on an
existing service, 643 await pending import and 3,971 candidate new service. Every one needs an exact
post-convergence disposition; choosing to retain or exclude that lane is a source decision, not an
implicit consequence of hard-coding `Ambiguous Usage`.

**2026-08-14 addition: `Known Usage` has two roles, and only one of them waits on F60.** The
correction above treats these 5,759 rows purely as an import-disposition problem. They are also the
archive's richest accuracy signal — hymn number, workbook title and resolved catalogue song ID over
1,306 date+service identities — and §13.4 now depends on them for the derived truth set.

The two roles are orthogonal, and the asymmetry is worth stating plainly because it inverts the
intuition: the **1,013 rows classified "already represented" are the rows most likely to be excluded
from import, and are precisely the rows that corroborate an existing Email parse.** A row being
redundant as evidence to import is exactly what makes it valuable as evidence to check against.

So the validation use does not depend on, and must not be sequenced behind, F60's disposition
approval. Reading `Known Usage` to measure accuracy writes nothing and imports nothing; the F60/F61
guards govern the persisting import lane only. Do not let the (correct) caution about importing this
workbook block the (free) use of it as a measurement source.

The workbook predates archive-v11 and the approved 2026-08-12 Email manifest. It must be regenerated
after Email/OpenLP/Livestream convergence from a retained, auditable procedure. The operation binds:

- all four source workbook hashes and their authoritative year/tab selection;
- the exact converged service membership, source/bundle hashes and song-catalogue fingerprint;
- generation/matching policy and the generated workbook hash;
- exact row counts for every sheet and one disposition for all 7,700 usage rows; and
- duplicate decisions across hymn workbooks, canonical service items and legacy `play_date`.

The currently audited derived workbook hash is
`4a4a7a1524b867184864a334399426f86d0c770b3ff7562cd4b0832f35e2b3b7`. It is not approved production
authority and must not be copied into an approval merely because it is recorded here.

Only after F60's regeneration/accounting is approved may the date-only lane enter rehearsal. Before
production, F61 makes the command operation-owned: `HistoricImportProductionGuard`, signed
operation/target/release/expiry approval, freeze state, exact workbook digest and the expected
1,941/1,867/74 contract must all be checked before any write. Each row records an operation-owned
outcome and the lane participates in exact closeout, visibility smoke, backup/restore and rollback.
Without those controls, `--import` is prohibited even though the command accepts it.

The eventual guarded rehearsal dry run uses:

```bash
vendor/bin/sail artisan migrate --force --no-interaction

vendor/bin/sail artisan service-tracking:import-historic-song-usage-reports \
  --path=storage/scratch/outputs/hymn-reconciliation-2026-08-09/hymn-service-song-reconciliation.xlsx
```

The dry run must report exactly `Rows read = 1941`, `Catalogue resolved = 1867`, `Unresolved for
later matching = 74`, `Created = 0`, and `Already present = 0`, unless the approved regenerated
workbook/catalogue authority deliberately replaces those figures. Any unapproved difference is plan drift:
stop, retain the output, and reconcile the workbook and deployed song catalogue before mutation.
The workbook's local catalogue IDs are deliberately not imported; matching is repeated against the
deployed catalogue title so primary-key differences cannot mislink usage.

After exact operation approval, persist once:

```bash
vendor/bin/sail artisan service-tracking:import-historic-song-usage-reports \
  --path=storage/scratch/outputs/hymn-reconciliation-2026-08-09/hymn-service-song-reconciliation.xlsx \
  --import
```

The first apply must report `Created = 1941` and `Already present = 0` under the current approved
count contract. Immediately rerun the same `--import` command and require `Created = 0`, `Already
present = 1941` **and zero stored-field/resolution drift**. Counts alone are not idempotency proof:
F62 requires the rerun to compare the immutable source statement and its current match against the
stored row. Retain both outputs with the operation evidence. Smoke-test representative matched songs and
confirm the history shows the date, `Service not recorded`, no fabricated service link, and the
combined usage total. Confirm the 74 unmatched reports remain stored with `song_id = null` for
later catalogue resolution rather than being discarded or fuzzily guessed.

F62 also supplies the promised later-resolution workflow. It must distinguish exact no-op,
authorised unmatched-to-matched catalogue resolution, a changed/ambiguous match, immutable source
drift and later linkage to a canonical service item. It may update only under an explicit policy and
must record the outcome; once linked, the report is excluded from the union so one occurrence is
counted exactly once. A final rerun after every approved resolution/link change is entirely no-op.

Rollback is restore-from-backup or a separately approved deletion by this import's source
fingerprints; do not delete or modify canonical services. Once rehearsal and production counts,
the no-op rerun, representative UI checks, backup retention and maintainer sign-off are recorded,
delete `ImportHistoricSongUsageReportsCommand`, `HistoricSongUsageWorkbookReader` and
`HistoricSongUsageReportImporter` in the one-shot retirement release. Keep the table, model and
read side: they are the durable provenance and query contract.

#### Archive-v11 curated-scope ordering (F63, 2026-08-12)

Before the fresh full archive-v11 staging run, compute a corroborated plan's disposition using the
manifest-approved `full`/`partial` scope being assigned. The current working-tree implementation
classifies the old extractor scope first, so a plan extracted as `unknown` remains held after
curation establishes its scope. This fails safe but inflates the review population. Do not copy the
entry scope onto an extra service not corroborated by the manifest; invalid, low-confidence,
special-service and structurally disputed plans retain their ordinary holds. Recheck the balanced
cohort if the correction changes it, then run the fresh complete staging operation and treat only
that report as the review/evidence baseline.

#### F2 — "rehearsal database" has to mean a fresh one (2026-08-07)

Step 3 previously said only "stage Email/OpenLP normalized evidence", and the obvious place to do
that is the working development database. Measured against the current one, that would corrupt the
§9.4 census on its first iteration.

`IngestChurchServiceSourceRevision::stagingReasons()` raises a proposal rather than projecting when
the service already holds items no normalized source accounts for:

```php
if ($hasUnnormalizedLegacyItems) {
    $reasons[] = ['kind' => 'unnormalized_legacy_items',
        'reason' => 'This service still holds legacy items from a source with no normalized
                     evidence, so projecting would delete items no source can account for.'];
}
```

That rule is correct — it is what stops a projection silently deleting items. The problem is the data
it would meet. **The measurements in this subsection are for the historical 2026-08-06 404-entry
snapshot and must be rerun after Email re-curation.** The development database holds **408 services and 2,743 items with exactly one source
record in total**, residue from the 2026-07-20 OpenLP archive run that predates WP1's evidence
ingestion. Cross-matching it against the approved manifest:

| | |
|---|---|
| Manifest identities that already exist locally | **219** |
| ...of which already hold canonical items | **219** (all of them) |
| Manifest identities with no local service | 172 |
| Local services matching no manifest identity | 189 |

So **56% of that historical corpus would stage an `unnormalized_legacy_items` proposal**, and the
census's largest class by a wide margin would be an artifact of one dev-database import from July.
§9.4.1's stopping
rule — "every review point is a bug report against the projector until proven otherwise" — would spend
its first and most expensive iteration on something that is neither a projector defect nor a property
of the corpus.

The same residue is why §12.4's local dry run refused 407 of 408 services. That section already
suspects the local database of being dev-state rather than a production likeness; this confirms it,
and adds the operational consequence.

**So step 3 requires a database with no pre-existing canonical items for the corpus's identities.**
Either a fresh migration, or a restore of a production-shaped baseline, before the first staging run.
This is not tidying: it decides whether the census measures the projector or the dev database.

**Enforced 2026-08-07 by PR28.** Recorded first as a precondition needing no code; writing it down
made clear it had the same defect PR24 diagnosed in the G8 prohibition — an unenforced precondition
has to be interpreted, an enforced one simply answers.
`UnevidencedCanonicalItemGuard::refusalFor()` refuses `oos:import-archive --import` when the curated
identities already hold items with no `source_assertion_hashes`, reporting the affected count against
the corpus size (historically "219 of 391"). The guard's measurement must be rerun against the
approved 535-entry replacement manifest before the current rehearsal can be assessed.

Three placement decisions:

- **Scoped to the identities being staged.** An unrelated legacy service is §12.4's population, and
  refusing on it would make the guard unsatisfiable against any real database.
- **Counted per identity, not per item.** One service raises one proposal however many legacy items
  it holds, so the identity is the unit of review cost the guard is protecting.
- **Checked after the production guard**, so an operator whose shell is pointed at production hears
  the more serious refusal first.

`--accept-unevidenced-items` is the explicit override, for the case where staging over a legacy
corpus is genuinely the intent. The evidence rule itself now lives once, on
`ChurchServiceItem::hasNormalizedEvidence()`, so the guard and
`IngestChurchServiceSourceRevision::stagingReasons()` cannot drift apart about what "unevidenced"
means.

It does not change §12.4's open question, which is the *other* half of the same fact — what to do
about services holding canonical items derived from no retained evidence. That disposition is still
open pending the production audit, and a clean rehearsal database does not answer it.

### 13.6 Rehearsal discovery loop-back

Every discovered fix is classified before implementation:

| Discovery changes | Re-enter at | Invalidate |
|---|---|---|
| Durable output contract, persistence shape, portable identity, asset roles or hashing | G1 | Contract manifest and all affected Bundles A/B |
| Source normalization, active evidence, projection or review result | G2 | Evidence/result hashes, linked manifests and affected A/B exports. **Media is never reprocessed.** Projector changes re-run against stored source revisions; Livestream assertion changes re-run `LivestreamSourceAdapter::adapt()` against the already-persisted `MediaProcessingLog`, which performs no media work |
| Bundle preparation, classification, transaction or auditor | G3/G4 | Prepared plans/tokens and all rehearsal applies after the changed boundary |
| Worker/storage/queue configuration only | G4/G5 | Configuration proof and affected checkpoint outputs |
| Pipeline code, model, prompt or config that changes durable media output | G1 | Fingerprint plus affected local processing; reprocess affected recordings or retain the original pinned implementation |

Never mix outputs from different contract or **processing fingerprint** versions in one accepted
batch. A changed contract version marks old bundles stale automatically. "Fix current-path defect" is
not permission to patch forward without repeating the earliest affected gate.

**This does not apply to the projection policy version, which is expected to advance repeatedly.**
The two versioned quantities are deliberately separate (§13.3):

- The **processing fingerprint** must be uniform across an accepted batch. Changing it means the
  media would come out differently, so affected recordings are reprocessed or the original pinned
  implementation is retained.
- The **projection policy version** advances every time the §9.4 loop automates a class. The batch
  invariant is that all services share the *same* policy version at export time — satisfied by
  re-projecting the whole corpus, which is cheap and pure, not by freezing the projector.

A projector improvement therefore never invalidates media. It invalidates projections, and those are
rebuilt from evidence already on disk.

### 13.7 Private reports

Produce corpus hashes/counts; include/exclude/duplicate/alias and identity decisions; processing and
fan-out states; automatic/manual counts and reasons; publication readiness; bundle/evidence hashes;
exact parity; no-op classifications; asset/capacity inventory; and estimated production/rollback
duration.

Also report, from the additions above:

- the §9.4 census at each iteration — classes, occurrence counts, disposition (`automated` or
  `irreducible`) and the matcher change that closed each automated class;
- the residual hand-review set with measured per-decision times;
- per-era content accuracy against the truth set, and which eras fall below the publication
  threshold;
- the §13.3 per-stage concurrency chosen, the bottleneck stage and serial-versus-concurrent elapsed
  time; and
- the §12.4 current-era re-projection diff, including how many proposals B13 had falsely accepted;
- the regenerated historic hymn workbook's four input hashes, catalogue/corpus fingerprints,
  generation policy and output hash, plus exact dispositions for all 7,700 rows; and
- the operation-owned hymn apply/reconciliation outcomes, including true no-ops, authorised
  catalogue matches, canonical-item links, unresolved rows and cross-source duplicates.

### Acceptance

- Sources unchanged; every discovered source is accounted for and every included recording uses
  manifest overrides.
- No staging path resolves through public/production storage.
- All fan-outs settle and readiness passes.
- Human review is limited to ambiguity the §9.4 census recorded as irreducible, with the automated
  classes and their matcher changes listed.
- Calibration forecast is accepted and checkpoint actuals reconcile to it, at the concurrency
  actually used.
- Per-era accuracy is measured against the truth set and eras below threshold are identified.
- Included-item completion is 100% with zero unresolved items.
- Every historic hymn source row is accounted for; the known-service lane is reconciled only after
  the canonical corpus converges, the date-only lane is guarded/hash-bound, and its second apply is
  an exact stored-state no-op rather than count-only success.
- Different-PK round trip, exact audit, rollback and no-op rerun pass.

## 14. WP8 — Public service history (ships first)

**Purpose:** make service history visible without exposing internal evidence — beginning with the
services production already holds, and extending backwards as the import lands.

**Resequenced 2026-08-02.** This package previously sat after G9 because it is not an
import-readiness gate. That remains true and is not a reason to build it last. It depends on nothing
the import produces: the current-era services, sermons, children's talks and song links are already
in production, and B21 is simply that the routes exist only inside the admin group at
[`web.php:163`](../../routes/web.php#L163). Building it first means:

- the programme's only visitor-visible outcome arrives immediately rather than at the end;
- the §14.2 exposure and policy boundary is designed against real content, and its tests exist,
  before the import can push a decade of new material through it;
- the import extends a working, exercised feature backwards instead of being the precondition for
  one existing at all; and
- WP9's production smoke tests gain a service-history surface to cover, instead of being limited to
  the existing sermon, talk and song pages.

It remains outside every import gate in both directions: WP8 does not gate the import, and the
import does not gate WP8.

### 14.1 Information architecture

Add public archive/detail routes using the established public shell:

- `/church/services` — paginated/filterable by year and service;
- `/church/services/{date}/{service}` — canonical service detail.

The detail can show date/service, publication-safe ordered items, linked published sermon, linked
children's talk only when `SermonExposurePolicy` permits, songs/performance video, public scripture
and honest empty states for incomplete history.

Never show raw source payloads, confidence, proposals/rationales, diagnostics, private paths,
unpublished sections or non-public children's media.

### 14.2 Public policy/read side

Create one query/policy boundary defining public service eligibility, public item types, behavior
when private items are omitted, planned-only visibility, canonical/indexing rules and missing linked
media. Preserve truthful order without exposing placeholders that reveal private content.

### 14.3 UI and tests

Reuse `<x-page.shell>`, content wrappers and existing cards/presenters. Use project brand tokens,
display/body typography and `wire:navigate`. Include mobile, keyboard, focus, loading, empty and
error states.

Test policies, children's exposure, unpublished media, incomplete/repeated services, query counts,
Dusk navigation/filter/keyboard behavior, and Playwright desktop/mobile baselines. Coordinate
baseline timing with the design-system refresh if it has landed, but neither plan gates the other.

### 14.4 Deferred: editorial, legal and consent policy

Deferred on 2026-08-02, recorded here so no later reader mistakes silence for clearance. This plan
treats publication as a data-integrity problem throughout; §3.3 governs *transport* hygiene only —
no raw bodies, no local paths, `0700` directories — and nothing in this document governs personal or
licensed content in the **published output**.

Unanswered:

- **Personal data in orders of service.** OoS emails routinely carry member names and often health
  or bereavement detail. §14.1's "publication-safe ordered items" is undefined with respect to named
  individuals.
- **Music copyright.** WP8 surfaces song performance video and usage history. Whether the church's
  CCLI licence covers retroactive publication of recordings from earlier eras is unasked. The
  application already models `ccli_number`.
- **Speaker consent** for guest preachers across the archive who never agreed to permanent
  publication.
- **Historic children's material.** [`SermonExposurePolicy`](../../app/Services/Sermon/SermonExposurePolicy.php)
  offers one config flag (`church.sermons.childrens_talks.public`, default false) plus a
  verified-email gate. It was designed for current material under current consent norms and cannot
  express "historic children's material is categorically different".
- **Takedown.** There is thorough rollback for data and no unpublish route for content someone
  objects to, with no named owner.

**Where the trigger sits.** Shipping WP8 over the current era is comparatively low-risk: recent
material, current consent norms, already-published sermons. The risk concentrates at the point
historic eras become publicly visible. These questions must therefore be answered before the first
historic era is published, not before WP8 ships — and they are a church-governance decision, not
solely a maintainer one. Until then, imported historic services are complete in the database and
withheld from public listing by the §13.4 mechanism.

### Acceptance

- Visitors can navigate service -> sermon/talk/song and back through history.
- Private/internal data is absent from HTML, metadata and asset URLs.
- Mobile, accessibility, SEO/canonical and performance checks pass.
- Public song usage agrees with service history.

## 15. WP9 — Production operation and closeout

**Purpose:** perform the rehearsed deterministic import exactly once.

### 15.1 Pre-window gates

- All code/migrations deployed; full gates green on that commit.
- Private manifests/reports accepted.
- Timed DB/asset backup and restore rehearsal complete.
- Sufficient DB, staging, destination and temp capacity.
- Worker/config proof complete.
- Numeric ingress-blocked budget and split-window thresholds accepted from rehearsal measurements.
- Ingress blocked and relevant queues drained/paused.
- Production apply disables processing/providers/mail/notifications.
- Final no-write preflight yields the accepted plan/operation hash.

### 15.2 Production-window budget

“Ingress blocked” means new media processing/archive-import submissions are refused and affected
Horizon queues are paused. Ordinary public read traffic remains online. Any deploy/schema step that
would require public downtime needs a separate explicit downtime budget and maintenance response;
this plan does not silently equate queue pause with taking the website down.

The default cap is **60 minutes from blocking processing/import ingress to resuming it**, unless the
maintainer explicitly approves another numeric cap at G8. `artisan down` is prohibited for this
operation unless separately approved; public reads stay available.

G7 records numeric values for:

- `maximum_import_ingress_blocked_minutes`;
- per-service p95 apply time;
- preflight and closeout reserve;
- rollback/reopen-ingress reserve; and
- the latest safe time to start another service.

Before G8, implement and test the exact ingress behavior:

- OpenLP/media/admin upload routes refuse new work with a clear retryable response and corresponding
  disabled admin state;
- the inbound Mailgun OoS route is lossless—either it continues to durably stage payloads outside
  affected processing, or a documented/tested provider retry contract is used;
- the separate scheduler cannot enqueue affected work while the lock is active; and
- if Horizon is paused globally rather than by affected queue, the budget/report explicitly records
  the delay imposed on unrelated default/background work.

#### Implemented 2026-08-06

The first three requirements have landed; §17's PR sequence never allocated a slice for them, which
is why they were still open after PR17. What exists:

- **The lock is a table, not a cache entry.** `import_ingress_locks` records operation id, reason,
  operator, and blocked/released timestamps, with a unique index on a nullable `is_active` column so
  "at most one active lock" is a database guarantee. A cache-backed flag would disappear on a Redis
  restart or a `cache:clear` mid-window and reopen ingress with nothing to show it had.
- **`ImportIngressGate`** owns `block`/`release`/`isActive`/`blockedMinutes`, driven by
  `artisan import:ingress {block|release|status}`. Release reports the window's actual duration for
  checking against the accepted `maximum_import_ingress_blocked_minutes`.
- **`RefuseBlockedImportIngress`** (alias `import-ingress`) returns 503 with `Retry-After` on the
  media upload route and the processing-retry route. Status, stream and cancel stay open: they
  observe or stop work, and cancelling during a window is something an operator may need.
- **The inbound Mailgun route stays lossless** by keeping its durable `firstOrCreate` staging and
  deferring only the dispatch, answering with `202 deferred`. Refusing instead would push an order of
  service onto Mailgun's retry schedule. `import:ingress release` sweeps the staged pending emails
  back onto the queue, so the deferral ends by itself rather than waiting to be noticed.
- **The scheduler skips both media cleanup commands** while the lock is held. These are the sharpest
  case in the whole requirement: they *delete* media on an age heuristic, and a window is exactly
  when the importer is writing assets to destinations no publication points at yet.

Covered by `ImportIngressGateTest` and `ImportIngressScheduleTest`, including that public reads stay
online throughout.

#### Completed 2026-08-06 — Horizon pause accounting and the admin upload state

All four requirements now hold.

- **The condition §15.2 makes the extra reporting conditional on is true here, structurally.**
  `supervisor-media` serves `default` in the same strict-priority queue list as the media queues, so
  there is no pause that stops import work and leaves ordinary background work running. Horizon
  pauses supervisors, not queues. `HorizonPauseAccounting` derives this from `config('horizon')` on
  every window rather than asserting it in prose, so a future supervisor split that *does* make a
  queue-granular pause possible is reflected in the report instead of silently contradicting it.
- **`media-processing.queues.default` is excluded from the import-only queue set.** It resolves to
  the application-wide `default` queue, which also carries mail, notifications and every unqueued
  job. Counting that key as import-only would erase the whole finding, so a test pins it.
- **The delay figure is recorded on the lock row**, in a `queue_pause_accounting` JSON column: the
  supervisors that must pause, the collateral queues, the collateral depth at block and at release,
  the window duration as `collateral_delay_minutes`, and `collateral_jobs_delayed`. It lives on the
  lock because the lock already is the window's record; a delay figure separated from its window is
  not evidence of anything. A depth that cannot be read is stored as `null` — "not measured" — never
  as `0`, and never blocks an operator from opening a window.
- **`import:ingress block` names the supervisors to pause and what else stops when they do**, before
  the window opens rather than in the closeout; `release` reports the delay and how to resume them.
- **The admin uploader refuses and says so.** The Livewire screen calls `UnifiedMediaProcessor`
  directly and never travels the API route, so `RefuseBlockedImportIngress` never saw it: the window
  was not actually closed on the admin path. `MediaUpload` now refuses in `uploadComplete` and
  `startProcessing` and replaces the form with an explanation. The guard cannot live in the processor
  — the historic importer is also one of its callers, and the window exists precisely so *that* work
  can run — so the seam is where new outside work is admitted.

Covered by `ImportIngressQueuePauseAccountingTest` and `MediaUploadImportIngressTest`.

**Found while doing this, not fixed:** `sermon-processing` appears in `supervisor-media`'s queue
list in `config/horizon.php` and nowhere else in the application — no job dispatches to it. The
accounting therefore reports it as a collateral queue alongside `default`, which is true but noisy.
Removing it is a production queue-topology change and needs its own check that nothing is stranded on
it; it is not part of this requirement.

The command stops admitting new services when remaining time equals the greater of **15 minutes** or
the accepted p95 closeout/resume duration. It also refuses to start a service when its p95 apply time
plus recovery reserve exceeds the remaining budget. Splitting the operation across two or more
windows is permitted. At a planned
split it finishes or safely rolls back the current atomic service, audits the completed subset,
records the ledger checkpoint, resumes ingress/queues, and requires a new whole-operation preflight
and token before the next window. Completed services are revalidated as exact `already_present`.

If an in-flight service unexpectedly breaches the forecast, do not kill the process mid-transaction
or improvise. Complete/roll back that service safely, restore ingress, record the budget breach and
return to G5/G7 forecasting before another window.

### 15.3 Apply and immediate closeout

Apply services in deterministic manifest order and stop at the first mismatch. Preserve the private
ledger. Do not improvise production repairs: roll back or return to code/rehearsal.

Immediately run the exact Bundle A/B audit; full second no-op import; public-song zero-loss check;
admin service plus existing public sermon/talk/song smoke tests; asset storage/HTTP checks;
zero-dispatch/error-log review; and manifest reconciliation of automatic/manual/blocked counts.

### 15.4 Closeout and retention

G9 is satisfied by evidence, not by elapsed time. The predecessor revision required a rollback soak
before cleanup; the evidence that soak existed to produce is available within minutes of the run,
and is exactly what §15.3 already lists — exact Bundle A/B audit, a full second no-op import, the
public-song zero-loss check, admin and public smoke tests, asset storage/HTTP checks and a
zero-dispatch review. When those are green, G9 is met and WP10 may start.

Retention is separate from gating and remains unconditional. Keep sources, staging, bundles,
ledgers, backups and compatibility readers until WP10 retires them on its own evidence in §16. You
cannot un-delete, so retention is cheap insurance; it must never hold up the next step.

Run the exact auditor again on the schedule the health checks already provide, so drift is detected
if it appears. Any drift reopens the plan and blocks cleanup — whenever it is found, not only within
a nominal window.

### Acceptance

- Production manifests equal accepted local manifests.
- Second import is entirely no-op.
- No processing/external work was dispatched.
- Processing/import ingress resumes within the accepted cap, with no lost inbound email/upload and
  no interruption to ordinary public reads.
- Admin service history, the WP8 public service archive and existing public sermon/talk/song URLs
  all work.
- Current-era re-projection (§12.4) is audited item-level and its reopened proposals are triaged.
- Maintainer signs off the closeout evidence.

## 16. WP10 — Contract and one-shot retirement

Only after G9:

1. Record every one-shot's production completion/deletion trigger.
2. Remove spent commands in R8/R12 ownership order.
3. Remove temporary compatibility writers/readers.
4. Contract obsolete schema in a later release than reader removal.
5. Retain exact auditors, portable contract tests and public read-side tests.
6. Archive this plan and reconcile R8, historic-media, remainder and index statuses.

Keep the normal-output canary permanently as the portable-processing regression contract.

## 17. Proposed PR sequence and sizing

**Sizing changed on 2026-08-02 from engineer-days to review surface.** The predecessor scheme
estimated typing time, and this programme is executed by agent, so typing time no longer predicts
anything. What still costs is *human review of agent-authored changes* and *blast radius if the
change is wrong* — so that is what the sizes now describe:

- **XS:** mechanical; one obvious invariant; no live path touched.
- **S:** one invariant; contained; failure is visible immediately.
- **M:** several related invariants, or one new surface; failure is visible in tests.
- **L:** touches a live path, a real database constraint, portable identity or production mutation.
  Requires the reviewer to hold the whole invariant in their head.
- **XL:** must be split before implementation.

**Implementation is not the schedule.** Elapsed time is set by two things, neither of them coding:
the §13.3 bulk media pass (9–18 days serial, less if the concurrency design succeeds) and the §9.4
residual review after the automate-first loop converges. Plan against those, not against this table.

**Status updated 2026-08-06.** PRs 1–17 and PR21 are merged. The work landed in **work-package order
(WP0 → WP6, then WP8, then WP7)** rather than the PR order this section proposes, and PR1 (WP8)
shipped last-but-one instead of first — it was skipped at the start and picked up after PR13. **Two
code slices remain**, PR22 and PR23, both drive-free; they were identified on 2026-08-06 as
unscheduled work in prose and are now numbered rows so they cannot be lost again. **PR24 was added
the same day** for the same reason — resolving the header's G8 scope showed the prohibition was
enforced nowhere. All three are now delivered. Status values:

- **Done** — the slice's PR merged and its own tests pass.
- **Done†** — merged and working, but a **gate-acceptance audit found a coverage gap** that must close
  before the slice's gate (G1–G9) can be claimed. The dagger points to "Acceptance and gate readiness"
  below.
- **Partial** — a feature within the slice's scope is actually broken or absent, named explicitly.
- **Ready** — every dependency is Done, so an agent can start it now.
- **Blocked (PR n)** — waiting only on the named predecessor.

**"Done" is a merge signal, not a gate certification.** These labels track whether each slice's PR
landed with its own tests green. They do **not** certify that the work package's full plan acceptance
is met or that its gate is passable — the B1/B2 case proves committed code can still carry certain
crashes. A separate gate-acceptance audit (below) has confirmed coverage gaps in several landed
slices; those gaps, not the merge status, decide when a gate can be claimed. Further per-slice
acceptance findings roll into that same audit list rather than changing the merge status.

| PR | Scope | Size | Depends on | Status |
|---|---|---|---|---|
| 1 | **WP8 public service archive/detail over current-era data** | M | None | Done (shipped out of order, after PR13) |
| 2 | WP0 canary, consolidated contract matrix and named red tests | M | None | Done (G1 canary row closed by PR14) |
| 3 | Immediate B1/B2 direct persister fixes and B17 streaming exporter/transfer | L | PR 2 | **Done** |
| 4 | Additive lineage/portable-identity schema if required | M, or XS/no-op if unnecessary | PR 2 | Done |
| 5 | Pure Email/OpenLP adapters and manifest schema | L | PR 4 | **Done†** (G1: manifest schema incomplete) |
| 6 | Active revision and projector matching/cardinality/order | L | PR 5 | Done |
| 7 | Automatic finalisation and canonical/evidence manifests | M | PR 6 | Done |
| 8 | Review-state/action correctness | M | PR 7 | Done |
| 9 | **§9.4 proposal census, cross-service queue and rule-level dispositions** | L | PR 8 | Done (G2 gap closed 2026-08-05) |
| 10 | Review workbench and Dusk behavior | L | PR 9 | Done |
| 11 | Bundle B automatic/manual schema, proposal dispositions and `decision_rule` | L | PR 9 | **Done†** (G3: no different-PK round trip) |
| 12 | Remaining Bundle A graph, portable identity and path-independent hash | L | PRs 2–3 | **Done†** (G3: no canary round trip) |
| 13 | Multi-role content manifest and destination allocation | M | PR 12 | Done |
| 14 | Remaining persistence, shared classification and richness convergence | L | PRs 11–13 | Done |
| 15 | Binding preflight, ledger, orchestrator and auditor | L | PR 14 | Done |
| 16 | **§12.4 current-era re-projection, corpus diff and B13 reversal** | L | PR 15 | Done |
| 17 | Worker-safe staging, manifest-authorised dispatch and §13.3 throughput design | M | PR 15 | **Done** (2026-08-06; the last slice of the original sequence) |
| 18 | Rehearsal discoveries with earliest-gate loop-back | Contingency; size each finding | PRs 2–17 | **Ready** |
| 19 | Production-operation fixes, only if rehearsal proves them necessary | Contingency; size each finding | PR 18 | Blocked (PR 18) |
| 20 | Post-closeout cleanup/contract migration | M | G9 only | Blocked (G9) |
| 21 | **§7.5 Email/OoS curation manifest, shared with the OpenLP format** | M | PR 5 | **Done** (2026-08-06; class, dry-run reconciliation and command repoint merged) |
| 22 | **§12.4 production evidence-coverage audit and lineage-audit whitelisting** | M | PR 16 | **Done** (2026-08-06; drive-free) |
| 23 | **§13.4 deterministic-promotion benchmark (per-service p95 apply, asset-copy throughput, preflight/audit and rollback timings)** | M | PRs 11–12, 15 | **Done** (2026-08-06; drive-free. Instrument landed; the numbers need a rehearsal run) |
| 24 | **G8 production-import guard (`HistoricImportProductionGuard`, four call sites)** | M | None | **Done** (2026-08-06; drive-free) |
| 25 | **§9.4.6 per-source evidence coverage in the G2 gate (F3)** | S | PR 9 | **Done** (2026-08-07; drive-free) |
| 26 | **§9.4.6 `expected_services` reconciliation rule (F1)** | M | PR 25 | **Blocked** on the §19 maintainer decision |
| 27 | **§13.1 manifest-declared OpenLP accounting, replacing `ExpectedCounts` (F4)** | S | PR 5 | **Done** (2026-08-07; drive-free, and removes a code edit from drive day) |
| 28 | **§13.5 clean-rehearsal-database guard (F2)** | S | PR 5 | **Done** (2026-08-07; drive-free) |

### Acceptance and gate readiness (audit)

The slices above merged, but a review of their tests against each work package's stated acceptance
found gaps where a landed slice cannot yet certify its gate. Each is a verified punch-list item, not a
reason to reopen the merge — close it before claiming the named gate. Newly discovered acceptance
gaps in landed slices belong here.

| Gate | Slice | Verified gap | Close it by |
|---|---|---|---|
| G1 | PR2/PR3 | **Closed 2026-08-04.** `HistoricMediaGraphPersister::persist()` now creates the run before linked publications and stages section media before final publication state; the two named regression tests pass against MySQL. | `HistoricMediaGraphPersisterTest::it_creates_the_run_before_linked_publications` and `it_transitions_a_section_to_published_only_after_required_media_exists`. |
| G1 | PR2 | **Closed 2026-08-05.** PR14 persisted the church-service links, the re-attachment block in `HistoricNormalOutputContractTest::persistCanaryThroughRealPath()` is gone, and the `service_item_identity`, `matched_item_identity`, `expected_item_identity` and `church_service_identity` assertions now exercise the persister. The helper's remaining teardown only *releases* the source run's claim so the persister can take the items; it re-attaches nothing. | `HistoricNormalOutputContractTest` over the real-path canary. |
| G1 | PR5 | **Schema closed 2026-08-06; data population outstanding.** The curation manifest is now version 2 and carries §7.3's missing fields: a manifest-level `batch_key`, and per entry `item_key` (unique), `source_kind`, `parse_decision`, `concatenation_decision`, `expected_item_count` and `decided_by`/`decided_at` or `decision_rule_version`. All are in the manifest and plan hashes, so an apply binds to the decision that authorised it. `validateIncludesForDryRun()` reconciles the parse against them: it fails on an item count that contradicts the manifest, and `strict` now fails closed on the embedded-`.osj` filename mismatch the parser already reports, which `manifest-authoritative` is the recorded adjudication of. **Still open:** *populating* the new fields for the real corpus needs the mounted source drive. | Populate the v2 curation fields against the mounted read-only drive as part of §13.1's remeasurement, then re-approve the manifest. |
| G2 | PR9 | **Closed 2026-08-05.** `ChurchServiceProposalCensusGate::evaluate()` now takes corpus-completeness evidence as a required second argument and fails closed on four conditions: no approved manifest count, staged below or above it, or staged services not projected at the current policy version. `ChurchServiceCorpusCompleteness` derives staged and projected counts from source revisions and `projection_policy_version` rather than from proposals, so an unrun corpus can no longer look like a converged one. `services:proposal-census --gate` no longer short-circuits to success on an empty census. | `ChurchServiceProposalCensusGateTest`, plus the empty-corpus cases in `ChurchServiceProposalCensusCommandTest` and `ReviewChurchServiceProposalQueueTest`. |
| G3 | PR11 | **Closed 2026-08-06.** `ChurchServiceConvergenceBundleRoundTripTest` exports a reviewed bundle, destroys the database it came from, rebuilds an equivalent machine base on shifted auto-increments and applies the bundle to it — asserting exact finalisation (the same canonical hash), the reviewer resolved by approved email hash onto a different user id, per-proposal dispositions reproduced, the review session naming the *production* proposal ids, and a `decision_rule` reproducing with its own rationale. A proposal absent from the production graph still fails closed. Verified non-vacuous: adding `$proposal->id` to `ChurchServiceProposalIdentity::for()` fails two of the three tests. | — |
| G3 | PR12 | **Closed 2026-08-06.** `HistoricProcessingResultBundleRoundTripTest` exports the WP0 canary — the shared fixture, now in `tests/Support/HistoricNormalOutputCanary.php`, not a second approximation — through Bundle A and imports it into a database whose auto-increments have been shifted past every id the source used. Asserts identical logical hashes, no lost field/relationship/role, identical section and publication natural keys, and that the recreated tables moved while preacher/song/service rows were *resolved* by natural key rather than duplicated. Verified non-vacuous: appending `$section->id` to the section key fails the hash equality. | — |

| G1 | PR5/PR21 | **Schema and Email data authority closed.** PR21 built `OosCurationManifest`, `OosCurationPlan`, `OosCurationEntryFactory`, `validateIncludesForDryRun()` and the repointed command. The 2026-08-09 replacement was re-curated 2026-08-11 under decision D1, then corrected and re-approved 2026-08-12. The current authority validates its 534 included entries (535 total, 1 excluded) with zero identity disagreements, includes the three current-era entries and yields 521 identities. Batch `oos-curated-2026-08-12`, manifest `474d32c4…d8451`, plan `6795f149…16cda`. | Closed for Email data authority; F63 must close before fresh full archive-v11 staging. OpenLP/video manifest work remains under the final-readiness gates. |

**Every code gap listed on 2026-08-06 is closed.** G1's crash tranche, canary, OpenLP manifest schema
and the Email manifest; G2's empty-census gap; and G3's two different-PK round trips are all done.
What remained in the table above was a maintainer approval, not an implementation gap — and the two
data-population rows (OpenLP fields, OoS rule set) are what convert schema into authority.

#### Findings added by the 2026-08-07 readiness audit

The sweep that closed the rows above asked whether each landed slice met its own acceptance. It did
not re-verify the *path between* the slices, which is where these four sit. Three need code; one is a
precondition on how the rehearsal is run. None is breakage — all four gates are green on `master`,
and the approved OoS plan hash still reproduces exactly.

| Gate | Finding | Verified gap | Close it by |
|---|---|---|---|
| G2 | **F1** — `expected_services` is not derivable from the approved manifest | The replacement manifest yields 521 approved identities, while a source may validly produce additional services. A scalar staged count cannot prove that every approved identity exists or that every extra belongs to this batch—the defect final-readiness F53 records. | **Decision closed 2026-08-09; implementation open with F53.** Certify exact approved membership and permit only hash-covered `service_beyond_manifest` identities; unexplained excess fails. |
| G2 | **F3** — the gate cannot distinguish Email-only staging from Email + OpenLP | `ChurchServiceCorpusCompleteness::stagedServices()` counted distinct `church_service_id` in `church_service_source_records` and never inspected `source`. Staging Email alone against an Email-derived `expected_services` gave `staged == expected` and an empty census, so G2 would have passed over a corpus with zero OpenLP evidence — the §9.4.2 population it is meant to certify. | **Closed 2026-08-07 (PR25).** The evidence now carries `staged_services_by_source`, `declared_source_kinds` and `unstaged_source_kinds`; the gate adds `census_source_kinds_undeclared` and `declared_source_kind_unstaged`, both failing closed. `church.historic_corpus.census_source_kinds` declares the scope, and an unrecognised kind reads as undeclared so a typo cannot narrow the requirement. The census command and the review queue both name the unstaged kind. |
| G5 | **F2** — staging into the working dev database fabricates the census's largest class | In the historical 2026-08-06 snapshot, 219 of the manifest's 391 identities already existed locally and **all 219 held such items**, from the 2026-07-20 OpenLP run. 56% of that historical corpus would stage a proposal that is dev-state residue, and §9.4's automate-first loop would triage it first. The same guard must be remeasured against the replacement manifest. | **Closed 2026-08-07 (PR28).** `UnevidencedCanonicalItemGuard` refuses `oos:import-archive --import` when curated identities already hold items no source explains, reporting the affected count against the corpus size. Scoped to the staged identities, so §12.4's unrelated legacy population does not block a run. `--accept-unevidenced-items` is the explicit override. |
| G1 | **F4** — `OpenLpCurationManifest` hard-codes the accounting §13.1 says to remeasure | `ExpectedCounts` was a private class constant (536/428/105/3/7) and `plan()` threw on any other value, while §13.1 instructs a remeasurement against the mounted drive and states the tracked counts are not proof. Since 431 already-de-duplicated symlinks are what is staged, a different answer is the expected outcome. `OosCurationManifest` has no such constant. | **Closed 2026-08-07 (PR27).** Manifest version 3 requires `expected_counts` in the envelope; the constant is gone. The declaration is validated strictly (every key, no extras, non-negative integers) and checked against the entries, so a manifest that has silently lost entries still fails — which is what the constant was for. A v2 manifest declares nothing and is rejected rather than defaulted. |

#### PR22 delivered (2026-08-06)

`audit:service-evidence-coverage` reports §12.4's three required counts — services, services carrying
at least one non-Manual source record, and proposals carrying a resolver — plus the populations the
decision actually turns on: Manual-only services, services with no source record at all, and of
those, **the ones holding canonical items anyway**. That last figure is the one §12.4 says the plan
has never asked about: a canonical result no retained evidence describes, which success criterion 1
presumes does not exist. Source records break down by kind, proposals by status and by
`decision_rule_id`, and projection coverage is read from the existing
`ChurchServiceCorpusCompleteness` rather than derived a second time.

It **always exits 0** when its queries complete. An unevidenced service is the measurement, not a
failure, and §12.4 explicitly leaves its disposition open (back-fill, exclude, or accept as legacy);
failing the run would prejudge a maintainer decision and go red on every production invocation.

**Found while doing this, and fixed:** `service-tracking:audit-source-revision-lineages` could not be
whitelisted as it stood. Its output names revision ids and lineage keys, and a lineage key is
`{church_service_id}|{source}|{source_key}` — where `source_key` is an email message id or an archive
filename. Whitelisting it unchanged would have published exactly what the workflow's own header
forbids. It now follows the same `--details` convention as the asset audits: defect counts per kind
by default, repair text on the server. `ChurchServiceSourceRevisionLineageInspector` gained
`issueCounts()` alongside `issues()`, both built from one traversal; the projector and ingest paths
are untouched. Its existing multi-leaf test now asserts against `--details`, and two new tests pin
that the default output contains the defect kind and not the source key.

Both commands are in `.github/workflows/production-audit.yml` as `service-evidence-coverage` and
`source-revision-lineages`. Running them is operator work behind the environment approval gate.

##### The workflow could not reach production at all — found 2026-08-07

Dispatched for `service-evidence-coverage` on 2026-08-07 and approved by the maintainer, the run
failed in seconds with `error: missing server host`. The cause is configuration, not code, and it
predates PR22:

| Environment | Secrets | Approval gate | Used by |
|---|---|---|---|
| `production` | `PROD_HOST`, `PROD_USER`, `PROD_SSH_KEY` | **none** | `deploy.yml`, `rollback.yml` |
| `production-audit` | **none** | required reviewer | `production-audit.yml` |

Environment secrets are only visible to a job targeting that environment, and there are no
repository- or organisation-level secrets to fall back on. `production-audit.yml` declares
`environment: production-audit`, so every `secrets.PROD_*` reference in it has always resolved to
empty. The workflow's previous run, 2026-07-25, failed the same way.

**So this audit has never successfully executed through GitHub Actions**, and PR22 whitelisted its
commands into a workflow that could not authenticate. **Superseded for the immediate §12.4
measurement on 2026-08-09:** the operator ran the same read-only command manually on production and
obtained the counts recorded in §12.4. **Maintainer decision, 2026-08-09:** manual SSH audits are an
acceptable permanent operational process. The missing workflow credentials are therefore not an
operational gap or readiness blocker, and `production-audit.yml` may remain unable to authenticate.
Repeat the read-only audit manually after the evidence back-fill and re-projection.

**Noted while diagnosing, and outside this plan's scope:** `production` has no protection rules, so
`deploy.yml` and `rollback.yml` currently run without review. If review before touching production
was the intent, the gate is presently on the read-only workflow and absent from the two that mutate.

#### PR23 delivered (2026-08-06)

**The operation now measures itself, and the §15.2 budget is computed from what it measured.**

The gap was not that promotion had never been benchmarked; it was that it *could not* be. The
convergence ledger recorded `prepared`, `service_started`, `service_completed` and `failed` and
**carried no timestamp on any of them**, so even a completed rehearsal left no way to derive a
per-service apply time. The measurement had to exist before the benchmark could.

- `HistoricConvergenceLedger` stamps every entry with `at` centrally in `append()`, so an event
  cannot reach the ledger without a time, and carries `duration_seconds` on prepared, completed and
  failed events plus `asset_bytes`/`asset_seconds` on completion. A new `recordCloseout()` takes the
  exact audit, which has no operation plan of its own because it runs after the plan is spent.
  Durations are nullable throughout: an unmeasured event says so rather than reporting a service
  that applied instantaneously.
- `ConvergeHistoricChurchService` times batch preparation, each service's apply, and — deliberately
  *after* asset cleanup — each failure, because §15.2's rollback reserve has to cover compensating
  the assets and not merely the throw. Timing is `hrtime()`, immune to a clock step mid-operation.
- `HistoricPromotionMeasurements` extracts samples from ledger entries; `HistoricPromotionBudget`
  derives §15.2's five values: `maximum_import_ingress_blocked_minutes` (an input, since only the
  maintainer accepts it), per-service p95 apply, preflight/closeout/rollback reserves, services per
  window, the admission floor, and the latest safe start before the window closes.
- `service-tracking:promotion-budget` reports it; `service-tracking:audit-convergence --operation-id=`
  records the closeout sample.

Four decisions a reviewer should not have to reconstruct:

- **Percentiles are nearest-rank, not interpolated.** With the handful of samples a rehearsal
  produces, interpolation invents a p95 no service actually took, and always a *smaller* one than
  the worst observed case. A window budget must be built from durations that really happened.
- **Asset bytes are role-expanded.** §17 already records that one physical file carrying N roles
  becomes N production copies; the unique-asset total would understate what was written.
- **Asset throughput is a floor, not a peak.** The measured seconds are the whole media-persistence
  phase, which contains the copy alongside its database writes. A floor is the correct side to be
  wrong on when sizing a window.
- **The command fails when the budget is unacceptable** — an unmeasured phase, or a window that
  cannot fit one service. This is the opposite of PR22's audit and deliberately so: G7 accepts
  numbers, and exiting 0 on a budget with no measurements would let "nothing was measured" pass as
  "the window fits".

**What this does not yet give you.** The instrument is complete; the *numbers* are not, and cannot
be until a rehearsal apply, a rollback and a closeout have actually run — §13.5 steps 12–15. Against
an empty ledger the command correctly fails with four unmeasured phases. G7 remains unmet, but it is
now unmet for want of a rehearsal rather than for want of a way to measure one.

#### PR24 delivered (2026-08-06)

**The G8 prohibition is now a precondition rather than a sentence, and its scope is production.**

This slice exists because an ambiguity in the status header had made the next drive-free task
undecidable, and the ambiguity was itself a symptom: an unenforced rule has to be interpreted, where
an enforced one simply answers. Two things were wrong at once. Read literally, the header forbade
`oos:import-archive --import` outright — and since `EmailSourceAdapter` is invoked from nowhere but
`InboundEmailImportService::import()`, that forbade the only route to staged Email evidence, hence
§13.5 steps 3–4 and hence G5. Meanwhile nothing in the code stopped that same command being pointed
at production, so the prose was over-broad and under-protective simultaneously.

- `HistoricImportProductionGuard` (`app/Services/Import/HistoricImportProductionGuard.php`) returns
  an operator-facing refusal, or null. Outside production it is silent. In production it fails closed
  until `church.historic_corpus.production_import_approval` names the approved G8 operation, on the
  same principle as `ChurchServiceProposalCensusGate`'s unset corpus size: the absence of a decision
  is not a decision. A blank or whitespace-only approval is not an approval, so a stray `=` in an env
  file cannot defeat the default.
- Four call sites: `oos:import-archive` (`--import` and both bundle modes),
  `service-tracking:import-openlp-services --apply`, `sermons:import-historic-videos` dispatch, and
  `service-tracking:converge-historic-service --apply`.

Three placement decisions a reviewer should not have to reconstruct:

- **Dry runs and preflights are not guarded, in production or anywhere else.** Revalidating the
  production-window prerequisites *is* G8, so blocking the preflight would make the gate unreachable.
  In the convergence command the guard therefore sits after `prepare()` and inside the `--apply`
  branch, and `the_production_preflight_is_not_blocked` pins that.
- **The refusal precedes the plan-hash check** in the OoS and OpenLP commands, and precedes the
  manifest requirement in the video command. An operator whose shell is pointed at production should
  be told that, not sent away for a hash or a manifest that would not have helped.
- **It guards the environment, not the storage or the connection string.** Storage isolation is
  PR17's per-batch staging root; an operator who deliberately points a non-production `APP_ENV` at
  production infrastructure is out of scope. What this catches is the realistic mistake — a
  production `.env` in the shell a rehearsal command was typed into.

The slice also corrected §7.5's claim that "dry-run and evaluation modes are read-only". Evaluation
mode writes `InboundEmail` rows and parse caches; what makes it staging rather than importing is that
it creates no canonical service and releases nothing to the review inbox. That narrower boundary is
now the one the plan states, and a test pins it.

#### Gates reopened by the historic import safety remediation (2026-08-13)

A review of commits `ada4b0483e`..`ac1468b472`
(`docs/reviews/historic-import-commit-review-2026-08-12.md`) found eight defects, three High, and
raised the [safety remediation addendum](HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md). Its code
packages **HIR0–HIR7 are all committed** as of 2026-08-13; HIR8, the rehearsal that converts them
into gate evidence, is not. The full package-by-package ledger, with commits and covering tests, is
recorded once in the
[final-readiness plan's 2026-08-13 entry](HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md#2026-08-13--hir0hir7-landed-pre-hir-evidence-invalidated-and-partly-remeasured)
and is deliberately not duplicated here.

What matters for this plan is that three of those packages change **derived output or accepted
evidence**, which reopens gates that had previously been recorded as closed above. This is not
breakage — the gates' code is in better shape than when they closed. It is that the evidence behind
the closure was produced by superseded behaviour.

| Gate | Reopened by | Why the earlier evidence no longer certifies it | Close it by |
|---|---|---|---|
| G1 | HIR3 | `HistoricNormalOutputContract` is now version 5 and `scripture_passage_outcome` is a required field. Any Bundle A export or canary result produced under version 4 states nothing about settlement, and the destination previously read that silence as approval. The metadata serializer also dropped the outcomes entirely, so exported evidence was lossy in a way no test caught. | Re-export the affected historic media under the v5 contract, run Scripture enrichment before the window, and prove destination preflight has zero missing keys |
| G2 | HIR2 | The 2026-08-12 full staging run reused parses resolved under the old cache contract, which could not be invalidated by a re-curation. The replacement runs under `--fresh-parse` produced different dispositions, so the source and projection populations G2 certifies against have moved. | **Staging and F1 discharged 2026-08-14**: restaged from a reprovisioned database (report `a6767b99…bff07`, 109 created / 19 evidence_retained / 404 held / 2 merged, date accuracy 98.5%), and the F1 adjudicated membership recomputed — 158 written services = 127 manifest-named + 31 explained extras, zero unexplained, zero off-manifest dates, verified non-vacuous. **Still open**: completeness (127 of 521 approved identities staged; 404 sources held for review), the proposal census, and declared-source-kind coverage over a combined corpus |
| G3 | HIR3 | The different-PK Bundle A round trip asserted logical hash equality over a version 4 payload. The canary fixture in `tests/Support/HistoricNormalOutputCanary.php` gained the outcome field, so the previously asserted hashes are not the hashes the current contract produces. | Re-run both round trips on the release candidate and record the new hashes |
| G4 | HIR3 | The binding no-write preflight is the exact check HIR3 found could pass on an unsettled outcome — a null passage with no outcome returned the same answer as an approved terminal absence. A green preflight recorded before `f4341d4d4` proves nothing about settlement. | Re-run the whole-operation preflight under the v5 contract |
| G7 / G8 | HIR4, HIR5 | Custody and recovery artifacts are both version 2 and version 1 **cannot** satisfy the repaired gates. HIR4 found two writable folders on one disk accepted as independent copies; HIR5 found one backup object presented as both the on-host and off-host restore. Any prior acceptance rested on exactly those defects. | Re-observe source custody on the acquisition host, and regenerate signed recovery evidence verified only from mapped retained artifacts |
| G5 / G9 | HIR6, HIR7 | Closeout accepted `dispatched` as terminal, so a rehearsal could report exact closeout with an unimported order of service. Release had no durable destination ownership, so the concurrency and rollback exercises never tested the case that actually loses data. | Exercise the ingress freeze with a real signed webhook, and run two interleaved release attempts plus the signed rehearsal release and rollback |

**G8's production prohibition is unchanged and still production-scoped.** Nothing here re-blocks
local staging.

**One further correction to the record above.** The `crockenhill_rehearsal` database referenced by
the closed rows is gone. As checked on 2026-08-13 it holds 440 services, 537 source records
(email = 110, openlp = 427) and 278 inbound emails with 200 still `pending` — the residue of a
combined restage killed part way through the Email corpus, all timestamped within 40 minutes on
2026-08-13. It is not the step-3 database and not any completed run. Reprovision before staging and
do not read counts out of it.

### Next task to pick up

**Updated 2026-08-13 after HIR0–HIR7 landed.** The drive-free code queue is empty: every scheduled
slice in this plan, and every code package in the safety addendum, is committed. What remains is
evidence, and it splits into one item that needs nothing and a set that needs infrastructure.

~~**Drive-free and unblocked — do this first:** reprovision, restage under HIR2, recompute the F1
adjudicated membership analysis.~~ **Done 2026-08-14.** F1 holds against post-HIR2 writes; see the
G2 row above and the final-readiness plan's `2026-08-14` entry. Execution note for any repeat: run
the importer under `docker compose exec -d` (via `vendor/bin/sail exec -T -d`), which survived a
harness-supervisor kill that terminated the polling wrapper mid-run. Target the database with
`sail exec -e DB_DATABASE=crockenhill_rehearsal` rather than editing `.env` — it is verified to
resolve correctly and removes the boot race and the crash-leaves-`.env`-wrong failure mode.

**Now the largest remaining item by effort, and drive-free:** **404 of 534** Email sources are
`held_for_review`. Adjudicating them is neither code- nor drive-blocked, nothing in HIR touches it,
and both closeout and G2's completeness half are blocked behind it — only 127 of 521 approved
identities are staged precisely because the rest are still held.

**Operator work behind the approval gate:** dispatch `production-audit.yml` for
`service-evidence-coverage` and decide §12.4 on the result. The plan's two branches are written; the
counts pick one.

**Needing the CBC drive and an operator:** §13.5 rehearsal step 1 (protect and hash the source
drives) and the OpenLP half of step 2 (populate the v2 curation fields). PR18/19 remain rehearsal
contingencies and PR20 is gated on G9. Note that the OpenLP *archives* turned out to be local at
`storage/scratch/ServiceRecords` and have been staged and validated; it is the v2 curation field
population and the video manifest that still need the drives.

**Needing infrastructure this repository does not have:** HIR8 §14 steps 1, 2, 4 and 6–11 — a
production-shaped deploy, an acquisition host, DO Spaces scratch keys, disposable restore targets and
a real signed webhook during an ingress freeze.

**Resolved 2026-08-06 (was: does the G8 prohibition block local evidence staging?).** It does not.
The prohibition is scoped to production, the status header now says so, and PR24's
`HistoricImportProductionGuard` enforces it instead of leaving it to a reading. The Email half of
§13.5 steps 2–4 remains drive-free. Its expanded manifest and F1 decision are approved; staging
still waits for F30 and F53's exact-membership implementation plus a clean rehearsal database. The decision record is in the status header; the
reasoning that forced it is that `EmailSourceAdapter` has exactly one caller, so `--import` is the
only route to staged Email evidence and a command-level prohibition would have forbidden G5.

**Superseded 2026-08-07.** That path read: "§12.4's audit dispatch, the OoS rule-set approval and
`expected_services`, then the Email half of steps 2–4." The rule set was approved on 2026-08-06, and
re-verifying the remaining three against the code showed `expected_services` is not a value anyone
can set (F1) and that the step it gates would, in the current database, measure the wrong thing (F2).
The corrected drive-free path, updated for the expanded Email roots, is:

1. ~~**Re-inventory and re-curate the expanded Email roots.**~~ **Done 2026-08-09; re-curated
   2026-08-11 (D1); corrected and re-approved 2026-08-12:** 535 entries, 534 included and 1
   excluded, 521 identities, including the three current-era entries; current hashes recorded above.
2. ~~**PR25 (F3), PR27 (F4) and PR28 (F2)**~~ **Done 2026-08-07.**
3. ~~**Decide the F1 reconciliation rule** (§19).~~ **Done 2026-08-09.** Implement PR26 together
   with F53 exact per-batch/per-source membership before G2 can be claimed.
4. ~~**Provision a clean rehearsal database**~~ **Done 2026-08-11:**
   `historic-import:provision-rehearsal-database` builds and certifies it, and
   `UnevidencedCanonicalItemGuard` was shown passing against the result for all 521 curated
   identities while still refusing against the working database (231 of 521 contaminated — the F2
   historical re-measurement against `oos-curated-2026-08-11`). ~~**Before another full Email run,
   close F63, then perform fresh full archive-v11 staging against `oos-curated-2026-08-12`**~~
   **Done 2026-08-13:** F63 closed 2026-08-12, and fresh `--fresh-parse` staging ran under HIR2 —
   report `storage/scratch/hir8-step3-import-20260813.json`, SHA-256 `27b0614a…a452a`. F61/F62 closed
   2026-08-13 in `789aafda9`, so the hymn-usage lane is no longer blocked by them; **F60 still is**,
   and it needs the converged corpus. Any further run must reprovision first — the rehearsal database
   is contaminated by a killed restage.

Step 4 depends on 3 only in the sense that the census it produces cannot be *gated* until F1 is
settled; the staging and projection themselves can proceed as soon as the replacement manifest and
clean database exist.

Two adjacent items are drive-free and worth doing alongside, though neither is on this path: extending
the §13.4 Email-side truth set beyond its single verified service (`storage/scratch/oos-truth/`,
one file; exactly one manifest entry asserts an `expected_item_count`), and Sentry, which the plans
index sequences deliberately **before** G8 because of the long unattended production apply.

Four contract facts are now permanent and constrain everything that follows:

- **Publication scripture filters are `deterministically_rebuilt`, not `portable`** (contract VERSION
  4). `SermonObserver` owns that index and re-derives it from `reference` on every save, so the
  importer cannot make bundle rows authoritative without becoming a second writer to it. The bundle
  still carries them as the evidence a round trip is compared against. Consequence for G3: a source
  sermon whose stored filters disagree with its own `reference` will not round-trip those rows —
  `HistoricMediaGraphPersisterTest::it_rebuilds_publication_scripture_filters_from_the_reference`
  pins the rule.
- **Asset fan-out is not preserved by persistence, and must not be expected to be.** One physical file
  carrying N roles becomes N production copies, because `assetDestinations()` allocates a distinct
  path per role. PR12's Bundle A round trip must compare logical hashes and role/content identity, not
  asset path counts.
- **An empty census is not evidence of convergence** (G2, closed 2026-08-05). The review-load gate
  reconciles the census against independent staged/projected counts, so the §9.4 loop cannot be
  declared converged over a corpus that was never staged. The approved manifest count lives in
  `church.historic_corpus.expected_services` and must be set from §7.3's manifest before the loop is
  claimed complete.
- **The dispatch path already consumes two §7.3 fields the OpenLP manifest cannot emit** (PR17).
  `HistoricVideoImporter::manifestItemKey()` reads `manifest_item_key` and falls back to a
  `legacy-`-prefixed hash of the source file list when it is absent; `manifest_concatenation` drives
  the concatenation branch and errors on an unrecognised value. Those are the durable job lock's
  identity inputs, so the fallback is a real weakening, not a formality: two manifest entries over the
  same files are indistinguishable under it. G1/PR5 is what removes the fallback's reason to exist.

**PR17 delivered (2026-08-06), for the record:** the per-batch storage root and resolved
driver/bucket/root identity comparison (`HistoricStagingContext`, `HistoricStagingContextRegistry`,
an extended `HistoricStagingGuard` and `HistoricStagingUrlGuard`); approved staging and manifest
identity serialised into every queued job context; the worker-executed canary proving writes stay
below the batch root (`HistoricWorkerStorageIsolationTest` with `tests/Support/HistoricStagingCanaryJob.php`);
stable per-manifest-item job identity and locks, using `MediaProcessingLog`'s unique `dedup_key`
index as the durable lock so it survives a worker crash; and the per-stage concurrency design in
`HistoricProcessingThroughput` plus `config/horizon.php`, with `HistoricProcessingFingerprint`
pinning §13.3's scope. B16 and B20 have their named tests. This closes the scheduled code; it does
**not** by itself pass G4/G5, which still need the rehearsal.

- **G1/PR5 — §7.3 OpenLP manifest fields. Schema landed 2026-08-06; data population remains.** The
  manifest is now v2 with `batch_key`, `item_key`, `source_kind`, `parse_decision`,
  `concatenation_decision`, `expected_item_count` and the decision author/time or rule version, all
  hash-covered and reconciled by the dry-run parse. What is left is operator work, not code:
  populating those fields for the real corpus against the mounted read-only drive, which folds into
  §13.1's remeasurement. The **OpenLP half** of rehearsal step 2 is unblocked in code and gated on
  that data. The **Email half** is not unblocked in code — it has no manifest at all (§7.5, PR21) —
  but it is not gated on the drive either.
- **G3/PR11 and G3/PR12 — the two different-PK round trips. Closed 2026-08-06** by
  `ChurchServiceConvergenceBundleRoundTripTest` and `HistoricProcessingResultBundleRoundTripTest`, both
  described in the audit table above. Rehearsal step 12 no longer carries the risk that local-ID
  coupling surfaces there for the first time.

  Two things the work established that are worth carrying forward. The WP0 canary now lives in
  `tests/Support/HistoricNormalOutputCanary.php` so the contract gate and the Bundle A gate are
  defined against **one** fixture — two would drift apart silently. And activating a staging context
  rewrites `filesystems.disks.*` and calls `Storage::forgetDisk()`, which discards a `Storage::fake()`
  because faking only swaps the resolved instance; any test that fakes a disk and then enters a
  context must re-establish it afterwards or it will be reading real storage.

With PR17 and PR21 merged and PR22–PR24 delivered on 2026-08-06, no scheduled implementation remained
**from that backlog**. The 2026-08-07 readiness audit added PR25–PR28, all drive-free and none larger
than `M`. **PR25, PR27 and PR28 landed the same day; only PR26 remains, and it is waiting on a
maintainer decision rather than on implementation.** With that decided, the statement holds again and
the remaining elapsed time is set by the §13.3 bulk media pass and the §9.4 residual review, exactly
as this section's sizing preamble says.

The lesson worth keeping is why they were missed. Each 2026-08-06 slice met its own acceptance, and
the audit that certified them asked exactly that question of each one. F1, F3 and F4 all live in the
*seams* — between a manifest and the gate that consumes its count, between a gate's evidence and the
sources it is meant to certify, between a plan sentence and the constant that contradicts it. A
per-slice audit cannot see them by construction, so a path-level check belongs before each gate is
claimed, not only before each PR merges.

Three entries are new or moved relative to the 2026-07-31 sequence: PR1 moves the public archive to
the front (§14); PR9 adds the review-load surface that makes §9.4's loop operable; PR16 adds the
current-era re-projection that §12.4 brought into scope. PR18's dependency is **PRs 2–17, not 1–17**:
§14 states that WP8 neither gates nor is gated by the *construction* of the import, so the independent
public-archive PR1 must not block the import's rehearsal-discovery slice. That is distinct from
closeout: PR1 is still a prerequisite for **G9**, because §15's WP9 acceptance requires the WP8
archive to work. PR1 blocks the final gate, not the rehearsal.

**PRs 5, 6, 8 and 16 touch the live weekly path** and carry the highest blast radius in the
programme — they change how services being created *this week* are ingested and projected, and PR16
rewrites results already in production. Review them accordingly; they are `L` for that reason rather
than for their diff size.

The original sequence contained no `S` or `XS` slices at all, and that was a scope signal: this is a
substantial programme however fast it is typed. Any `L` slice that cannot be reviewed around one
coherent invariant must be split before coding.

**PR25 and PR27 are the first `S` slices in the programme, and that is also a signal.** They are
small because the surrounding machinery is already built — each moves one number from the place that
cannot know it to the place that can. Their size is a property of arriving late, not of being
peripheral: PR25 gates G2, and PR27 stands between the mounted drive and an approvable OpenLP
manifest.

The correctness core cannot be silently scoped down while retaining the claim of exact,
no-reprocessing production convergence.

PR2's run/publication/section constraint ordering and stream primitives are permanent. Any temporary
destination-allocation wiring used to exercise the current graph is knowingly replaced by PR10–12;
the PR2 MySQL and streaming tests remain permanent contracts.

Every autonomous PR includes:

- **Who benefits:** a named user/operator/visitor group.
- **What observably improves:** a measurable correctness, automation, safety or visitor outcome.

Do not combine schema, projector, UI and operator execution in one PR.

## 18. Quality gates

For every code PR:

1. Write the reproducing PHPUnit test first and confirm the expected failure.
2. Run focused tests through Sail.
3. Run `vendor/bin/sail composer phpstan` at zero errors.
4. Run `vendor/bin/sail bin pint --dirty`.
5. Run `vendor/bin/sail artisan test --parallel --compact`.
6. Run Dusk for admin/public interaction changes.
7. Use Playwright only for visual baselines.

Before G5/G8 also require real MySQL constraints; source permutations; different-PK round trips;
shared media roles; large streamed assets; concurrency/rollback; zero production dispatch; exact
public-song preservation; aggregate-equal/item-different rejection; and full no-op rerun.

## 19. Maintainer decisions

Implementation may use these defaults unless changed before the named gate:

| Decision | Default | Needed by |
|---|---|---|
| Public service archive | Yes; requested outcome | WP8 |
| Expose internal evidence publicly | No | WP8 |
| Show compatible planned-only items publicly | Yes, when publication-safe | WP8 |
| Automatic finalisation | Yes, only with zero unresolved proposals | WP2 |
| Existing richer sermon conflict | Fail closed; never overwrite | WP5 |
| Existing matching asset | Verify/reuse; never overwrite different content | WP5 |
| Batch failure | Stop; resume only after new full preflight | WP6 |
| Staging retention | Until WP10 retires it on §16 evidence; never a calendar gate | WP9 |
| Residual review load | No hour budget. Automate-first loop per §9.4; classes are automated or recorded irreducible | WP3 |
| Rule-level dispositions | Permitted, with enumerated proposal IDs and a `decision_rule` rationale | WP3 |
| Value-tiering the corpus by era | Rejected 2026-08-02; import the whole corpus at full fidelity | §0 |
| Service below the §13.4 accuracy threshold | Import fully; withhold media from public listing via existing exposure attributes | WP7 |
| Bulk-pass concurrency | Establish per-stage width at calibration; re-forecast from the concurrent figure | WP7 |
| Editorial/copyright/consent policy | Deferred 2026-08-02; required before the first historic era is published, not before WP8 ships | §14.4 |
| Scope of the G8 import prohibition | Decided 2026-08-06: production only. Local staging into a rehearsal database is how G5 is reached, and `HistoricImportProductionGuard` enforces the boundary rather than the prose | WP7 |
| **What `expected_services` reconciles (F1)** | **Decided 2026-08-09.** Keep the replacement manifest's approved 521-identity set as the baseline; permit additional identities only when each is explicitly explained by `service_beyond_manifest`, and fail closed on unexplained excess. Implement with F53's exact batch/source membership certification, not a scalar count exception | WP3 / G2 / F53 |
| **Which source kinds G2 certifies (F3)** | **Implemented 2026-08-07 (PR25)** on the stated default: the stopping condition may only be claimed for source kinds actually staged, declared in `church.historic_corpus.census_source_kinds`. Email-only staging cannot claim §9.4.2's Email × OpenLP convergence | WP3 / G2 |
| **Where the OpenLP accounting is declared (F4)** | **Implemented 2026-08-07 (PR27)** on the stated default: in the approved manifest envelope at version 3, not in a class constant. §7.3 already makes the approved manifest mutation authority; the constant contradicted it | WP1 / G1 |
| **Whether the rehearsal database must be clean (F2)** | **Implemented 2026-08-07 (PR28):** yes, enforced. `--accept-unevidenced-items` is the deliberate override | WP7 / G5 |

Before G7 the maintainer explicitly accepts the corpus manifest/exclusions, every unresolved manual
decision, the §9.4 census stopping condition, maintenance/rollback timing and private report
retention. Public exposure and indexing policy for the **current era** is accepted before PR1 ships
WP8; the §14.4 policy questions for **historic eras** are accepted before those eras are published.
Neither gates the import itself.

## 20. Definition of done

- [x] Public service history ships over current-era data, safe, accessible and linked (PR1).
- [x] WP0 canary covers the complete normal graph. *(Media graph and church-service links both run
  through the real persistence path as of PR14 — see §17's G1 canary row.)*
- [x] Every source kind — Email, OpenLP and livestream acquisition — has a curation manifest in the
  one §7.3 format. *(Email closed by PR21; `OosCurationManifest`, `OpenLpCurationManifest` and
  `HistoricVideoCurationManifest`. Having a manifest is not the same as having an approved one — the
  OoS rule set and the OpenLP v2 fields are tracked in §17's audit table.)*
- [ ] The mounted source inventory is 100% accounted for by included or approved excluded items.
- [ ] The local Email inventory is 100% accounted for across the current 535-entry reconciliation
  target: 533 verbatim, 261 formatted, 259 paired, 274 verbatim-only and 2 formatted-only, including
  an explicit decision for each of the 131 files added after the old approval.
- [ ] Every included item is exact-promoted or exact-already-present; unresolved/failed count is zero.
- [ ] Calibration forecast, checkpoint ledger and actual time/cost/capacity reports reconcile.
- [ ] Bulk-pass concurrency is designed, measured and reflected in the forecast.
- [ ] Per-era content accuracy is sampled against a truth set, and eras below threshold are imported
  in full but withheld from public listing.
- [ ] Email/OpenLP/Livestream assertions are independent and immutable.
- [ ] Source lineage, matching, cardinality and anchored order are deterministic.
- [ ] Conflict-free services finalise automatically.
- [ ] The §9.4 census reaches its stopping condition over a reconciled corpus: every manifest identity
  is staged and projected at the current policy version, every service staged beyond the manifest is
  explained by a `service_beyond_manifest` flag, every proposal class is `automated` or `irreducible`
  with a reason, and no `irreducible` class would yield to a tier change. *(Reworded 2026-08-07: this
  previously said "the approved manifest count is staged", which F1 shows is not a quantity the
  manifest has.)*
- [x] The corpus-completeness evidence records coverage **per source kind**, and G2 is claimed only
  for the kinds actually staged (F3, PR25).
- [x] Staging is refused against a database holding pre-existing canonical items for the corpus's
  identities, so the census's largest classes are projector behaviour rather than import residue
  (F2, PR28). *(The guard is in place; the clean database itself is still to be provisioned.)*
- [x] The OpenLP accounting is declared in the approved manifest rather than frozen in a class
  constant (F4, PR27). *(Still open: the remeasured drive inventory that the declaration will state.)*
- [ ] The projection policy version and the processing fingerprint are disjoint, and a test proves
  re-projection dispatches no job, opens no media file and calls no provider.
- [ ] Exceptional review is complete, authorised and portable; no proposal decision is inferred
  from omission, including under rule-level dispositions.
- [ ] Bundle A is complete, portable, path-independent and streamed.
- [ ] Bundle B transports exact finalisation, proposal dispositions and `decision_rule` rationale.
- [ ] Real graph persistence passes current MySQL constraints.
- [ ] Existing publications and song usage converge without loss.
- [ ] The historic hymn reconciliation is reproducible and hash-bound against the exact converged
  corpus; all 5,759 known-service and 1,941 date-only rows have one approved disposition (F60).
- [ ] Historic hymn mutation is operation-owned, production-guarded, count/digest-verified and
  included in visibility, rollback and exact closeout evidence (F61).
- [ ] Historic hymn reruns compare stored state, resolve/link only under explicit policy and finish
  entirely no-op without double-counting canonical occurrences (F62).
- [ ] Archive plans are classified using curated scope before the fresh full archive-v11 staging
  baseline, while extra unknown-scope plans remain held (F63).
- [ ] Current-era services are re-projected after the repair, audited item-level, and B13's false
  acceptances are reversed rather than inherited.
- [ ] Dry run binds every state apply can observe/mutate.
- [ ] Local workers prove isolated per-batch staging.
- [ ] Different-PK rehearsal, rollback and no-op rerun pass.
- [ ] Production ingress budget, lossless inbound handling and split-window procedure are accepted
  and rehearsed.
- [ ] Production exact audit and no-op rerun pass; G9 closeout evidence is obtained.
- [ ] No gate in the executed programme blocked on elapsed calendar time.
- [ ] One-shots/compatibility schema retire only after their triggers.
