# Historic Media Acquisition and Result Promotion Plan

> **Archived 2026-08-08 — superseded as an executable plan by**
> [Historic Archive Import Readiness Remediation](../plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md),
> with final go/no-go authority now held by the
> [Historic Archive Final Import Readiness Plan](../plans/HISTORIC-ARCHIVE-FINAL-IMPORT-READINESS-2026-08-07.md).
> Keep this file only as the detailed Bundle A and media-acquisition decision record; do not execute
> its HM work-package sequence.

> **Status (revised 2026-07-29): Stage A retention prerequisites are implemented; the remaining
> acquisition and promotion work is not started. Production promotion is blocked on
> [R8 Data Convergence Correctness](R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md) WP0–WP10 and its
> WP11 rehearsal.**
>
> **Superseded as an implementation plan (2026-07-31):** the audit found release-blocking defects in
> the normal-output contract, portable graph, asset roles, persistence order, streaming and exact
> verification. [Historic Archive Import Readiness Remediation](../plans/HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md)
> is now the single implementation sequence and production gate. This document is retained only as
> a decision record and detailed Bundle A prior art; do not execute its HM work-package numbering.
>
> This revision replaces the original Stage B design. Git history preserves that design; it is not
> an implementation reference. In particular, do not replay the legacy livestream projector in
> production, transplant item links by title/position, copy review columns or local user IDs, write
> local output directly into production's canonical asset namespace, or claim that only two foreign
> keys need remapping.
>
> **Goal:** make production indistinguishable, at its durable domain boundaries, from the state it
> would reach if every historic recording had been uploaded through the current livestream pipeline
> alongside its Email and OpenLP evidence.
>
> **Meaning of “as if uploaded normally”:** run the current media pipeline once, locally, under a
> pinned code/model/prompt/config fingerprint; review the result; transport its complete durable
> outcome; and let production run only deterministic import, projection and validation. It does not
> mean re-running FFmpeg, transcription, LLM analysis, notifications or cleanup in production.
>
> **Maintainer decisions retained:** include sermons and children's talks; tolerate zero loss of
> currently-public song usage; review locally; keep transcription-grade full-service audio; use
> current production storage conventions after promotion.

**Who benefits:** the operator processing the CBC drive, visitors relying on sermon and song
records, and future maintainers who need a complete, explainable processing history.

**What observably improves:** every selected recording is processed once through today's pipeline;
production receives the same sermon, sections, published song videos, artifacts and combined
service decision; Email, OpenLP and Livestream complement one another; and rerunning the promotion
changes nothing.

---

## 1. Ownership boundary

This plan owns the **media side**:

- drive inventory and source-file decisions;
- dispatch through the normal livestream ingress;
- durable processing outputs and technical media review;
- isolated staging storage;
- the processing-result bundle, asset transfer and media-graph remapping;
- media-specific readiness, integrity, idempotency and rollback checks.

[R8 Data Convergence Correctness](R8-DATA-CONVERGENCE-CORRECTNESS-2026-07-29.md) owns the
**service-convergence side**:

- Email, OpenLP, Livestream and Manual source records/assertions;
- arrival-order-independent projection;
- field authority and occurrence state;
- multi-source proposals and final canonical review;
- the reviewed-convergence bundle;
- final production ordering, exact parity and closeout.

The seam is the Livestream source revision emitted from the locally completed processing run. This
plan transports it with the media result; R8 ingests it through the same normalized evidence action
as a live run.

## 2. Non-negotiable invariants

1. Historic recordings enter through `UnifiedMediaProcessor::process(type: livestream)`.
2. The importer passes the manifest's date and service as explicit overrides; filenames are
   provenance, not authority.
3. Local media output is written below an isolated private staging bucket/root, never production's
   canonical object keys.
4. The exported processing fingerprint pins the git commit, pipeline/bundle versions,
   transcription and analysis services/models, prompt/schema hashes, relevant configuration,
   source hashes and concatenation decision.
