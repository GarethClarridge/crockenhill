# R8 data convergence and one-shot retirement runbook

> **Status (revised 2026-08-14): command surface implemented, but this runbook is still not
> executable as written; rehearsal and production execution have not started.**
>
> **What the 2026-08-14 revision changed.** Three instructions below were not merely stale, they
> were wrong against the deployed code, and two of them would have caused an operator following this
> document to fail a gate:
>
> - the OpenLP accounting `536/105/3/428/7` was hard-coded in two places and is wrong on three
>   figures. Counts are no longer stated here at all; the approved manifest's own `expected_counts`
>   is the authority. See §4 and §5.3.
> - "complete one final Manual canonical review per affected service" and "every multi-source
>   service has a completed Manual review session" **contradict the auditor**. A service finalised
>   as `automatic` must carry *no* completed human review, and
>   `ChurchServiceConvergenceAuditor` asserts exactly that. Following the old instruction would have
>   failed the audit on every automatically finalised service. See §5.6 and §10.
> - the apply example omitted `--operation-id` and `--expires-at`, both of which
>   `service-tracking:converge-historic-service --apply` now refuses to run without. See §7.
>
> It also adds §5.0, the source-acquisition sequence, which became executable on 2026-08-14 (F66),
> and §11, which names the complete current command surface so the remaining gaps in this document
> are visible rather than silent.
>
> The original WP0–WP10 and WP11 export/convergence/audit command surfaces exist on `master`, but
> the 2026-07-31 readiness audit found release-blocking defects. Do not run the production mutation
> sequence until the remediation plan reaches Gate G8. In particular, do not treat an individual
> command's successful dry run as approval for the production maintenance window.
>
> The complete-operation authority is the
> [Historic Archive Final Import Readiness Plan](../plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md).
> Its supporting implementation contracts live in
> [Historic Archive Import Readiness Remediation](../plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md).
> [R8 Data Convergence Correctness](../archived-plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md)
> is retained as a decision/prior-art record. The previous runbook re-ran OoS extraction and
> historic-video processing in production and used aggregate parity checks; that sequence remains
> superseded in git history.
>
> **Production has not been mutated for this operation.**
>
> This remains the intended location of the sole production operator runbook, but it does not
> become production authority until the final-readiness plan's replacement sequence is written and
> executed verbatim in the required rehearsal. References below to R8/HM work-package numbers are
> retained as historical decision context, not independent release gates.

This runbook closes R8 items 2.4 and 2.6 in the
[July 2026 simplification remainder plan](../plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md).
It coordinates:

- song catalogue and legacy `play_date` convergence;
- media identity backfill;
- manifest-gated OpenLP evidence;
- portable historic OoS assertions;
- legacy MP3 create-only promotion;
- historic Bundle A media results;
- Bundle B reviewed Email/OpenLP/Livestream convergence;
- exact closeout before deleting spent one-shot commands.

## 1. Authority and ownership

| Domain | Local authority | Production action |
|---|---|---|
| Song catalogue | Approved checksummed OpenLP songs SQLite | Deterministic catalogue sync |
| OpenLP services | Private curation manifest over the complete raw archive | Import exact normalized assertions |
| Historic OoS email | Validated local assertion bundle | Import assertions; no extractor call |
| Historic recordings | Current pipeline result in private staging | Apply Bundle A; no media/AI jobs |
| Canonical services | Final combined local Email/OpenLP/Livestream review | Apply Bundle B only on exact base hash |
| Legacy MP3 sermons | Existing verified create-only bundle | Promote rows/assets without reprocessing |
| Legacy song usage | Production `play_date` source identities | Import with zero-loss audit |
| Runtime/auth state | Production | Never copy users, tokens, sessions or raw private email bodies |

