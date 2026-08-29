# Historic Video Pilot-to-Bulk Plan

**Date:** 2026-08-29
**Status:** In progress — Phase 0 inventory implemented; its first capture failed closed and no further historic-video dispatch is authorised
**Scope:** Correct the pilot findings, prove bounded staging reclamation, run a fresh canary, and process the remaining historic-video corpus safely
**Related plan:** `HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md` remains the authority for the wider historic-import programme

## 1. Decision

Do not start the remaining historic-video identities yet.

The pilot proved that the processing path can produce useful results and that failed runs can be resumed, but it also exposed launch-blocking defects in disk custody, metadata application, service projection, cost accounting and long-running operation control. The remainder must run as bounded, resumable passes, with each pass transferred into private quarantine, audited and reclaimed before the next begins.

Two distinctions are load-bearing:

1. `historic-import:release-batch` is not a disk-reclamation command. It copies quarantined assets to the public disk and marks the named records published. Staging reclamation needs a separate, verified path and must not make anything public.
2. The historic-operation cost field does not currently enforce video-pipeline spend. `HistoricImportCostLedger` has no pipeline call sites, and recording usage after a provider call cannot prevent that call from crossing a cap. The checked-in incremental plan also records `max_cost_minor_units` as 200 ($2), while the pilot review referred to a $10 cap. The immutable operation record and an explicit currency binding must settle that discrepancy before another paid run.

## 2. Pilot findings to address

### 2.1 Capacity and custody

- `/mnt/historic-work` had about 30 GB available of 461 GB after the pilot.
- The 16-identity selection consumed about 16 GB: roughly 8.7 GB sermon video, 4.6 GB retained source/staging material, and 1.3 GB audio, transcripts and section publications.
- The remaining corpus cannot fit without a transfer-and-reclaim cycle.
- No supported staging-reclaim command currently exists. The existing bundle transfer can copy and verify staging assets into private destination quarantine, but public release is a different operation.

### 2.2 Sermon metadata

- Pilot sermons retained placeholder or filename-derived titles even where AI analysis produced suitable titles.
- `ProcessTranscriptWithAI::looksLikeFilename()` does not recognise bare `Morning`/`Evening` titles or titles carrying suffixes such as `[Youtube Backup]`.
- Scripture reference needs an exact field trace. The AI job already attempts to write `analysis.reference` to `sermons.reference` when both the sermon and ID3 reference are null, so the observed null may be caused by blank ID3 authority, a different portable field, or a later serialization hop.
- Pilot sermon durations were not reliably populated despite known section boundaries.
- Existing-series matching produced implausible historic assignments because the model sees series names without sufficient date/reference constraints.
- Speaker identification often fell back to `Visiting Speaker` without preserving enough candidate detail for efficient review.

### 2.3 Service projection

The pilot's livestream projection synchronises canonical service items before ingesting the source revision. The ingestion guard can therefore see the projection's own new items as unevidenced legacy content, producing `unnormalized_legacy_items`, review state and merge proposals that the same run caused itself.

### 2.4 Songs and extracted sections

- Adjacent sections can produce duplicate fragments of the same song.
- Very short clips can become source-stage publication candidates.
- `short_partial` and `fragmented` recordings do not always justify automatic song membership, count or order assertions.
- Children's talks and low-confidence primary sermon sections need explicit publication decisions.

### 2.5 Operation control

- The source drive became stale twice during the pilot.
- Stopping the outer wrapper did not stop the real in-container process.
- A full serial run would take approximately five to six days at the observed average duration.
- Some long-running job timeout, overlap-lock and retry policies are inconsistent.
- Operator output does not distinguish all terminal dispositions clearly enough for a multi-pass run.

## 3. Delivery plan

### Implementation progress

| Phase | State | Evidence |
|---|---|---|
| 0 — Freeze and inventory | In progress | `historic:inventory-video-pilot` captures a create-once private ledger. The definitive 2026-08-29 capture hash was `cb7dc11b6ffb8070f077ecd94255696362ffd6e5ac227a6132cf00e0acde5697`; its exit gate failed. |
| 1–9 | Not started | Blocked behind the Phase 0 exit gate. |

The first capture reconciled the immutable selection and operation but found:

- the $10 operation authority (`max_cost_minor_units = 1000`) has no currency binding and no usage entries;
- 16 selected keys produced 16 processing rows for only 15 identities;
- `2023-09-03-morning` has no processing row;
- `2020-03-22-morning` owns the expected failed RMS run and its completed retry;
- all 15 completed runs currently fail portable graph inventory because `service_structure` retains a local identity or runtime field;
- unreadable macOS `._*` sidecars prevent a deep inventory of the 16.05 GB batch root, so no byte census can yet be claimed.

The private ledger is retained at `storage/app/private/historic-video-pilot-ledger-20260829-v2.json`. It is deliberately not a committed artifact because it contains the complete private processing graph and staging paths. The earlier `historic-video-pilot-ledger-20260829.json` capture predates explicit graph-error gating and is retained only as superseded evidence.

### Phase 0 — Freeze and inventory the pilot

Create one authoritative, read-only pilot ledger before modifying or deleting anything.