5. Export waits until the main chain and every fan-out job have settled.
6. The bundle includes every durable row and object needed by current read, retry, audit and public
   paths. Omissions must be declared intentional and tested.
7. Database IDs never cross as identity. Every ID-bearing relation is expressed by a stable key and
   remapped on import.
8. `processing_metadata` is serialized through an allowlist. Unknown ID-bearing or local-path
   content blocks export.
9. Production imports the processing result without dispatching media jobs or external calls.
10. Canonical service items and review state are created only by R8's projector and Manual revision,
    never copied or independently reconstructed here.
11. Every confirmed song section links to the canonical item selected from its Livestream assertion.
12. Public song usage loses no previously qualifying occurrence.
13. An identical second import is entirely no-op.

## 3. What is already implemented

The following live-pipeline improvements landed on 2026-07-25 and were corrected on 2026-07-26:

- normalized full-service transcripts survive on the transcript disk;
- raw verbose transcription responses, including word timing where available, are durable;
- source-file and concatenation provenance is recorded before dispatch;
- RMS output is durable and resolvable through `ServiceArtifactDisk`;
- the 32 kbps full-service transcription audio is retained;
- durable artifacts are enumerated in
  `processing_metadata['service_artifacts']`;
- historic sermon video, transcript and thumbnail output uses the ordinary relative layout;
- `audit:historic-import-assets` validates the per-batch import report.

These are permanent normal-pipeline improvements, not import-only compatibility code. Do not
reimplement them in the exporter.

Two corrections are load-bearing:

- `rms_log_path` is now durable and must be promoted; only genuine temp fields are nulled.
- `service_artifacts` is an extensible collection. Bundle code must enumerate it rather than
  hard-code the artifact types known today.

## 4. Target architecture

### 4.1 Two linked bundles

Local produces:

1. a **processing-result bundle** from this plan; and
2. a **reviewed-convergence bundle** from R8 WP10.

They share:

- batch hash;
- natural service identity `(date, service)`;
- processing UUID and source media hash;
- Livestream source revision/assertion hashes;
- pre-review evidence-set/projection hash;
- processing fingerprint.

Neither bundle is valid alone for final production convergence. The media bundle cannot make a
canonical service decision; the convergence bundle cannot claim a recording exists without its
verified media result.

### 4.2 Local flow

1. Inventory and approve the drive manifest.
2. Import the curated Email and OpenLP evidence needed for the batch.
3. Process each historic recording through the normal livestream pipeline into isolated staging
   storage.
4. Wait for pipeline and fan-out readiness.
5. Complete technical media review:
   - service identity;
   - sermon boundaries and metadata;
   - speaker decision;
   - section classifications and song matches;
   - approval-required sermon/children's-talk publication;
   - published/rejected/not-applicable disposition for every section candidate.
6. Ingest/refresh the run's Livestream assertions through R8.
7. Project Email + OpenLP + Livestream together.
8. Complete one final canonical service review after all three sources are present.
9. Export both linked bundles and the exact local comparison manifest.

Technical media review may proceed batch by batch. Final canonical review must not happen early.

### 4.3 Production flow

R8 WP11 owns the operator sequence. At the per-service seam:

1. preflight both bundles, source records, assets, fingerprints and plan hash without writes;
2. open R8's outer per-service transaction;
3. create the portable media graph and remap its relationships;
4. ingest the Livestream source revision/assertions through R8's common action;
5. project the complete active Email/OpenLP/Livestream set;
6. require the pre-review hash to equal local;
7. apply the Manual review revision;
8. link sections to canonical items from assertion/decision identity;
9. import/remap permanent section publications;
10. verify assets, media graph, canonical manifest and public song usage;
11. commit once; on failure roll back every database write and compensate only this attempt's
    newly-created final assets;
12. rerun and require `already_present`/no-op.

The legacy `LivestreamChurchServiceProjectionService::project()` plus a later linkage transplant is
not a production import step.

## 5. Isolated staging storage

Local processing must not write directly to production's canonical paths. Current permanent paths
contain database IDs, including:

- `sermons/{sermon_id}/...`;
- published song video paths containing `service_section_id`;
- section-publication paths containing local section IDs.

Even with overwrite guards, using the live namespace can collide with production IDs, leaves
unpromoted objects publicly addressable and produces paths that do not correspond to production
row IDs.

Add a dedicated private staging disk/root for this operation:

- same storage driver and persistence characteristics as the target;
- unique batch-root prefix or separate private bucket;
- no CDN/public-read route;
- relative output layout remains the normal application layout beneath that root;
- exporter records logical asset roles, staged paths, sizes and SHA-256 hashes;
- importer allocates production rows, derives production-canonical paths from their new IDs,
  copies/verifies without overwriting different content, then persists those paths;
- failed services delete only objects created by their attempt;
- staging objects remain until the complete batch passes and the rollback window expires.

No new filesystem dependency is required. The implementation should use the configured Laravel
filesystem boundary and existing storage adapters.

## 6. Normal-output contract

Before implementing the exporter, run one non-mocked canary through the real current livestream
pipeline and inventory every durable output. Classify each as:

- `portable` — bundle and remap it;
- `deterministically_rebuilt` — rebuild without network/media processing and prove equality;
- `ephemeral` — omit it deliberately and prove no durable read path requires it.

The initial required portable graph is:

| Layer | Required contents | Portable identity/remap |
|---|---|---|
| Primary publication | Main `sermons` row, preacher/aliases, scripture filters | Date + service + content type + stable publication role |
| Additional publications | Published sermon/children's-talk rows referenced by sections | Service identity + stable section key + content type |
| Processing run | Complete `media_processing_logs` durable state | Processing UUID + source SHA-256 |
| Processing history | `sermon_processing_steps` | Processing UUID + step |
| Segmentation | `livestream_segments` | Processing UUID + segment index/timing |
| Sections | `service_sections`, including reviewed classification and publication state | Processing UUID + section order + classification signature |
| Segment links | `service_sections.source_segment_ids` | Remap exported segment keys to production segment IDs |
| Service evidence | Livestream source record/assertions | Revision/assertion hashes |
| Service content | Accepted Livestream `summary`, `notices` and `chapter_markers` claims | Versioned source-record content + assertion hashes |
| Canonical links | Section → service-item relation | R8 assertion/decision identity, not title/position guessing |
| Published song media | `song_videos` and permanent video objects | Song canonical key + stable section key |
| Durable artifacts | Every enumerated `service_artifacts` entry, transcript and RMS paths | Kind + processing UUID + hash |
| Asset provenance | Main and additional sermon media, thumbnails and metadata candidates | Logical role + owner natural key + hash |

The contract test fails when the current pipeline adds a durable model, relationship or asset that
the exporter has not classified.

### 6.1 Fan-out readiness

The main processing log leaving an in-progress state is insufficient. Export must refuse until:

- no active or failed descendant work remains;
- `StoreSermonVideo` has linked and verified the permanent main video;
- every `AutoPublishServiceSection` dispatch has settled;
- every approval-required sermon/children's-talk section is published or explicitly rejected;
- every song section eligible for auto-publication has a `song_videos` row and verified asset;
- no unpublished candidate is being exported as permanent state;
- the durable row/object hash is stable across two readiness reads.

Do not create a second queue orchestrator. Query and audit the existing durable outcomes.

### 6.2 Portable processing metadata

Do not copy `processing_metadata` wholesale. It can contain:

- local church-service item IDs such as structure `oos_item_id`;
- local paths;
- retry/proposal state;
- evolving nested payloads unknown to an older importer.

Create a versioned serializer with:

- an explicit allowlist of portable keys;
- natural-key representation for every retained relationship;
- a deny/fail rule for unknown keys that look like IDs, paths or runtime correlation;
- exact round-trip tests for each supported metadata block;
- preservation of processing fingerprint, structure payload, historic source provenance and
  `service_artifacts`.

True temp/runtime fields are not portable: `source_file_path`, `enhanced_audio_file_path`,
`queue_name`, `job_id`, `attempt_count`, local owner ID and any live retry lease. Durable
`rms_log_path`, transcript paths and artifact entries are portable.