[Historic Media Acquisition and Result Promotion](../archived-plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
owns local drive processing, technical media review and Bundle A. R8 owns source evidence,
projection, Bundle B and this production sequence.

## 2. Hard stops

Stop before any production write unless all are true:

- R8 WP0–WP10, historic HM0–HM6 and safety packages HIR0–HIR7 are deployed;
- focused, PHPStan, Pint, full-suite and required browser gates passed on the deployed commit;
- `historic-import:verify-source-acquisition` passed against the exact two protected copies, and its
  custody artifact is version 2 — version 1 artifacts were signed against a gate that never looked
  at the disk and cannot satisfy G5/G7 (HIR4);
- an operation exists from `historic-import:prepare-operation`, and its id and expiry are the ones
  the approved dry runs printed;
- the production database backup and restore procedure was tested;
- a private non-served staging root exists for this exact run;
- source, OpenLP manifest, OoS, legacy MP3, Bundle A and Bundle B hashes are recorded;
- every importer dry run emits the expected plan hash;
- Bundle A readiness proves the complete normal-pipeline graph and settled fan-outs;
- Bundle A and Bundle B share the expected evidence/source/processing hashes;
- explicit historic date/service identity is present for every recording;
- processing/import ingress is blocked, accepted work is drained, and affected queues have no
  queued, reserved or delayed jobs and no live batches;
- affected failed jobs are snapshotted and explicitly adjudicated before Horizon is paused;
- the production command configuration disables network extraction and historic media processing;
- the exact local manifest and explicit production-only allowlist are present;
- the operator has a written abort/rollback decision for conflicts.

Counts are diagnostics, not authority. No file count identifies the approved OpenLP set; the
private manifest does. This runbook deliberately states no fixed count anywhere — a number written
here is a number that can rot, and the 2026-08-14 revision found exactly that in three places.

## 3. Private staging

Create one run-specific private staging root with mode `0700`; files use `0600`.

Required logical layout:

```text
<private-run-root>/
  source-manifests/
  song-catalogue/
  openlp/
  oos/
  legacy-sermons/
  bundle-a/
    manifest/
    assets/
  bundle-b/
  reports/
```

Requirements:

- the root is not on the public, sermon, transcript or CDN-served disks;
- reject symlinks, path traversal, absolute paths in bundles and files outside the run root;
- use exact manifest entries, never a recursive wildcard as mutation authority;
- do not use `rsync --delete` against a shared or unresolved root;
- verify size and SHA-256 after staging and again immediately before apply;
- retain the root through closeout and the rollback window;
- clean only paths named by the retained run manifest.

Local historic processing uses its own isolated private storage root. It must not write production's
canonical asset keys.

## 4. Required private ledger

Maintain one private ledger outside git:

| Entry | Required identity |
|---|---|
| Deployment | git commit and image/release identifier |
| Song catalogue | source file SHA-256 and sync plan hash |
| OpenLP | manifest hash, `batch_key`, and the manifest's own `expected_counts` block copied verbatim |
| OoS | source archive hash, assertion bundle hash and extractor fingerprint |
| Legacy MP3 | bundle/asset hashes and selected natural identities |
| Historic media | source corpus manifest, Bundle A hash and processing fingerprint |
| Reviewed convergence | Bundle B hash, evidence-set hash and exact canonical hash |
| Production allowlist | natural identity plus reason for every intentional production-only row |
| Backups | backup identifier and restore verification |
| Results | every dry-run/apply/idempotency report and exact final manifest |

Never put raw email bodies, secrets, credentials or real user IDs in the ledger.

## 5. Local assembly and review

### 5.0 Source acquisition and custody (drive-day)

Everything else in §5 assumes two protected copies already exist. This is how they come to exist,
and it is the only part of the operation where a mistake means re-mounting the original. Nothing
here touches the database.

The operator half — the written acquisition procedure, the named custodian, malware tooling and the
physical device identification — is workstream D of the final-readiness plan and is **not yet
written**. The tooling half became executable on 2026-08-14 (F66). Do not run these commands from
this section alone until workstream D's procedure exists.

1. Mount the original read-only. Identify the physical device and filesystem, and record health and
   read errors. Never point any importer at the original.
2. Make two protected copies whose **failure domains differ** — not two directories on one disk.
   Both commands below refuse copies that share a mount source and device, and refuse a copy a write
   probe can still write to.
3. Malware-scan in isolation and retain the checksummed report.
4. Draft the whole-tree disposition worksheet. This is read-only against the copy and decides
   nothing:

```bash
vendor/bin/sail artisan historic-import:draft-source-dispositions \
  "<evidence-copy-absolute-path>" \
  --worksheet="acquisition/<batch-key>/dispositions.json"
```

   It fails, naming the paths, on read errors, dangling or escaping symlinks and case/Unicode
   collisions. Each of those is a source problem to fix before continuing, not a warning to note.

5. Adjudicate the worksheet: set an explicit `disposition` on **every** path, and write one reason
   per disposition class in `disposition_reasons`. Both are required — capture refuses an undecided
   path and refuses a disposition class with no written reason. The custody artifact has no room for
   per-path reasons, so the worksheet is retained as evidence alongside it.
6. Write the facts file: `batch_key`, `key_id`, `physical_source` (device/volume identity,
   filesystem, health-report digest, zero read errors, and the five proven mount facts),
   `malware_scan`, `retention`, a `storage_identity` per copy, and the planned `capacity_plan`.
   **The capacity plan must not declare `source_bytes`** — that total is measured from the working
   copy, and capture refuses a hand-typed one.
7. Capture and sign the custody artifact:

```bash
vendor/bin/sail artisan historic-import:capture-source-acquisition \
  "<private-absolute-path>/dispositions.json" \
  "<private-absolute-path>/acquisition-facts.json" \
  "<evidence-copy-absolute-path>" \
  "<working-copy-absolute-path>" \
  --custody="acquisition/<batch-key>/custody.json"
```

   Record the printed worksheet SHA-256 in the ledger next to the custody path. Any extended
   attribute reported as unclaimable is a signal that one copy was made with the wrong tool —
   investigate before proceeding.

8. Verify the acquisition. This is the gate; the two commands above only produce its input:

```bash
vendor/bin/sail artisan historic-import:verify-source-acquisition \
  "<private-absolute-path>/custody.json" \
  "<evidence-copy-absolute-path>" \
  "<working-copy-absolute-path>" \
  --report="acquisition/<batch-key>/acquisition-report.json"
```

All four artifacts are create-once below `storage/app/private` at mode `0600`. A re-run needs a new
path, which is deliberate: an acquisition you can silently overwrite is not custody.

**Abort conditions:** any read error, any collision, any unprotected copy, any shared failure
domain, a capacity plan that cannot cover source plus temporary plus staging plus rollback plus the
approved contingency, or a worksheet whose path set no longer matches the copies. Each aborts with
zero effects and no artifact written.

**Retention:** keep the original and both protected copies read-only until exact production
acceptance plus the rollback window, and record who may later delete or return them.

### 5.1 Preserve inputs

1. Preserve the CBC drive and source archive.
2. Preserve the local database before the operation and after each media batch.
3. Verify source hashes and private manifests.
4. Verify all configured transcription/analysis services are the intended non-mock implementations.
5. Record code/model/prompt/schema/config fingerprints.

### 5.2 Song catalogue

1. Dry-run the approved song catalogue sync.
2. Resolve every collision or rejected identity.
3. Apply locally.
4. Rerun and require no changes.
5. Preserve the source and plan hashes.

### 5.3 OpenLP evidence

1. Inventory the complete raw archive recursively.
2. Validate the private curation manifest against the archive. **Take every count from the
   manifest's own `expected_counts` block and record it in the ledger; do not carry a count in from
   this document or from a previous run.** The importer reconciles raw artifacts, byte-identical
   duplicates, explicit exclusions, includes and corrected aliases against that block and refuses a
   mismatch.
3. Reject extra/missing files, path traversal, hash mismatch, duplicate logical services and
   contradictory aliases.
4. Dry-run and record the plan hash.
5. Apply normalized OpenLP source records/assertions locally.
6. Rerun and require no writes.

Manual copied/deleted/renamed “curated directories” are not authority.

> **Why no numbers appear here.** This runbook previously hard-coded `536/105/3/428/7`. On
> 2026-08-14 the approved manifest (`crockenhill-openlp-curation` v3, batch
> `openlp-curated-2026-08-13`) declared a different exclusion count, a different include count and a
> different alias count. An operator reconciling against this document rather than against the
> manifest would have rejected a valid archive. The manifest is versioned and hashed; this sentence
> is not.

### 5.4 Historic OoS evidence

1. Extract and structurally validate the archive locally.
2. Hold invalid/ambiguous entries for review.
3. Export the versioned normalized assertion bundle.
4. Verify entry/source/fingerprint hashes.
5. Import the bundle into a clean test database with different primary keys.
6. Require zero extractor calls and an entirely no-op second import.

### 5.5 Historic recordings and Bundle A

Follow the historic-media plan:

1. process approved work items through the current livestream pipeline;
2. pass manifest date/service as explicit overrides;
3. use isolated private output storage;
4. wait for the main chain and all fan-out outputs;
5. run historic asset and normal-output readiness audits;
6. complete technical media/publication review;
7. ingest/check the final Livestream assertions;
8. export Bundle A and verify every row/object relationship and hash;
9. cross-database round-trip Bundle A with different primary keys;
10. rerun export/import and require stable hash/no writes.

Bundle A must include the complete durable graph: sermons/children's talks, processing log/steps,
segments, sections, accepted Livestream `summary`/`notices`/`chapter_markers` content, permanent
publications, `song_videos`, durable artifacts and assets. It does not carry canonical review
state.

#### The manifest is immutable for the life of a batch

The approved manifest's hash determines the plan hash, and the plan hash names the batch's private
storage root (`historic-batches/<plan-hash>`) and every manifest item's dispatch identity. Amending
the manifest — adding a discovered recording, correcting a date, changing an exclusion — changes all
three. That is by design: a different manifest is a different approved batch.

Consequences to plan for before starting a bulk pass:

- Recordings already processed under the superseded manifest keep their old batch root. They are
  **not** reprocessed, and the existence checks skip them, but they can no longer be exported in the
  same Bundle A as anything processed afterwards.
- `historic:export-processing-results` refuses a mixed selection and names which runs belong to which
  plan hash. Export each plan hash as its own Bundle A, with its own `--batch-hash`, and converge
  them as separate pairs.
- So: settle the manifest before the pass. If an amendment is genuinely unavoidable mid-pass, export
  the completed portion as its own Bundle A **first**, while its runs are still the only ones in the
  selection, then amend and start the next batch.

This is the §13.1 completion gate doing its job — `discovered = included + excluded` is a statement
about one approved manifest, not about whatever the drive happened to hold on a given day.

### 5.6 Final combined review and Bundle B

Only after Email, OpenLP and Livestream evidence is complete:

1. run the deterministic projector;
2. resolve every multi-source conflict and planned-only/observed-only anomaly;
3. complete a final Manual canonical review **only on the services that require one** — see the
   warning below;
4. require zero pending/stale proposals and unexplained review states;
5. export Bundle B and the exact ordered local manifest;
6. verify Bundle A/B source, evidence and pre-review hashes agree;
7. rerun and require stable hashes/no writes.

Do not treat earlier technical media review as final canonical review.

> **Do not review every multi-source service.** `ChurchServiceCanonicalFinalization` has two values,
> and the auditor treats them as opposites. For a service exported as `manual`,
> `ChurchServiceConvergenceAuditor` requires the named `review_uuid`, a completed session, a matching
> `resulting_canonical_hash` and `reviewed_canonical_revision == canonical_revision`. For a service
> exported as `automatic` it asserts that **no** review ever completed against it and that
> `reviewed_canonical_revision` is null.
>
> So a completed human review on an automatically finalised service is not harmless diligence — it
> is an audit failure, and one that cannot be undone by reviewing more. The bundle exporter records
> which services are which; work that list, not "every multi-source service". This runbook's
> previous instruction predated automatic finalisation.

### 5.7 Legacy MP3 and `play_date`

Retain the existing create-only, natural-key, asset-verified legacy sermon bundle flow and the
production-derived `play_date` import. Before production:

- classify every local-only sermon candidate;
- require preacher/scripture/asset provenance;
- resolve duplicate natural identities;
- preserve the production song-usage baseline;
- prove all dry runs are idempotent.

The abandoned `storage/app/sermon-patch.sql` must never be applied or regenerated.

## 6. Production preflight

1. Deploy the exact approved commit.
2. Verify migrations and application health.
3. Confirm backup/restore evidence.
4. Block new processing/import ingress and drain all accepted work.
5. Prove the affected queues have no queued, reserved or delayed jobs and no live batches.
6. Snapshot and explicitly adjudicate affected failed jobs, then pause Horizon.
7. Create and permission the private run staging root.
8. Stage exact manifests, sources, Bundle A, Bundle B and assets.
9. Verify every staged hash and fingerprint.
10. Run every importer in dry-run mode.
11. Record all plan hashes and classifications.
12. Compare them with the approved local ledger.
13. Abort on unexpected `create`, `conflict`, extractor/media call or field difference.

No production apply begins until the entire operation—not merely the first batch—passes preflight.

### 6.1 Implemented bundle commands

All paths below must be private and must refer to the exact retained rehearsal artifacts.
Substitute the recorded values; do not reconstruct hashes during the production window.

Neither export takes a `--fingerprint`. Bundle A reads the processing fingerprint back out of the
runs it is exporting, so the bundle records the configuration that actually produced the media rather
than whatever was typed at the prompt. Bundle B then reads both the media-bundle hash and that same
fingerprint out of Bundle A. Convergence and the auditor refuse any pair whose fingerprints do not
hash-match, so there is deliberately no way to supply them independently.

`--batch-hash` must equal the approved manifest hash that authorised the runs' staging context; the
exporter refuses otherwise.

Export Bundle A after readiness and technical review pass:

```bash
vendor/bin/sail artisan historic:export-processing-results \
  --processing-ids="<comma-separated-processing-uuids>" \
  --batch-hash="<approved-manifest-sha256>" \
  --output="<private-absolute-path>/bundle-a.json"
```

Export Bundle B only after final combined canonical review, pointing it at the exact Bundle A file
written above:

```bash
vendor/bin/sail artisan service-tracking:export-convergence \
  --service-ids="<comma-separated-reviewed-service-ids>" \
  --batch-hash="<approved-manifest-sha256>" \
  --media-bundle="<private-absolute-path>/bundle-a.json" \
  --output="<private-absolute-path>/bundle-b.json"
```

For each matching Bundle A/Bundle B service index, validate the pair and record the printed plan
hash. This command performs no database or asset writes without `--apply`:

```bash
vendor/bin/sail artisan service-tracking:converge-historic-service \
  "<private-absolute-path>/bundle-a.json" \
  "<private-absolute-path>/bundle-b.json" \
  --media-index=<zero-based-index> \
  --convergence-index=<zero-based-index>
```

The plan hash is bound to both exact bundle hashes and both service indexes. Any artifact or index
change requires a fresh dry run and approval.

**Omitting both indexes is not a narrower run — it is `--all`.** The command treats a missing
`--media-index` *and* `--convergence-index` as a whole-batch plan over every matching service. Supply
both or neither deliberately; a half-typed invocation is refused, but a fully untyped one is the
largest possible operation.

The dry run prints the operation id, the plan hash, the expiry **and the exact apply invocation to
copy**. Use that printed line rather than assembling §7's template by hand: it already carries the
three bound values, and hand-assembly is where a wrong index or a renewed expiry gets introduced.

## 7. Production apply

1. Apply the song catalogue sync.
2. Apply legacy `play_date` and media-identity convergence where their individual gates pass.
3. Import OpenLP normalized evidence through the manifest-gated importer.
4. Import OoS normalized evidence with network extraction disabled.
5. Promote approved legacy MP3 bundles.
6. For each affected natural service identity, run the convergence orchestrator in one outer
   database transaction:
   - allocate production identities and copy/verify assets into production-canonical paths;
   - create/remap the complete Bundle A media graph;
   - ingest Livestream assertions and service content;
   - run the projector over complete Email/OpenLP/Livestream evidence;
   - require the pre-review hash to equal Bundle B's reviewed local base;
   - apply Bundle B's Manual revision and review decisions;
   - link sections/publications through assertion and stable bundle identities;
   - run strict per-service media, canonical and public-song-usage database gates;
   - dispatch no jobs/external calls and emit events only after the outer commit.
7. Run cross-service exact media/canonical audits.
8. Rerun every source and bundle; require all `already_present`/no-op.

For each approved service, apply only the exact dry-run token. **All three of `--operation-id`,
`--expires-at` and `--plan-hash` are required** — the command refuses `--apply` without them, and
each must be the value the approved dry run printed, not a value composed at the prompt:

```bash
vendor/bin/sail artisan service-tracking:converge-historic-service \
  "<private-absolute-path>/bundle-a.json" \
  "<private-absolute-path>/bundle-b.json" \
  --media-index=<zero-based-index> \
  --convergence-index=<zero-based-index> \
  --operation-id="<operation-id-from-the-approved-dry-run>" \
  --expires-at="<iso-8601-expiry-from-the-approved-dry-run>" \
  --apply \
  --plan-hash="<recorded-dry-run-plan-hash>"
```

The dry run prints all three; copy them from its output. An expired plan is refused rather than
renewed — a plan that has outlived its window has outlived the state it was approved against, so the
remedy is a fresh dry run and a fresh approval, never a later `--expires-at`.

`--all` applies every matching service in manifest order under one operation, and `--resume`
continues a run from the private operation ledger after an interruption. Neither is a substitute for
the per-service dry run: the plan hash still binds both bundle hashes and both service indexes.

Expected success output identifies the natural service identity, canonical hash and created-asset
count. Stop on the first non-zero exit. Do not generate a replacement token during an apply run.

A failure at any per-service step rolls back all database writes for that service and removes only
final assets created by that attempt from its exact manifest. Never overwrite different existing
content.

## 8. Exact verification

Aggregate counts do not prove convergence. The final audit compares, per natural service identity:

- ordered canonical item identities;
- type, title, reference and song canonical identity;
- service summary, notices and chapter markers;
- planned/observed occurrence state;
- contributing source assertion hashes and field authority;
- Manual revision/review decision;
- processing UUID/source/fingerprint;
- sermon and children’s-talk identities;
- segments, sections and their remapped relationships;
- published `song_videos`;
- durable artifacts and asset hashes;
- public song-usage eligibility.

The audit exits non-zero for:

- missing, extra or reordered items;
- aggregate-equal but item-different services;
- pending/stale proposals or unexplained review state;
- missing/extra media rows or assets;
- local IDs, staging paths or runtime correlation in production durable state;
- any unapproved local/production difference;
- any unexpected production extraction, AI or video-processing call.

Intentional production-only fixtures/live services require the private natural-key allowlist and
reason. Counts are never an allowlist.

Run the exact Bundle B comparison after all services apply and again after the required no-op
rerun:

```bash
vendor/bin/sail artisan service-tracking:audit-convergence \
  "<private-absolute-path>/bundle-b.json" \
  --media-bundle="<private-absolute-path>/bundle-a.json" \
  --operation-id="<operation-id>" \
  --report="r8/<run-identity>/convergence-audit.json"
```

Pass `--media-bundle` so the media graph and asset equality are audited in the same pass rather than
left to a separate gate, and `--operation-id` so the result is recorded as a closeout measurement
against the operation instead of an unbound report. Afterwards, prove the retained report and its
ledger binding rather than trusting the exit code you saw at the time:

```bash
vendor/bin/sail artisan service-tracking:audit-convergence \
  "<private-absolute-path>/bundle-b.json" \
  --operation-id="<operation-id>" \
  --verify-closeout
```

The command is read-only. It exits non-zero and emits dotted field paths for canonical item/order
differences, service-content drift, evidence-set drift, missing/incomplete review sessions,
pending/stale proposals, `needs_review`, or reviewed-revision mismatch. Bundle A readiness,
media-graph and asset equality remain separate hard gates owned by the historic-processing
inventory/importer; a passing Bundle B audit does not replace them.

## 9. Rollback

Before resuming ingress:

- stop on the first failed hard gate;
- preserve reports, manifests, hashes and application logs;
- roll back the affected service transaction;
- clean only newly-created final assets named by that attempt's manifest;
- leave verified private staging intact;
- restore the database when a committed cross-service invariant cannot be repaired safely;
- rerun the exact audit after restore.

Object storage and database rollback are separate. Do not delete a final object merely because its
row rolled back; first prove it was created by this attempt and is not referenced by an existing
record.

## 10. Closeout

Do not resume normal operation or delete one-shot commands until:

- every approved source is applied or explicitly excluded;
- OpenLP accounting reconciles exactly against the approved manifest's `expected_counts`;
- Bundle A contains every required normal-pipeline output;
- Bundle A's evidence reproduces Bundle B's reviewed base;
- every service finalised as `manual` has a completed review session whose
  `resulting_canonical_hash` and `reviewed_canonical_revision` match, and every service finalised as
  `automatic` has **no** completed review session and a null `reviewed_canonical_revision`;
- zero actionable archive emails remain, and every deferred inbound email reached the terminal
  `processed` state with a non-null `processed_at` — `dispatched` is a queue handoff, not an outcome
  (HIR6);
- zero pending/stale proposals remain;
- every planned-only/observed-only anomaly is adjudicated;
- exact local/production manifests match, except recorded accepted differences;
- public song usage has zero unapproved loss;
- every source/bundle rerun is no-op;
- no unexpected production extraction/media-processing call occurred;
- production health, public sermon, song and members-only children's-talk smoke checks pass;
- backup, ledger and private hashes are retained;
- staging cleanup is scheduled after the rollback window.

Closeout is a command, not a judgement. Verify and retain the operational evidence, then the
recovery evidence, rather than asserting either:

```bash
vendor/bin/sail artisan historic-import:verify-operational-closeout \
  "<operation-id>" "<private-absolute-path>/operational-evidence.json"

vendor/bin/sail artisan historic-import:verify-recovery \
  "<operation-id>" "<private-absolute-path>/recovery-evidence.json" \
  --artifact="<artifact-id>=<verification-path>"
```

Both artifacts are signed and version 2. Version 1 recovery evidence cannot satisfy the repaired
gate (HIR5), and the resolver refuses two artifact ids that resolve to one inode — an on-host and an
off-host restore must be two objects.

**Release is a separate authorised act after closeout, not part of the import.** Imported sermons
and song videos land quarantined on the private disk; publishing them is
`historic-import:release-batch` against its own signed authorisation, gated on the operation being
`Complete`. Run it with `--dry-run` first, which verifies everything and publishes nothing:

```bash
vendor/bin/sail artisan historic-import:release-batch \
  "<private-absolute-path>/release-authorisation.json" --dry-run
```

Under D10 one person may hold the release-owner, verifier and rollback-owner roles; the names are
accountability fields and are not compared for uniqueness.

Only then update remainder R8, delete commands whose docblock triggers are satisfied and archive
this runbook with the two plans.

## 11. Command surface and what this runbook still does not cover

The deployed surface is larger than the sequence above. This table exists so a missing step is
visible rather than silent; **a command with no runbook step is not approved for the production
window**, and assembling one at the prompt is what §C7 of the final-readiness plan forbids.

| Command | Covered here | Note |
|---|---|---|
| `historic-import:draft-source-dispositions` | §5.0 | New 2026-08-14 (F66) |
| `historic-import:capture-source-acquisition` | §5.0 | New 2026-08-14 (F66) |
| `historic-import:verify-source-acquisition` | §5.0 | The acquisition gate |
| `service-tracking:sync-songs` | §5.2 | |
| `service-tracking:import-openlp-services` | §5.3 | |
| `oos:import-archive` | §5.4 | |
| `sermons:import-historic-videos` | §5.5 | Needs a video curation manifest **that nothing builds** |
| `historic:export-processing-results` | §6.1 | Bundle A |
| `service-tracking:export-convergence` | §6.1 | Bundle B |
| `service-tracking:converge-historic-service` | §6.1, §7 | |
| `service-tracking:audit-convergence` | §8, §10 | |
| `historic-import:verify-operational-closeout` | §10 | |
| `historic-import:verify-recovery` | §10 | |
| `historic-import:release-batch` | §10 | Post-closeout, separately authorised |
| `historic-import:prepare-operation` | **no step** | Produces the operation id and expiry §7 requires |
| `historic-import:provision-rehearsal-database` | **no step** | Rehearsal only; reprovision before every staging run |
| `historic-import:enrich-scripture-passages` | **no step** | HIR3: outcomes must be settled before Bundle A export |
| `historic-import:row-manifest` | **no step** | Exact row membership for a disposable restore |
| `audit:historic-import-assets` | **no step** | Retained-artifact audit |
| `services:generate-corpus-membership` | **no step** | F53/PR26 exact per-batch membership certification |
| `service-tracking:promotion-budget` | **no step** | F58 production-window measurement |
| `service-tracking:import-historic-song-usage-reports` | **no step** | Hymn lane, F61/F62 |
| `service-tracking:reproject-current-era` | **no step** | Current-era re-projection after the evidence back-fill |

### What still blocks approval of this document

Correcting the errors above does not make this runbook production authority. Outstanding, in the
order they gate:

1. **No per-step expected output, exit code, evidence captured, abort condition or rollback action.**
   §C2 requires all five for every step; most steps here have prose only.
2. **No T-minus preparation, ingress/admin/deploy freeze, queue and scheduler snapshot, or
   per-checkpoint admission sequence.**
3. **No decision tree** for source or hash mismatch, low disk, timeout or live work, failed job,
   provider rate or cost breach, database or object failure, expired token, plan drift, concurrent
   edit and missed deadline. Which cases stop, resume, compensate or restore must be written down
   before the window, never improvised inside it.
4. **No measured timings.** §E2's stratified calibration has not run, so no step here carries a
   duration and the window cannot be budgeted.
5. **The eight commands above with no step.**
6. **Never rehearsed verbatim.** D10 removed the second-operator walkthrough; the verbatim rehearsal
   is what replaces it, and it is the stronger half — a reader can miss a wrong command, executing
   the document as written cannot. Until that rehearsal happens with timings and retained reports,
   this document is a draft.