Record:

- exact manifest membership and disposition of all 16 selected identities;
- the relationship between the reported 13 sermons, completed processing runs, pre-existing sermons and failures;
- disk use per identity, separated into durable output, retryable input, concatenation, temporary data and unexplained residue;
- every resulting service, sermon, children's talk, song video, usage record, section and merge proposal;
- current operation state, deadline, cost authority, currency assumption and recorded usage;
- deployed commit and worker fingerprint, including models, reasoning effort, size limits, queue widths and storage roots.

**Exit gate:** Every identity and every byte under the batch root has a named owner and disposition.

### Phase 1 — Correct sermon metadata application

1. Extend placeholder recognition to cover the pilot's real shapes, including bare service names and backup suffixes.
2. Preserve manually curated titles and slugs. Regenerate a slug only when it was derived from the replaced placeholder.
3. Trace Scripture through:
   - manifest `editorial_facts.scripture_reference`;
   - `ai_analysis.reference`;
   - `sermons.reference`;
   - service-section metadata;
   - Bundle A's portable representation.
4. Treat null, empty and whitespace-only ID3 values consistently so a blank tag cannot block a valid AI value.
5. Populate sermon duration from the extracted section duration or end-minus-start boundaries.
6. For historic processing, apply a series automatically only from curated facts or a sufficiently constrained deterministic match. Retain weaker AI series output as a review suggestion.
7. Preserve the leading speaker candidates, scores and margin when automatic identification falls back to `Visiting Speaker`.

Tests must reproduce the pilot title/reference shapes and prove that curated data is never overwritten.

**Exit gate:** Banked pilot analysis can repair the intended title, reference, duration and slug fields without another paid analysis run or damage to curated fields.

### Phase 2 — Correct service projection

Change livestream projection so that source evidence is ingested before, or atomically with, canonical item synchronization. The projection must not make its own items look unevidenced.

Tests must prove:

- a fresh projection does not create `unnormalized_legacy_items`;
- an exact reprojection is idempotent;
- an existing reviewed canonical revision remains protected;
- section order, sermon placement, source attribution and section links remain stable;
- a genuine structure/analysis disagreement remains reviewable rather than being silently resolved.

Reconcile pilot proposals from stored sections and source revisions at zero model spend after the fix.

**Exit gate:** Zero pilot proposals or review flags are attributable to projection ordering.

### Phase 3 — Tighten song and section eligibility

1. Merge adjacent sections matched to the same song before extraction where the evidence proves continuity.
2. Route suspiciously short clips to review instead of making them automatically release-eligible.
3. Treat `short_partial` and `fragmented` recordings as insufficient for automatic song membership/count/order unless independently corroborated or explicitly approved.
4. Keep children's talks and low-confidence sermon sections quarantined with an explicit decision.
5. Preserve enough evidence to distinguish an intentionally short song from a split or partial extraction.

**Exit gate:** No obviously fragmentary or adjacent-duplicate song clip is automatically release-eligible.

### Phase 4 — Make cost accounting and the cap effective

Every paid structure and sermon-analysis request must record:

- historic operation ID and manifest item key;
- stable request key;
- requested and returned model;
- input, cached-input, output and reasoning tokens;
- pricing snapshot and currency;
- calculated cost and retry relationship.

Implement an atomic reservation before each outbound request, followed by settlement to the actual provider usage. A request must not start if its conservative reservation would breach the authorised operation limit. Retried request keys must remain idempotent. A provider-side project limit remains the external backstop.

Before another paid run, reconcile:

- whether the immutable authority is $2 or $10;
- the authorised currency;
- actual pilot spend where provider telemetry permits it;
- projected remaining spend using measured p95 rather than the mean;
- retry and canary headroom.

**Exit gate:** A canary request cannot be issued without sufficient reserved budget, and internal aggregate usage agrees with provider reporting within an agreed tolerance.

### Phase 5 — Build and prove private transfer and staging reclamation

The supported per-pass custody flow is:

1. Export an exact Bundle A for the completed pass.
2. Copy assets from private staging to the private destination quarantine disk using create-only semantics.
3. Verify every destination's byte size and SHA-256.
4. Audit the imported database/media graph and operation binding.
5. Confirm that no active, failed-retryable or queued job still references the candidate staging paths.
6. Dry-run an exact, enumerated reclaim.
7. Delete only verified batch-owned staging and temporary copies.
8. Write a reclaim receipt containing every removed path, size, hash, operation/pass owner and total reclaimed bytes.
9. Re-run transfer and audit to prove an exact no-op.

The reclaim implementation must:

- fail closed on unknown paths, missing hashes, unexpected links, active references or destination mismatch;
- distinguish successful-run residue from retryable failure residue;
- never delete source-drive files, destination quarantine assets or public assets;
- be idempotent and declare its one-shot deletion trigger if implemented as an Artisan command;
- honour the approved rollback-retention policy rather than silently shortening it.

**Exit gate:** The current pilot completes this cycle, returns the expected disk space and publishes nothing.

### Phase 6 — Harden bounded-run operation

#### Pass selection and sizing