## 7. Work packages

### HM0 — Pin the normal-output contract

Add a real-pipeline canary fixture and a contract inventory test before writing the bundle.

Cover:

- full normal livestream chain and fan-outs;
- sermon plus a published children's talk;
- confirmed song section producing `song_videos`;
- durable artifacts and processing steps;
- section-to-segment and section-to-publication relationships;
- explicit ephemeral exclusions.

**Acceptance:** the test produces a stable logical manifest and fails when a required durable
output is removed from it.

### HM1 — Isolated staging and explicit import identity

Add the private staging disk/root and switch historic batches to it through configuration.

Change `HistoricVideoImporter` so every single-file, concatenated and re-encoded dispatch passes:

- `serviceOverride` from the approved work item;
- `serviceDateOverride` from the approved work item;
- historic source/concatenation metadata;
- batch and processing fingerprint.

Keep the source filename as provenance only.

**Tests:** every dispatch shape uses the overrides; wrong filenames cannot change identity; staging
paths cannot resolve through the public production/CDN disk; canonical production keys remain
untouched.

### HM2 — Export-readiness audit

Add one read-only readiness service/command used by the exporter and batch runbook.

It reports:

- active/failed processing or fan-out outcomes;
- missing main sermon video/transcript/thumbnail;
- missing durable artifacts;
- unresolved section publication decisions;
- eligible song sections without `song_videos`;
- mutable output hash;
- services whose Livestream assertions do not match their final sections.

**Acceptance:** exporter refusal is deterministic and actionable; a fully settled canary passes.

### HM3 — Processing-result bundle format and serializer

Define `crockenhill-historic-processing-result`, version 1.

The envelope includes:

- format/version and batch hash;
- source manifest identity;
- code/pipeline/bundle versions;
- model/prompt/schema/config fingerprint;
- complete logical media graph from §6;
- Livestream source record/assertions;
- accepted Livestream structure content for `summary`, `notices` and `chapter_markers`;
- staged asset manifest;
- R8 evidence-set and pre-review hashes;
- no local database IDs, secrets or runtime queue correlation.

Use single-purpose injected serializers for the run, sections/segments, publications, metadata and
assets. Do not introduce a second source-assertion or review format.

**Acceptance:** schema validator rejects missing graph members, unknown ID-bearing metadata,
unsafe paths, duplicate natural identities and hash/fingerprint drift.

### HM4 — Exporter

Add a dry-run-first exporter selecting only:

- completed, non-superseded historic runs;
- technically reviewed media;
- fully settled fan-outs;
- services with complete R8 Livestream assertions;
- final canonical review already completed locally.

The exporter verifies every staged object by size and SHA-256 and writes only to
`storage/scratch/` or another explicitly private path.

**Acceptance:** exporting the same unchanged set produces the same logical manifest and bundle
hash; no local ID or raw private email body appears.

### HM5 — Production media importer

Add a create-only, dry-run-first importer:

- whole-bundle preflight before mutation;
- `--apply --plan-hash=<dry-run hash>`;
- classifications `already_present`, `create`, `blocked_difference`, `conflict`;
- consistent lock order;
- composable prepare/persist steps that can join R8 WP11's per-service outer transaction;
- production-canonical path allocation after row IDs exist;
- asset copy/verification with compensating cleanup on rollback;
- relationship remapping from stable keys;
- no queued jobs, notifications, AI calls or media processing;
- after-commit events only where existing read paths require them.

The importer does not create/update `church_service_items` or service review columns. Its
standalone apply mode is for cross-database rehearsal only; production Bundle A application runs
inside R8's convergence orchestrator so media rows cannot commit without the matching evidence,
projection, Bundle B revision, links and database equality gates.

**Acceptance:** partial failure leaves no rows or newly-created final assets for that service;
different-content path collisions block; identical rerun is no-op.

### HM6 — R8 convergence integration

Integrate through R8 WP2/WP3/WP10:

