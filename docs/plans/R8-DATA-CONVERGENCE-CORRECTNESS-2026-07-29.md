# R8 Data Convergence Correctness Plan

> **Status (2026-07-30): WP0–WP10 and the WP11 command surface are implemented on `master`.
> Production convergence remains blocked until the implementation is deployed, the complete local
> WP11 rehearsal passes, the private parity/closeout reports are accepted and every applicable R8
> operator gate is closed. No production convergence mutation has run.**
>
> **Superseded as an implementation plan (2026-07-31):** the audit found release-blocking defects in
> source independence, active revision selection, anchored projection, automatic finalisation,
> proposal transport and binding preflight. [Historic Archive Import Readiness Remediation](HISTORIC-ARCHIVE-READINESS-REMEDIATION-2026-07-31.md)
> is now the single implementation sequence and production gate. This document is retained only as
> a decision record and detailed convergence prior art; do not execute its R8 work-package numbering.
>
> **Where that successor stands (2026-08-07), so this header's "implemented on `master`" is not
> read as readiness:** the successor plan's PRs 1–17 and 21–24 have merged, which means the defects
> listed above are *repaired* rather than merely catalogued — the code this header describes as
> implemented is not the code that will run. Its G2–G9 are all unclaimed, its rehearsal has not
> started, and a 2026-08-07 readiness audit added three more drive-free slices (PR25–PR27). The
> production convergence this plan gates is still blocked, for the reasons its own paragraph above
> gives plus those.
>
> This plan supersedes
> [`LOCAL-PROCESSING-PORTABILITY-2026-07-28.md`](../archived-plans/LOCAL-PROCESSING-PORTABILITY-2026-07-28.md)
> and owns the correctness work discovered while
> reviewing commit `e68ca31f899440f7c52b439498c282182fdf382e`. It amends the service-data parts of
> `docs/operations/r8-data-convergence-runbook.md`. It does not own historic-drive acquisition,
> pipeline retention or technical media review, but it **does replace the canonical projection,
> review transfer and production sequencing from the original historic-media Stage B**.
> [`HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md`](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
> now supplies the portable processing-result bundle at the WP9 seam.
>
> **Maintainer decision (2026-07-29):** the OpenLP and OoS import commands may be changed and tested
> as much as necessary. Their eventual deletion is not a reason to accept a weaker production run.
>
> **Do not:** run the real R8 OpenLP/OoS production mutation before this plan's pre-production gate;
> silently fall back to production AI extraction or historic-video reprocessing; discard an older
> proposal when a newer source arrives; or apply a machine import directly to a manually reviewed
> canonical service.

**Who benefits:** the operator reviewing historic services, site visitors relying on accurate song
usage and sermon/service records, and future maintainers who need to explain how a canonical item
was chosen.

**What observably improves:** the same checked source bundle produces the same item-level canonical
manifest regardless of import order; production makes zero unplanned AI/video-processing calls;
every source proposal remains reviewable; and a later machine import cannot silently change a
reviewed service.

---

## 1. Problem statement

Commit `e68ca31f899440f7c52b439498c282182fdf382e` made useful improvements:

- admin saves now write with Manual authority;
- matched OpenLP song and reading identity survives a later email import;
- cross-source unmatched items are preserved;
- source evidence is accumulated;
- an earlier staged proposal is copied into `superseded_proposals`;
- the runbook now describes a curated 428-file OpenLP directory, composition checks and a closeout
  gate.

Those changes do not yet establish the required invariants:

1. The approved OpenLP set is still assembled by manual delete/rename instructions, so the wrong
   428 files can pass a count check and a self-generated checksum.
2. Canonical results are not fully arrival-order independent; service-level `source` and some
   review signals remain last-writer dependent.
3. `pending_structure_merge` remains one mutable JSON slot. Superseded proposals are not shown or
   independently resolved, and resolving the newest proposal deletes the entire subtree.
4. Production still repeats OoS extraction and historic-video processing performed locally.
5. Planned and observed assertions still share one union list without an explicit occurrence state
   or a complete final-adjudication rule.
6. Manual authority has a deletion hole: a song originally authored by Email/OpenLP can retain that
   machine `source` after a manual save, then be deleted when that machine source later omits it.
   More generally, a reviewed service can still receive direct machine additions before review is
   reopened.
7. The final parity query compares aggregate counts, not ordered item identities and canonical
   fields.

The fix is not another layer of conditions around `church_service_items.source` or another nested
array in `import_metadata`. Source submissions must become immutable evidence, and the canonical
list must be a deterministic, reviewable projection of that evidence.

## 2. Definition of done

R8 service convergence is correct only when all of the following are true:

- the OpenLP apply run is authorised by the exact private curation manifest used in rehearsal;
- all 536 raw archives are accounted for as 428 includes, 105 byte-identical duplicates and 3
  exclusions, with 7 included entries carrying explicit corrected aliases;
- Email, OpenLP and Livestream assertions are stored independently from canonical items;
- all six permutations of Email/OpenLP/Livestream arrival produce the same canonical manifest;
- every proposal and source revision remains durable after later arrivals and review decisions;
- a reviewed canonical revision is immutable to machine sources;
- planned-only, observed-only and planned-and-observed items are distinguishable and reviewable;
- production reuses locally produced OoS assertions and reviewed historic-video results, with zero
  silent fallback calls;
- the historic processing-result bundle reproduces the complete durable normal-pipeline graph
  across different database primary keys;
- all source and bundle artifacts are transferred through a private, non-served staging root;
- production's ordered per-item manifest matches the reviewed local manifest, except for differences
  explicitly accepted and recorded by a user;
- zero actionable archive emails, pending proposals, stale review sessions and unexplained review
  states remain;
- identical reruns are no-ops;
- the focused, static-analysis, formatting, full-suite and browser gates pass.

## 3. Decisions locked by this plan

### 3.1 Evidence is immutable and canonical state is projected

Each Email plan, OpenLP archive, Livestream result and Manual review is an immutable source
revision. Its assertions are never rewritten when another source arrives. Re-importing the same
source payload is an idempotent no-op; a changed payload creates a new revision linked to its
predecessor.

`church_service_items` remains the read-side canonical projection used by the application. It is
not the evidence store.

### 3.2 Manual review is final authority

A machine source may auto-project only while the service has no reviewed canonical revision. Once
a person reviews or edits the complete list:

- the accepted list and order become a Manual source revision;
- the service records the reviewed canonical revision;
- later machine submissions create proposals and review signals only;
- they do not add, delete, reorder or rewrite canonical items;
- accepting later evidence creates a new Manual canonical revision.

This applies equally to direct workbench saves and to structure-merge resolution.

### 3.3 Arrival order never selects a canonical field

The projector consumes the complete active source-revision set. It must not consult:

- which importer ran last;
- `church_services.source`;
- the current `church_service_items.source`;
- the position of a proposal in a JSON array.

The projector is a pure service returning a canonical DTO, field decisions, matches, conflicts and
a deterministic SHA-256 manifest hash. Persistence happens separately inside a transaction.

### 3.4 Field-level authority is explicit

| Field/decision | Authority |
|---|---|
| Final membership, exclusions, custom values and final order | Manual |
| Song identity, OpenLP search identity and canonical reading reference | Manual → OpenLP → Email explicit link/reference → Livestream |
| Planned non-song title/details | Manual → Email → OpenLP → Livestream |
| Observed occurrence, timings and observed relative order | Manual decision → qualified Livestream evidence |
| Planned completeness | Email plus OpenLP; neither source's silence deletes the other's assertion |
| Service `summary`, `notices` and `chapter_markers` | Manual → accepted Livestream structure content |
| Ambiguous match or irreconcilable order | Proposal requiring review; never last-writer mutation |

For an unreviewed service, unmatched source assertions remain in the projected union with an
explicit evidence state. For a reviewed service, membership is exactly what the Manual revision
states.

### 3.5 Observation and planning are separate axes

Canonical items expose an `occurrence_state`:

- `planned_only` — asserted by Email and/or OpenLP, with no accepted Livestream assertion;
- `observed_only` — asserted by Livestream, with no plan assertion;
- `planned_and_observed` — supported by both;
- `manually_confirmed` — a reviewer explicitly confirmed occurrence where machine evidence was
  insufficient.

An excluded assertion remains in evidence/history but not in the canonical item list. A service
with a recording cannot close while unexplained planned-only or observed-only items remain; the
reviewer must confirm, exclude or explicitly accept them as unresolved historical evidence.

### 3.6 The legacy `source` columns cease to be policy inputs

Add a service-level `source_summary` with deterministic values:

- a sole machine source: `email`, `openlp` or `livestream`;
- more than one contributing machine source: `mixed`;
- a reviewed Manual projection: `manual`.

During the expand/contract transition, populate the existing `source` columns for compatibility,
but no merge, deletion, cleanup or review decision may depend on them. Replace consumers such as
livestream deletion with source-record/processing-log relationships before contracting the legacy
columns.

### 3.7 Production never silently recomputes a reviewed local result

Portable bundles carry normalized assertions, hashes and the processing fingerprint that produced
them. A missing/mismatched entry is a hard preflight failure. The production command does not call
the OoS extractor or process a historic video unless the operator invokes a separately named,
explicit fallback outside this convergence run.

### 3.8 Local review is the production acceptance baseline

After all local sources are present, the operator reviews the final combined projection once.
Production imports the same source revisions and the resulting Manual review revision. It applies
the Manual revision only if the production evidence-set hash matches the reviewed local base hash.
Otherwise it stops and reports a field-level diff.

### 3.9 Historic promotion has two linked bundle domains

The historic operation exports:

- **Bundle A — processing result:** owned by the historic-media plan; carries the complete durable
  output of the locally-run current livestream pipeline plus its Livestream source
  record/assertions;
- **Bundle B — reviewed convergence:** owned by WP10; carries the complete evidence hashes, Manual
  source revision, review session/decisions and canonical manifest.

Bundle A never writes canonical service items or review state. Bundle B never creates media rows or
assets. Production may apply Bundle B only after Bundle A's Livestream evidence, combined with the
Email and OpenLP evidence, reproduces the reviewed local pre-review hash.

### 3.10 Transfer staging is isolated from public storage

Local historic processing writes below the private staging disk/root defined by the historic-media
plan. Production stages source evidence, Bundle A, Bundle B and their assets below a private,
non-served run root with exact permissions and checksum-verified manifests.

No local run writes a production-canonical public key. No importer accepts a public/sermon/
transcript path as its staging root. Assets move to production-canonical paths only during the
explicit Bundle A apply, after production row identities are allocated and different-content
collisions have been rejected.

## 4. Target data model

All schema changes are additive in the first release. Generate focused migrations through Artisan;
do not modify deployed migrations or combine DDL and backfill DML.

### 4.1 `church_service_source_records`

One immutable revision of a source submission:

| Column | Purpose |
|---|---|
| `id` | Local primary key; never exported as identity |
| `church_service_id` | Canonical service relationship |
| `source` | Email, OpenLP, Livestream or Manual |
| `source_key` | Stable identity: message/plan key, OpenLP artifact key, processing UUID or Manual review UUID |
| `revision_hash` | SHA-256 of canonical normalized assertions |
| `input_hash` | SHA-256 of the source artifact/content |
| `supersedes_id` | Previous revision of the same source key, nullable |
| `batch_hash` | Import/bundle identity |
| `processing_fingerprint` | Versioned JSON: code commit, parser/projector version, model, prompt/schema and relevant config hashes |
| `service_content` | Versioned source claim for service `summary`, `notices` and `chapter_markers` |
| `payload_complete` | False for inferred legacy backfills |
| `captured_at` | Source/review time |
| `created_by_user_id` | Manual reviewer, nullable for machine sources |
| timestamps | Audit |

Constraints and indexes:

- unique `(source, source_key, revision_hash)`;
- index `(church_service_id, source, captured_at)`;
- index `batch_hash`;
- foreign keys constrained; `supersedes_id` references the same table.

Portable bundles never export `created_by_user_id`. They carry the SHA-256 of the reviewer's
normalized email; production preflight must resolve that to exactly one existing admin user or
block for a fresh production review. It never creates or copies a user. Cross-record references in
bundles use revision hashes, not local primary keys.

### 4.2 `church_service_item_assertions`

One source's claim about one item:

| Column | Purpose |
|---|---|
| `source_record_id` | Owning immutable source revision |
| `assertion_key` | Stable key within that revision |
| `source_position` | Source order |
| `evidence_kind` | `planned`, `observed` or `manual` |
| `type`, `section_type` | Source semantic identity |
| `title`, `source_title` | Verbatim source fields |
| `normalized_title` | Matching aid, not canonical authority |
| `song_id` / `song_canonical_key` | Local link plus portable natural identity |
| `scripture_reference` / `normalized_scripture_key` | Portable reading identity |
| `start_seconds`, `end_seconds`, `confidence` | Observed evidence |
| `metadata` | Source-specific detail |

Constraints and indexes:

- unique `(source_record_id, assertion_key)`;
- indexes on source position, song canonical key and normalized scripture key;
- no canonical-item foreign key is required for identity; matches belong to a projection/decision.

### 4.3 `church_service_merge_proposals`

An immutable projection offered for review:

| Column | Purpose |
|---|---|
| `church_service_id` | Service being reviewed |
| `trigger_source_record_id` | Revision that caused projection/reprojection |
| `base_canonical_revision` / `base_canonical_hash` | Optimistic concurrency token |
| `included_source_hashes` | Canonically sorted source revisions included in this projection |
| `proposed_items` / `proposed_hash` | Complete proposed canonical projection |
| `field_decisions` | Authority selected for every canonical field |
| `conflicts` | Ambiguities and review reasons |
| `status` | `pending`, `accepted`, `rejected` or `stale` |
| `resolved_by_user_id`, `resolved_at` | Resolution audit |
| timestamps | Audit |

A newer proposal may mark an older proposal stale, but never deletes it. Stale means “recompute
against the new base,” not “discarded.”

### 4.4 `church_service_review_sessions` and decisions

A session records the complete proposal set and canonical revision the reviewer saw. Per-item
decisions record:

- included/excluded;
- selected source assertion or custom Manual value;
- final position;
- song/reference override;
- occurrence decision;
- optional rationale.

The session also records field decisions for service `summary`, `notices` and `chapter_markers`,
including source selection or a custom Manual value. The resulting Manual source revision contains
the complete final service content as well as the complete item list.

The session stores the resulting canonical hash and revision. This is the durable proof that every
included proposal was adjudicated. Proposals not included in the session remain pending.

### 4.5 Canonical columns

Add to `church_services`:

- `canonical_revision` unsigned integer, default `0`;
- `canonical_hash` nullable 64-character string;
- `reviewed_canonical_revision` nullable unsigned integer;
- `source_summary` nullable string.

Add to `church_service_items`:

- `canonical_identity` nullable indexed string;
- `occurrence_state` nullable string;
- `manual_occurrence_decision` nullable boolean.

Keep `import_metadata`, `source`, `pending_structure_merge_source` and
`metadata.source_evidence` during dual-write. Contract them only after WP11, as described in §10.

## 5. Write and review flows

### 5.1 Machine source ingestion

Every source adapter calls one action:

1. begin a transaction;
2. resolve/create and `lockForUpdate()` the service row;
3. idempotently insert the source record and assertions;
4. resolve the active revision set by stable source key;
5. run the deterministic projector;
6. if the service is reviewed, persist a proposal only;
7. if unreviewed and projection has blocking ambiguity, persist a proposal only;
8. otherwise apply the projection, increment `canonical_revision`, update the hash and emit the
   canonical-change event after commit.

All writers acquire locks in the same order: service, proposals ordered by id, canonical items.
The date/service unique race is handled by re-querying and locking the winning row. Source-record
unique keys make retries idempotent.

### 5.2 Matching

Try matching tiers across all candidates, strongest first:

1. canonical song key or explicit song identity;
2. exact normalized scripture reference;
3. compatible semantic type plus exact normalized source identity;
4. compatible semantic type plus constrained normalized-title match;
5. compatible type and source-order window between already matched anchors.

Do not use a weak positional fallback across independently authored sources. If more than one
candidate survives a tier, create a conflict rather than selecting the first database row.

Every accepted link records the match tier, confidence and contributing assertion hashes in the
proposal/decision and the convergence manifest.

### 5.3 Deterministic order

- Manual order wins when a Manual revision exists.
- Otherwise, observed items with qualified timings are ordered by Livestream time.
- Planned items matched to observed items use the observed position.
- Planned-only items are inserted between their nearest matched plan anchors.
- OpenLP provides the next plan-order authority, then Email.
- Observed-only items stay in observed time order.
- Conflicting anchors or an unanchored list that admits more than one valid interleaving creates a
  review proposal.

### 5.4 Manual save and proposal resolution

The Livewire component sends the expected `canonical_revision`. The action:

1. authorizes admin access;
2. locks the service and selected proposals;
3. rejects a stale revision with “The service changed; reload and review the new evidence”;
4. writes a complete Manual source record and assertions;
5. applies exactly the reviewed list, including deletions;
6. increments and marks the reviewed canonical revision;
7. creates the review session and item decisions;
8. resolves only the proposals included on screen;
9. leaves other proposals pending;
10. dispatches reconciliation/events after commit.

Manual omission means deletion, including a detected song, because the reviewer is looking at the
complete canonical list. Source evidence remains in the assertion tables.

## 6. Work packages

### WP0 — Pin the semantics with failing tests

Before changing production code, add red tests for:

- manually save an Email-authored song, then re-import an Email plan omitting it: the song survives
  and a proposal is created;
- the equivalent OpenLP-authored song case;
- a later machine source adds/changes/reorders an item on a reviewed service: canonical rows are
  unchanged and a proposal records the complete delta;
- OpenLP and Email proposals both remain visible and auditable after either is resolved;
- all six Email/OpenLP/Livestream arrival permutations yield the same canonical manifest;
- Livestream `summary`, `notices` and `chapter_markers` project identically in every arrival order,
  enter Manual review and contribute to the canonical hash;
- an identical revision rerun writes nothing;
- two concurrent source/proposal writes both survive;
- each occurrence state is derived correctly;
- aggregate-equal but item-different manifests fail comparison.

Keep the existing `e68ca31f` tests; tighten their semantics rather than deleting them.

**Acceptance:** each test fails for the intended current behavior before its implementation WP
turns it green.

### WP1 — Add normalized evidence and revision schema

Deliver:

- focused migrations for the tables/columns in §4;
- enums/data objects for source, proposal status, evidence kind and occurrence state;
- Eloquent models/relationships, factories and schema-integrity tests;
- canonical JSON encoder and SHA-256 helper with stable key/list ordering;
- model defaults/casts matching database defaults.

No backfill or read-path switch in this WP.

**Acceptance:** migration/rollback tests pass; duplicate source revisions are rejected; hashes are
stable across equivalent associative-array ordering; no existing route or test behavior changes.

### WP2 — Source adapters and immutable ingestion

Add adapters for:

- Email: source key = message identity + plan key; input hash = normalized archive/email content;
- OpenLP: source key = curated manifest entry; input hash = `.osz` SHA-256;
- Livestream: source key = processing UUID + projection format version;
- Manual: source key = generated review UUID; complete-list assertions.

Route `InboundEmailImportService`, `ImportChurchServiceFromOpenLp`,
`LivestreamChurchServiceProjectionService`, `SaveChurchServiceFromAdmin` and structure-review
actions through the common ingestion transaction.

For a live/local Livestream, the current pipeline maps its final sections into a source revision
and assertions through this action. It also maps the accepted `service_structure` content into the
source record's versioned `service_content`. For portable historic ingestion, Bundle A supplies
that exact revision/assertion/content payload and processing fingerprint to the same action.
Portable ingestion must not dispatch projection jobs, media jobs or external calls, and it must not
introduce a second Livestream evidence format.

Dual-write existing `metadata.source_evidence` from normalized assertions for compatibility. Do not
read it as authority after this WP.

**Acceptance:** every ingress creates the expected immutable source/assertion set; repeat payloads
are no-ops; changed payloads create linked revisions; transaction rollback leaves neither evidence
nor canonical partial state.

### WP3 — Deterministic projector and source policy

Implement the pure projector, matching tiers, ordering, occurrence-state derivation, service-content
projection and field authority table from §§3 and 5.

Replace unconditional Email/OpenLP `forceFill(['source' => ...])` writes. Populate
`source_summary` deterministically. Update logic that currently uses `church_services.source`
(including livestream deletion/ownership checks) to use source records and processing-log
relationships.

The existing source-aware synchronizer can remain as the projection persistence mechanism while
the projector owns every decision passed to it; it must no longer invent cross-source authority
from row state.

The canonical hash covers the ordered canonical items and final service `summary`, `notices` and
`chapter_markers`. Persistence applies both from the projector DTO; no importer or legacy
Livestream projector writes those service fields independently.

**Acceptance:** the six arrival permutations and repeat/revision permutations produce identical
canonical hashes, item values, service content, source summaries and occurrence states.

### WP4 — Strict Manual authority and revision locking

Change both admin save and proposal resolution so a person always writes a complete Manual revision.
After `reviewed_canonical_revision` is set, machine ingestion stages only.

The complete Manual revision includes service `summary`, `notices` and `chapter_markers`. A later
Livestream structure-content change becomes a proposal and cannot rewrite reviewed service content.

Fix the reviewed-song deletion hole immediately, even if this WP lands before the full UI:
deletion and identity protection must consult Manual assertions/reviewed revision, never just the
legacy source column.

Add optimistic revision checks to all review mutations. Recompute stale proposals rather than
blindly applying their payload.

**Acceptance:** every post-review machine mutation case leaves the canonical hash unchanged; stale
review submissions fail safely; deliberate Manual deletion and reordering work.

### WP5 — Multi-source review UI

Extend the existing class-based admin Church Service workbench. Do not create a second admin
surface.

Replace the one-proposal panel with:

- every pending/stale proposal grouped by source, artifact and capture time;
- current canonical values beside each source assertion;
- badges for Planned only, Observed only, Planned + observed and Manually confirmed;
- field-level authority/match explanations;
- current and proposed service summary, notices and chapter markers;
- include/exclude, select-source and custom Manual value controls;
- a “review all currently pending evidence” path;
- a clear warning when a proposal arrived after the screen loaded.

Use existing `<x-card>`, `<x-form-button>`, form controls and neutral admin tokens. Add `wire:key` to
proposal/assertion loops, `wire:loading` to mutations, accessible confirmations, visible focus and
mobile stacking.

The resolver marks only proposals included in the submitted review session. It never unsets source
records or historical proposals.

**Tests:**

- Livewire feature tests for authorization, stale revisions, partial/full resolution and status
  counts;
- Dusk for keyboard operation, concurrent-change warning and accept/reject/custom flows;
- Playwright only if the changed workbench layout warrants a visual baseline.

### WP6 — Backfill, shadow projection and cutover

Create a one-shot backfill command with its deletion trigger in the class docblock.

Backfill rules:

- each current `pending_structure_merge` and each `superseded_proposals` entry becomes its own
  proposal row;
- legacy `metadata.source_evidence` becomes source records/assertions marked
  `payload_complete = false`;
- never invent fields absent from the legacy evidence;
- occurrence state derives from the evidence that exists;
- ambiguous legacy items are flagged for review;
- initialize `canonical_revision` and `canonical_hash`;
- initialize `reviewed_canonical_revision` only where the existing normalized review state proves a
  completed manual review.

Run the normalized projector in shadow mode and emit a private parity report. It must not mutate
canonical rows during shadowing.

Cut over reads/UI only after:

- normalized pending count equals current slots plus all superseded entries;
- zero source assertions were lost;
- every canonical difference is explained;
- duplicate source-record count is zero;
- one normal weekly Email/OpenLP/Livestream cycle completes without unexplained drift.

### WP7 — Manifest-gated OpenLP import

Define private manifest format `crockenhill-openlp-curation`, version 1. Each raw entry records:

- relative path;
- SHA-256;
- include, duplicate-of or exclude disposition;
- duplicate target hash when applicable;
- logical upload filename;
- resolved date/service;
- alias reason;
- exclusion reason.

Change `service-tracking:import-openlp-services`:

- production apply requires `--manifest`;
- recursively inventory the raw directory, but import only manifest includes;
- reject path traversal, unmanifested extras, missing files, hash mismatch, duplicate included
  hashes, duplicate logical service identities and contradictory aliases;
- require exact accounting: 536 raw, 105 duplicates, 3 exclusions, 428 includes, 7 aliases;
- dry run writes a canonical report and `plan_hash`;
- apply requires `--apply --plan-hash=<dry-run hash>`;
- persist the manifest/batch hash on every OpenLP source record;
- rerun classifies all entries as already present/no-op.

The private decisions remain outside git, but both environments must validate against the same
manifest hash. A count and self-generated post-copy checksum are no longer sufficient.

**Tests:** happy path, every count/hash/path/alias failure, extra/missing file, duplicate alias,
dry-run/apply plan-hash mismatch, partial failure rerun and full idempotency.

### WP8 — Portable OoS assertions

This WP supersedes the parse-cache design in the
[archived local-processing portability plan](../archived-plans/LOCAL-PROCESSING-PORTABILITY-2026-07-28.md).
Transport normalized, validated assertions rather than merely copying a row cache.

Extend `oos:import-archive` with private bundle export/import modes. The versioned bundle contains:

- format/version and bundle hash;
- archive artifact hash;
- entry identity and input hash;
- parser/projector version;
- git commit;
- model id and prompt/schema/config fingerprints;
- normalized plans and assertions;
- validation disposition/reasons/consensus;
- no local database IDs.

Local export requirements:

- every non-blocked archive entry is represented;
- structural validation has run;
- source records/assertions match the exported payload hashes;
- private content stays in `storage/scratch/`.

Production import requirements:

- re-run deterministic structural validation against the staged Markdown;
- verify every entry hash and fingerprint before any mutation;
- a mismatch hard-fails the preflight;
- a structurally invalid shipped entry is held for review with no canonical write;
- production network extraction is disabled for this run;
- importing evidence and applying it remain separate explicit operations;
- complete valid bundles call the extractor zero times;
- second apply is a no-op.

**Tests:** cross-database round trip with different primary keys, zero extractor calls, entry/hash/
fingerprint mismatch, production revalidation, invalid-entry hold, no-ID portability, rollback and
idempotency.

### WP9 — Historic-video bundle integration

This WP is the seam with the revised
[historic media acquisition and result promotion plan](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md).
That plan owns Bundle A's media graph, assets and importer. R8 owns its Livestream evidence
ingestion, projection and relationship to Bundle B.

#### Bundle A contract

Require Bundle A to carry:

- main sermon plus every section-published sermon/children's talk;
- preacher and scripture natural identities;
- sanitized durable processing log state and `sermon_processing_steps`;
- `livestream_segments`;
- reviewed `service_sections`, with `source_segment_ids` expressed as stable segment keys;
- accepted Livestream structure content for service `summary`, `notices` and `chapter_markers`;
- every durable normalized/raw transcript, RMS, compressed full-service audio and enumerated
  `processing_metadata.service_artifacts` entry;
- sermon audio/video/transcript/thumbnails and published section assets;
- `song_videos`;
- the Livestream source record/assertions;
- processing/source/fingerprint hashes and an exact asset manifest.

The portable metadata serializer must allowlist supported blocks, remap natural identities and
reject unknown ID-bearing/local-path/runtime content. It must not copy local `oos_item_id`,
section/item IDs, owner IDs, queue/job correlation or retry/proposal state.

#### Acquisition/readiness contract

The historic importer must:

- process locally through the normal livestream pipeline;
- pass the approved date and service as explicit overrides for single, concatenated and re-encoded
  inputs;
- write all output under an isolated private staging disk/root;
- record source/concatenation and processing fingerprints;
- refuse export until the main chain and fan-outs have settled;
- require all approval-required sermon/children's-talk sections to be published or rejected;
- require every eligible auto-published song section to have a verified `song_videos` row;
- prove the durable output hash is stable before export.

#### Production integration

Production uses one R8 convergence orchestrator per natural service identity. It owns an outer
database transaction across Bundle A persistence, Livestream evidence ingestion, projection,
Bundle B application, canonical linking and every database equality gate. The Bundle A importer
and WP2/WP10 actions expose composable steps that join this transaction rather than committing
independently; all events are deferred until the outer commit.

For each service, the orchestrator:

1. preflights the whole Bundle A and its assets without writes;
2. imports the complete media graph synchronously, remapping all local identities and deriving
   production-canonical asset paths;
3. dispatches no queue, notification, FFmpeg, transcription or LLM work;
4. ingests the supplied Livestream source revision/assertions through WP2;
5. runs WP3 over the complete Email/OpenLP/Livestream evidence set;
6. requires the resulting pre-review hash to match local before WP10 can apply Bundle B;
7. links sections to canonical items through contributing Livestream assertion identity;
8. remaps every published sermon/children's-talk and `song_videos` relation;
9. verifies exact media-graph/assets and strict public-song-usage equality;
10. classifies an identical rerun entirely `already_present`;
11. commits only after every database equality gate succeeds.

A mismatch at any step rolls back all database writes for that service and cleans only final assets
created by that attempt from the verified manifest. Existing different-content objects are
conflicts and are never overwritten.

#### Tests

- real-pipeline canary output contract;
- cross-database round trip with entirely different primary keys;
- explicit date/service override despite misleading filenames/times;
- isolated-staging/public-path refusal;
- segment and `source_segment_ids` remapping;
- additional sermon/children's-talk and `song_videos` remapping;
- durable RMS/all-artifact inclusion;
- portable metadata local-ID/path rejection;
- unsettled fan-out/publication refusal;
- transaction rollback plus exact asset cleanup;
- failure after Bundle A rows/assets but before Bundle B commit;
- zero production jobs/external/media calls;
- aggregate-equal but item-different base mismatch;
- strict zero public-song-usage loss and identical-rerun no-op.

Until Bundle A and this integration are ready, defer historic video from convergence. Do not use
production reprocessing and claim local/production equivalence.

### WP10 — Bundle B: reviewed convergence, exact manifest and closeout

Add a versioned convergence exporter/importer for the final local review result.

Bundle B contains no media rows/assets, raw email body, secret, path or local database identity.
Per service:

- natural key `(date, service)`;
- active source-record and assertion hashes;
- pre-review projection/base hash;
- Manual source revision and review-session decisions;
- canonical revision/hash;
- ordered canonical items;
- normalized identity, semantic type, title/reference and song canonical key;
- occurrence state and manual occurrence decision;
- final service `summary`, `notices` and `chapter_markers`;
- field authority and match method;
- accepted/rejected/pending proposal identities;
- review state.

Manual-review provenance uses the reviewer email hash described in §4.1. A missing or ambiguous
production admin match blocks application; it never substitutes an arbitrary user id.

Production importer:

1. require the matching Bundle A identity and successfully imported Livestream evidence;
2. preflight all service/source/base hashes with no writes;
3. refuse to proceed unless WP3 reproduces Bundle B's reviewed local pre-review hash;
4. classify each service as `already_present`, `apply_review`, `blocked_difference` or `conflict`;
5. require explicit `--apply --plan-hash`;
6. join WP11's locked per-service outer transaction rather than committing independently;
7. refuse the Manual revision if production evidence differs from the reviewed base;
8. verify the resulting canonical hash;
9. make an identical rerun entirely no-op.

Add a read-only exact comparison/audit command. It emits field-level JSON differences and exits
non-zero for:

- missing/extra/reordered items;
- title, reference, type, song identity or occurrence mismatch;
- service summary, notices or chapter-marker mismatch;
- different accepted source assertions;
- unreviewed multi-source services;
- pending/stale proposals;
- actionable archive emails;
- unexplained review state;
- artifact/processing fingerprint drift.

Intentional production-only live services and fixtures use an explicit private allowlist keyed by
natural identity and reason. Counts are never an allowlist.

### WP11 — Runbook rewrite, rehearsal and production gate

Implementation checkpoint (2026-07-30):

- Bundle A export: `historic:export-processing-results`;
- Bundle B export: `service-tracking:export-convergence`;
- per-service dry-run/token/apply: `service-tracking:converge-historic-service`;
- exact Bundle B closeout comparison: `service-tracking:audit-convergence`;
- the local rehearsal, deployment, weekly WP6 soak, production maintenance window and post-run
  rollback soak remain operator work and have not run.

Rewrite the R8 service sequence around bundles and exact manifests:

#### Local

1. preserve the database and source artifacts;
2. import OpenLP through the approved curation manifest;
3. extract/validate OoS locally and export its assertion bundle;
4. process historic video through the normal pipeline with explicit date/service identity and
   isolated storage;
5. wait for all main-chain/fan-out outputs and complete technical media/publication review;
6. ingest/check every Livestream source revision;
7. reproject the complete Email/OpenLP/Livestream set;
8. review every multi-source/anomalous service once;
9. export Bundle A, then Bundle B and the exact local comparison manifest;
10. rerun every source/export and prove no-op/idempotency;
11. retain the private manifests, plan hashes, bundle hashes and processing fingerprints.

#### Production preflight

1. deploy WP0–WP10;
2. confirm DB backup and tested restore path;
3. block new processing/import ingress, drain accepted work, then prove the affected queues have no
   queued, reserved or delayed jobs and no live batches;
4. snapshot and explicitly adjudicate failed jobs for the affected pipelines, then pause Horizon;
5. create a private, non-served run staging root;
6. stage only manifest/checksum-verified source evidence, Bundle A, Bundle B and assets;
7. reject staging paths that resolve through public/sermon/transcript storage;
8. dry-run every importer;
9. require matching local/production source, plan, bundle and processing fingerprints;
10. stop on any unexpected count, external/media-processing call or field diff.

#### Production apply

1. sync the song catalogue;
2. import OpenLP/OoS source evidence;
3. for each affected natural service identity, run the single per-service convergence orchestrator:
   apply Bundle A, ingest its Livestream assertions, reproduce the complete pre-review hash, apply
   Bundle B, create canonical links and pass all database equality gates before commit;
4. compensate only newly-created final assets from a failed attempt's verified manifest;
5. run the cross-service exact media-graph, section-link, publication, song-usage and canonical
   gates;
6. export and compare the exact production manifest;
7. rerun both bundles and every source import and require all no-op;
8. pass the closeout audit;
9. resume Horizon/site and run health/public smoke checks;
10. retain evidence/backup, then clean only the exact private staging run.

#### Closeout requirements

- zero pending/stale proposals;
- every imported multi-source service has a completed review session;
- every proposal is included in a decision; none is discarded or deliberately left pending at
  closeout;
- every planned-only/observed-only anomaly on a recorded service is adjudicated;
- zero actionable archive emails;
- zero unexplained `needs_review`/reopened reviews;
- exact manifest equality or an explicit accepted difference;
- 428/105/3/7 OpenLP accounting and bundle hashes preserved privately;
- Bundle A contains every required normal-pipeline output and no staging/local-ID residue;
- Bundle B was applied only after Bundle A's evidence reproduced the reviewed base;
- no unexpected production extractor or historic-video processing calls;
- all idempotency passes report no writes.

## 7. PR and deployment sequence

| PR/release | Work | Can merge independently? | Production gate |
|---|---|---|---|
| A | WP0 tests + WP1 additive schema | Yes | Deploy; no behavior switch |
| B | WP2 ingestion + WP3 projector/dual-write | After A | Shadow only |
| C | WP4 Manual lock + WP5 review UI | After B | Normal weekly soak |
| D | WP6 backfill/shadow cutover | After C | Parity report accepted |
| E | WP7 OpenLP manifest + WP8 OoS bundle | After B; before R8 | Full local rehearsal |
| F | WP9 Bundle A media-result integration | Historic HM0–HM5 + B | Real-pipeline cross-DB round trip |
| G | WP10 Bundle B convergence/audit | After C, E and F | Exact two-bundle local rehearsal |
| H | WP11 runbook and production operation | After D–G | Maintainer-operated production run |
| I | Contract cleanup/deletions | Later release after closeout | Soak and rollback window elapsed |

Do not combine schema, projector, admin UI and operator execution into one PR. The safe transition
is expand → dual-write/shadow → read switch → production operation → contract.

## 8. Test matrix and quality gates

### Unit

- complete field-authority matrix;
- source-revision canonical encoding/hashing;
- matching tiers and ambiguity;
- deterministic ordering;
- occurrence-state derivation;
- service-content authority and canonical hashing;
- exact manifest comparison.

### MySQL integration

- six arrival permutations;
- same-source revision and idempotent retry;
- transaction rollback;
- date/service unique race;
- two concurrent source/proposal writers;
- stale review revision;
- Manual freeze against add/change/delete/reorder;
- Manual freeze against later service summary/notices/chapter-marker changes;
- events dispatched after commit;
- legacy backfill parity.

### Feature/command

- each importer routes through immutable evidence ingestion;
- reviewed service stages only;
- all OpenLP manifest guards;
- OoS zero-extractor bundle round trip;
- Bundle A normal-pipeline canary, different-PK round trip and complete media graph;
- Bundle A segment/publication/song-video remapping and metadata-ID rejection;
- Bundle A explicit identity, isolated staging and unsettled-fan-out refusal;
- Bundle B refusal before/mismatched Bundle A evidence;
- outer-transaction rollback after Bundle A persistence and before Bundle B completion;
- two-bundle dry-run/plan-hash/apply/idempotency;
- attention counts query normalized pending proposals.

### Browser

- Dusk for proposal visibility, field decisions, keyboard/focus, loading/error/success states and
  stale-screen handling;
- Playwright only for intentional visual changes, using deterministic service evidence fixtures.

### Every implementation PR

1. focused red→green tests;
2. `vendor/bin/sail composer phpstan` — zero errors;
3. `vendor/bin/sail bin pint --dirty`;
4. `vendor/bin/sail artisan test --parallel --compact` for non-trivial changes;
5. `vendor/bin/sail artisan dusk` for UI behavior;
6. production asset/bundle commands remain dry-run by default and require explicit apply tokens.

## 9. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Schema complexity exceeds a one-shot need | The normalized evidence/manual-authority model also fixes live weekly Email/OpenLP/Livestream convergence; keep source adapters small and projector pure |
| Dual-write drift | Shadow comparison and one weekly soak before read cutover |
| Concurrency loses a source/proposal | Row locks, immutable unique source keys, revision tokens and consistent lock order |
| Backfill invents history | `payload_complete=false`; retain raw legacy metadata; flag ambiguity |
| Reviewer workload becomes excessive | One session reviews all current evidence; group by source and show only differences/anomalies |
| Production differs from reviewed local | Base/source/canonical hashes hard-stop before Manual review state transfers |
| Bundle exposes private email/source data | Private storage/transfer, normalized assertions only, no raw body in convergence manifest |
| Operator accidentally invokes AI/video fallback | No implicit fallback; separate explicit command outside the R8 sequence; audit call counts |
| Local processing collides with or leaks through production paths | Isolated private staging root; importer derives canonical paths only after production IDs exist |
| Bundle omits a new durable pipeline output | Real-pipeline output-contract canary and exporter completeness guard |
| Asset copy succeeds but service transaction fails | Per-attempt asset manifest and compensating cleanup; never overwrite different content |
| ID-bearing metadata links to unrelated production rows | Allowlisted portable serializer; reject unknown IDs/paths; remap stable natural identities |
| Migration rollback during deployment | Additive Release A; no legacy drop until later contract release |

## 10. Contract cleanup

Only after production closeout and the agreed soak:

- switch remaining UI/API/query consumers from pending JSON and legacy source fields;
- preserve normalized source records, assertions, review sessions and decisions;
- remove `pending_structure_merge` compatibility writes;
- in a later expand/contract release, drop `pending_structure_merge_source` and obsolete JSON keys;
- reassess whether legacy item/service `source` columns can be removed or remain read-only
  compatibility fields;
- delete the spent import/backfill commands and their command-only tests after recording the final
  private Bundle A/Bundle B/source hashes and a pre-deletion tag;
- clean isolated staging only from the exact retained manifest after backup and rollback-window
  confirmation;
- update/close remainder R8 and archive this plan and the operational runbook.