- Select passes with immutable `--only` manifest keys, never `--limit`.
- Begin with 10–16 representative identities per pass.
- Calculate the permitted size as the smaller of disk capacity and the safe operating window.
- Use p95 peak bytes per identity and p95 duration, not averages.
- Reserve enough disk for the configured minimum-free threshold, the largest selected source, FFmpeg working copies and all concurrent in-flight jobs.
- Keep the initial size unchanged for at least three clean cycles before considering an increase.

#### Drive and stop behaviour

- Verify the mount, selected files and expected hashes before every pass and before dispatching each item.
- A stale mount stops new dispatches and leaves the pass resumable; it must not turn every pending item into a permanent failure.
- Document and rehearse the real in-container stop sequence: stop dispatch, gracefully stop historic workers, then verify queues and processing states.
- Record the dispatch process and worker process identities; killing only the outer wrapper is not an accepted stop.

#### Queue safety

- Align `PrepareSectionPublicationCandidates`' overlap lock with its 1,800-second timeout plus grace.
- Add bounded/exponential backoff and rate limiting to paid external stages.
- Set the importer wait timeout from measured pilot p95 plus margin.
- Surface failures, cancellations, timeouts, skips and manual-review outcomes separately, with affected processing IDs and stages.
- Define a bounded retention policy for failed-run working files.

**Exit gate:** A controlled interruption can be resumed without duplicate records, assets, notifications or paid calls.

### Phase 7 — Run a fresh untouched canary

Select 10–16 identities not touched by the pilot, stratified across:

- full, `short_partial` and fragmented coverage;
- single and concatenated recordings;
- codec mismatch/re-encode;
- at least one large source;
- different eras, folders and containers;
- services with and without existing Email/OpenLP evidence;
- songs and children's talks;
- the geometries that exercised the newly fixed validator paths.

Acceptance criteria:

- no system or code failures;
- every input reaches a truthful terminal disposition;
- titles, references, durations, series policy and speaker-review evidence are correct;
- no projection-generated legacy-item conflicts;
- song and section eligibility behaves correctly;
- cost and peak disk telemetry are complete per identity;
- the neutral/unobservable transcript rate is measured rather than extrapolated from the six-item calibration set;
- the pass completes the full transfer, audit and reclaim cycle;
- re-running the identical canary is a no-op with zero new AI spend.

Any new systemic defect blocks the bulk run. Genuine, enumerated content-review cases do not.

### Phase 8 — Process the remainder as a closed pass loop

For each pass:

1. Preflight the mount, selected hashes, disk reserve, workers, models, budget and manifest keys.
2. Dispatch only those immutable keys.
3. Monitor terminal outcomes, cost, drive health and disk watermark.
4. Stop immediately for mount instability, budget risk, unexplained duplicates, destination mismatch or recurring systemic failure.
5. Reconcile services, sermons, sections, songs and review residue.
6. Export and transfer the pass into private quarantine.
7. Verify hashes, asset receipts, database graph and operation ownership.
8. Reclaim only the verified staging inventory.
9. Record reclaimed bytes and remaining manifest membership.
10. Start the next pass only after the prior pass is closed.

The pass report must name every non-zero residue. An empty queue is not evidence of successful completion.

**Exit gate:** Every approved manifest item is completed, explicitly held/excluded, or named as unresolved; no pass retains unexplained staging data.

### Phase 9 — Final convergence and public release

After all processing passes:

1. Regenerate corpus membership and the proposal census.
2. Re-evaluate the previously uncorroborated services and disagreements against the new full-grade video evidence.
3. Resolve surviving proposals by class rather than by repetitive individual review where a safe deterministic rule exists.
4. Complete editorial QA for titles, slugs, references, series, speakers, songs, children's talks and occasions.
5. Audit exact assets, Scripture settlement, quarantine visibility and notification containment.
6. Resolve the current seam between the incremental-round plan and the release command's requirement that the operation be `Complete`. Do not manufacture completion state to bypass it.
7. Complete the required truth-set spot checks and public acceptance journeys.
8. Create separately signed, era-sized release authorisations.
9. Run `historic-import:release-batch --dry-run` before each public release.
10. Release only the exact authorised membership, observe it for the recorded rollback window, and retain the release ledger.

Public release remains a separate human-authorised act. It is never a side effect of import, transfer or disk reclamation.

## 4. Go/no-go summary

The remainder may start only when all of these are true:

- [ ] Pilot membership and disk ownership reconcile exactly.
- [ ] Title, Scripture, duration and slug application pass real-shape regression tests.
- [ ] Service projection no longer conflicts with its own evidence.
- [ ] Fragmentary/duplicate song candidates fail closed to review.
- [ ] Cost currency and cap authority are unambiguous.
- [ ] Paid calls reserve and settle against an enforced operation budget.
- [ ] Private transfer, audit and exact staging reclaim succeed end to end.
- [ ] The real worker stop/resume procedure is rehearsed.
- [ ] A fresh untouched canary passes all acceptance criteria.
- [ ] Re-running that canary is an exact no-op with zero additional model spend.

Until then, the remaining historic-video dispatch is **NO-GO**.