- media import supplies the Livestream source revision/assertions;
- Email and OpenLP evidence must already be present for the reviewed base;
- deterministic projection must match the local pre-review hash;
- Manual review transfer must match the complete evidence hash;
- sections link through contributing Livestream assertion identity;
- every published `song_videos` row links to the remapped section, song and canonical service;
- strict public-song-usage equality remains a hard transaction gate.
- R8's per-service orchestrator owns the outer transaction across Bundle A, WP2, WP3, WP10,
  canonical links and all database equality gates; component actions do not commit independently.

**Acceptance:** aggregate-equal but item-different services fail; all six source arrival
permutations converge; local and production manifests match across different primary keys.

### HM7 — Operator appendix and cleanup

Keep only drive-specific acquisition instructions here:

- inventory/corpus manifest;
- storage/config canary;
- calibration batches and self-braking;
- technical media review;
- readiness/export commands;
- staging retention/cleanup.

R8 WP11 is the sole production convergence runbook. Do not maintain a second production sequence.

Delete the one-shot historic importer/exporter/importer only after:

- the complete archive is promoted and audited;
- both bundle reruns are no-op;
- the rollback window expires;
- private manifests and hashes are retained;
- the deletion trigger in each command docblock is satisfied.

## 8. Local acquisition runbook requirements

Before batch 1:

1. land R8 WP0–WP5 and HM0–HM3;
2. preserve the source drive and create the approved corpus manifest;
3. configure the private staging disk/root;
4. verify resolved transcription, analysis, structure and storage configuration is non-mock and
   matches the planned fingerprint;
5. sync/import the required Email and OpenLP evidence locally;
6. import the active production song/preacher identities through their approved portable paths;
7. confirm queue timeout/retry configuration for the longest pipeline job;
8. snapshot the local database and preserve the manifest outside git.

For each batch:

1. dry-run inventory;
2. process a small calibration set;
3. wait for processing and fan-outs;
4. run the historic asset and HM2 readiness audits;
5. complete technical media review;
6. ingest/check Livestream assertions;
7. repeat until the batch is technically settled;
8. snapshot the database;
9. proceed to final combined-source projection/review only when Email and OpenLP are complete.

Do not delete or unmount the source drive until every selected work item has a verified source hash,
processing-result bundle and retained reprocessing artifacts.

## 9. Verification matrix

### Unit

- portable metadata allowlist and unknown-ID rejection;
- stable logical identities and hashes;
- production asset-path derivation;
- exact graph comparison;
- fingerprint construction.

### MySQL integration

- cross-database import with entirely different primary keys;
- segment and `source_segment_ids` remapping;
- main/additional sermon and published-section remapping;
- `song_videos` remapping;
- service/artifact relationship integrity;
- transaction rollback and compensating asset cleanup;
- rollback after Bundle A rows/assets are created but before Bundle B completes;
- concurrent/already-present import;
- canonical service rows untouched by media-only import.

### Pipeline/command

- all historic dispatch shapes use explicit date/service overrides;
- export refuses unsettled fan-outs;
- export refuses incomplete section publication;
- durable RMS and every `service_artifacts` entry round-trip;
- no production queue, notification, FFmpeg, transcription or LLM call;
- dry-run/plan-hash/apply/idempotency;
- zero public-song-usage loss;
- processing-result and R8 bundle hash agreement.

### Full quality gates

Every implementation PR runs focused red→green tests, PHPStan, Pint and the parallel full suite.
Browser tests are required only for review UI behavior owned by R8.

## 10. Production acceptance

The historic-media portion is complete only when:

- every approved drive item is represented or explicitly excluded with reason;
- every promoted run has the complete portable graph;
- all assets resolve from production-canonical paths and match hashes;
- no local IDs or staging paths remain in production durable state;
- all Email/OpenLP/Livestream evidence hashes match local;
- all Manual canonical revisions match local;
- all section/item and published-output links are exact;
- no public song occurrence is lost;
- no unexpected external/media-processing call occurred;
- every importer rerun reports no writes;
- the exact R8 closeout audit passes.
