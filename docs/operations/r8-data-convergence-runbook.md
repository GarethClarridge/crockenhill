# R8 data convergence and one-shot retirement runbook

> **Status (revised 2026-07-29): design skeleton, not executable.**
>
> Do not run the production mutation sequence until
> [R8 Data Convergence Correctness](../plans/R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md)
> WP0–WP10 are deployed and WP11 has replaced every planned placeholder below with the implemented,
> tested command and expected output. The previous runbook re-ran OoS extraction and historic-video
> processing in production and used aggregate parity checks; that sequence is superseded and remains
> only in git history.
>
> **Production has not been mutated for this operation.**

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

[Historic Media Acquisition and Result Promotion](../plans/HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
owns local drive processing, technical media review and Bundle A. R8 owns source evidence,
projection, Bundle B and this production sequence.

## 2. Hard stops

Stop before any production write unless all are true:

- R8 WP0–WP10 and historic HM0–HM6 are deployed;
- focused, PHPStan, Pint, full-suite and required browser gates passed on the deployed commit;
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

Counts are diagnostics, not authority. In particular, “428 files” does not identify the approved
OpenLP set; the private manifest does.

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
| OpenLP | manifest hash; 536 raw / 105 duplicates / 3 exclusions / 428 includes / 7 aliases |
| OoS | source archive hash, assertion bundle hash and extractor fingerprint |
| Legacy MP3 | bundle/asset hashes and selected natural identities |
| Historic media | source corpus manifest, Bundle A hash and processing fingerprint |
| Reviewed convergence | Bundle B hash, evidence-set hash and exact canonical hash |
| Production allowlist | natural identity plus reason for every intentional production-only row |
| Backups | backup identifier and restore verification |
| Results | every dry-run/apply/idempotency report and exact final manifest |

Never put raw email bodies, secrets, credentials or real user IDs in the ledger.

## 5. Local assembly and review

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
2. Validate the private curation manifest:
   - 536 raw artifacts;
   - 105 byte-identical duplicates;
   - 3 explicit exclusions;
   - 428 includes;
   - 7 explicit corrected aliases.
3. Reject extra/missing files, path traversal, hash mismatch, duplicate logical services and
   contradictory aliases.
4. Dry-run and record the plan hash.
5. Apply normalized OpenLP source records/assertions locally.
6. Rerun and require no writes.

Manual copied/deleted/renamed “curated directories” are not authority.

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

### 5.6 Final combined review and Bundle B

Only after Email, OpenLP and Livestream evidence is complete:

1. run the deterministic projector;
2. resolve every multi-source conflict and planned-only/observed-only anomaly;
3. complete one final Manual canonical review per affected service;
4. require zero pending/stale proposals and unexplained review states;
5. export Bundle B and the exact ordered local manifest;
6. verify Bundle A/B source, evidence and pre-review hashes agree;
7. rerun and require stable hashes/no writes.

Do not treat earlier technical media review as final canonical review.

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

## 7. Production apply

The implemented WP11 runbook must replace each generic step with the tested command and expected
output.

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
- OpenLP accounting is exactly 536/105/3/428/7;
- Bundle A contains every required normal-pipeline output;
- Bundle A's evidence reproduces Bundle B's reviewed base;
- every multi-source service has a completed Manual review session;
- zero actionable archive emails remain;
- zero pending/stale proposals remain;
- every planned-only/observed-only anomaly is adjudicated;
- exact local/production manifests match, except recorded accepted differences;
- public song usage has zero unapproved loss;
- every source/bundle rerun is no-op;
- no unexpected production extraction/media-processing call occurred;
- production health, public sermon, song and members-only children's-talk smoke checks pass;
- backup, ledger and private hashes are retained;
- staging cleanup is scheduled after the rollback window.

Only then update remainder R8, delete commands whose docblock triggers are satisfied and archive
this runbook with the two plans.
