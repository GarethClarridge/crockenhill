# Historic Video Pilot-to-Bulk Plan

**Date:** 2026-08-29
**Status:** In progress — Phases 0–6 complete and the Phase 7 canary has been dispatched and resumed under operation 3; the run result and its code remediation are recorded below, but the operator reconciliation and identical-canary proof remain outstanding, so bulk processing remains NO-GO
**Scope:** Correct the pilot findings, prove direct private asset promotion and bounded temporary cleanup, run a fresh canary, and process the remaining historic-video corpus safely
**Related plan:** `HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md` remains the authority for the wider historic-import programme

## 1. Decision

Do not start the remaining historic-video identities yet.

The pilot proved that the processing path can produce useful results and that failed runs can be resumed, but it also exposed launch-blocking defects in disk custody, metadata application, service projection and long-running operation control. The remainder must run as bounded, resumable passes inside one cumulative operation and database. Passes bound runtime and concurrency; they are not separate evidence islands and do not close a mini-corpus before the next begins.

Two distinctions are load-bearing:

1. `historic-import:release-batch` is a later public-release command, not part of processing custody. Completed historic assets should be promoted directly to their permanent private quarantine location, verified once, and remain private until separately authorised release. Only retryable working copies and temporary FFmpeg artifacts belong on staging.
2. The historic-operation cost apparatus does not protect this pipeline: `HistoricImportCostLedger` has no production pipeline call sites, and the operation cap was designed when the substantially more expensive Sol model made per-run cost a material operational risk. Historic processing now uses Luna and the operator decided on 2026-08-30 that an internal reservation/settlement system would be disproportionate. Phase 4 therefore neutralises every live dependency before the canary and defers physical schema/code removal to IC8 closeout. Existing model/token/request telemetry, retry backoff and the provider-side project limit remain; they are enough to diagnose runaway or duplicate calls without turning pricing into a second accounting system.

## 2. Pilot findings to address

### 2.1 Capacity and custody

- `/mnt/historic-work` had about 30 GB available of 461 GB after the pilot.
- The 16-identity selection consumed about 16 GB: roughly 8.7 GB sermon video, 4.6 GB retained source/staging material, and 1.3 GB audio, transcripts and section publications.
- The earlier claim that the remaining corpus cannot fit without a transfer-and-reclaim cycle was based on the host boot volume, not the historic drive, and is withdrawn.
- The corrected capacity estimate does not justify a bespoke per-pass Bundle A, transfer, audit, reclaim and receipt protocol before the canary measures actual retained and peak bytes. Direct private promotion plus ordinary verified cleanup is the default architecture.

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
| 0 — Freeze and inventory | Complete | Ledger `historic-video-pilot-ledger-20260829-v4.json`, hash `f3d31fef71eb21746fc5f0fb919d28f9a79ebc0af8b2c7af02820844f9b7e71c`, exit gate PASS. |
| 1 — Sermon metadata | Complete | Commit `1f17b3320` adds a pilot-bound, idempotent replay of banked `ai_analysis` with zero analysis-provider calls. |
| 2 — Service projection | Complete | Commit `e8fc05a88`. |
| 3 — Song and section eligibility | Complete | Commit `bd7d1bf27`. |
| 4 — Neutralise internal cost apparatus | Complete | Commit `82be34700` removes live cap/ledger reads and writes while retaining the inert schema and compatibility code for IC8 closeout. |
| 5 — Canary custody instrumentation | Complete | Commits `c61c8c7af` (direct create-only promotion into quarantine), `81ca5f3d9` (cleanup confined to working-copy disks) plus this commit's four byte measures, reported by `historic-import:video-pass-status --measures`. |
| 6 — Copy-and-enqueue dispatch | Complete | Commit `6c6b0a7a8` removes polling, adds operation-bound capacity evidence, and aborts stale mounts. Commit `3cb189f5b` adds the database-owned `historic-import:video-pass-status` report and the content-read-I/O regression test. **Its whole-corpus-verification claim was false until 2026-08-30**: `6c6b0a7a8` deleted the opt-in `--verify-corpus` flag *and* the argument it passed, so `plan()` fell back to its `true` default and every invocation — dry runs and `--only` passes included — re-read ~1.0 TB. Now fixed at the call site with `verifySourceContents: false`, proved by `a_bounded_pass_does_not_read_the_contents_of_unselected_sources`. A 14-item dry run went from over three hours to 23 seconds. |
| 7 — Fresh canary | Run complete; remediation implemented, acceptance blocked | Operation 3 was dispatched and resumed after a stale-mount abort. The duration, stranded-tail, custody-measurement and pilot-custody fixes are implemented and tested; the operation-bound repairs, corrected report and identical-canary proof have not yet been run. |
| 8–9 | Not started | The remainder and public-release phases remain gated on a clean Phase 7 acceptance. |

### Phase 0 outcome

The first capture's three failures were each a defect in the capture, not in the
pilot.

Portable inventory refused every completed run on `service_structure.sections[].oos_item_id`,
a local order-of-service row identity the export had no business carrying, and
behind it on four more fields: two staging-relative paths duplicating what the
section records already carry, a rejected local structure proposal, the run's own
UUID inside a publication-candidate record (which travels), and the speaker
model's local profile row (which does not).

The byte census returned nothing because macOS writes an AppleDouble sidecar
beside every staged file on the exFAT drive, Docker cannot stat those from inside
the container, and Flysystem's deep listing abandons the whole listing on the
first entry it cannot stat.

Membership is now settled per identity rather than by counting rows. The final
ledger accounts for all 16 identities — 14 completed, one completed after a
failed attempt (`2020-03-22-morning`), one skipped because a sermon already stood
on the date (`2023-09-03-morning`, sermon 862) — and all 609 files under the
16.05 GB batch root: 287 durable outputs, 304 platform sidecars, 15 orphaned RMS
working copies, two orphaned extraction working copies and one orphaned thumbnail
frame.

Two findings carry into later phases:

- **The drive returns an I/O error** reading `livestream/temp/8cfacc7b-….mp4`, a
  4.9 GB partial copy from the failed run. Phase 6's preflight must treat
  `drive_read_failures` as a drive-health signal.
- **The pipeline leaks its RMS working copies.** `GenerateRmsLog` archives the log
  to a durable artifact path and never deletes `temp/rms_<uuid>.log`. Fifteen
  identities left 200 MB behind under job UUIDs no run records. Phase 6's bounded
  retention policy has to cover them.

### Phase 5 outcome

**The direct processing lane never promoted anything.** Everything the pilot
produced was written to staging and left there. Nothing set `sermons.asset_disk`,
so the column the whole quarantine model reads was null, and the records were
created in the column default `published` state while their bytes sat on a
removable working volume. The bundle-import lane had done the opposite all along
— `HistoricMediaGraphPersister` creates its sermons `Quarantined` with
`asset_disk` set — so the two lanes disagreed about what a historic record is.
`PromoteHistoricAssets` now closes that gap on the same convention: the stored
path never changes, only the disk identity does, which is exactly what
`HistoricSermonPublicationService` does in the other direction at release.

**Quarantine was configured onto the wrong volume.** `HISTORIC_QUARANTINE_ROOT`
was unset, so the disk fell back under `storage/` — the project bind mount, on the
boot volume with 30 GiB free. Staging and temp had been deliberately moved to the
CBC drive for exactly this reason and quarantine was left behind. It is now set
to `/mnt/historic-work/quarantine`, and `.env.example` says why it must sit on the
same writable volume. Operator decision, 2026-08-30: quarantine lives on the CBC
drive, accepting that quarantined assets are unreachable while the drive is
detached.

**Cleanup could reach anything.** `VideoStorageService::cleanupTemporaryFiles()`
tried the temp disk and then fell through to `file_exists()` plus a raw
`unlink()` on whatever string it was handed. An absolute path could name anything
the container can write, including the source corpus — protected only by the
mount being read-only, not by any code — and a disk-relative path resolved
against the working directory, meaning `sermons/video/x.mp4` was a file in the
project root. Deletion is now confined to an allow-list of working-copy disks
(temp, and staging during a pass); quarantine is deliberately absent.

**Item 5 was already satisfied.** `ProcessingRunFailureHandler` returns before
cleanup for any run carrying a historic import job key, and
`it_retains_historic_livestream_inputs_after_terminal_failure_for_phase_retry`
already locked that in. Verified rather than rebuilt.

**Peak working bytes is a sample, and says so.** A continuous gauge would need a
sampler outside the pipeline. Instead each run records the total staging bytes at
its own high-water moment — durable output written, nothing reclaimed yet — and
the pass-level peak is the maximum of those samples. The other three measures are
`promoted_bytes` (summed from the same records), `staging_retained_bytes` (walked
live) and `unexplained_residue_bytes` (retained minus what any run can account
for, as a working copy it owns or unpromoted output). Residue is the number a
later reclamation change would have to name as its justification.

**Song videos cannot be promoted yet.** `SongVideo` has no `asset_disk` column and
`SongVideoService::getVideoUrl()` always builds a sermon-disk URL, so promoting
their bytes would break resolution. They stay on staging and are counted as
accounted-for, not residue. Giving `SongVideo` a disk identity is the follow-up if
the canary shows their retained bytes matter.

### Phase 6 outcome

**§2.1's capacity premise is a measurement artefact.** `df` and
`disk_free_space()` inside the container report the host's boot volume, not the
bind-mounted drive: 30 GiB free of 461 GiB, against a drive holding **444 GiB
free of 1.8 TiB**. `TempDiskSpace` already documents exactly this failure and the
operator had already set `MEDIA_PROCESSING_TEMP_DISK_UNMEASURABLE=true`, so every
gate was correctly standing down — silently, which is how the wrong number
reached a plan.

Re-derived from the manifest: the 454 remaining identities hold **979 GiB** of
source, and the pilot turned 76.3 GiB of source into 15 GiB of staging. That puts
the remainder's staging need between **192 GiB** (scaling by source bytes) and
**452 GiB** (scaling by the pilot's per-identity figure, which overstates it — the
pilot deliberately took the heaviest member of each cell, at 4.77 GiB of source
per identity against the remainder's 2.16 GiB mean).

That correction removes the premise for building a per-pass transfer-and-reclaim
architecture now. The canary must measure peak working bytes and retained bytes
after direct promotion. Build specialised reclamation only if those measurements
show that ordinary cleanup cannot keep the operation inside the verified capacity.

`sermons:import-historic-videos` now states what the pass needs — the floor, plus
twice the largest concurrent sources for FFmpeg's working copies — and says
plainly that it cannot measure what is there, naming the host command that can.

Four other defects fixed:

- **The overlap lock expired six times sooner than its job could finish.**
  `PrepareSectionPublicationCandidates` allows 1800 seconds and released its lock
  after 300, so any extraction past five minutes left the door open behind it.
  All the section-publication locks now follow the `$this->timeout + 120` rule
  the rest of the pipeline already used.
- **Two paid stages retried with no delay.** `DetectServiceStructure` and
  `MatchSongsFromTranscript` bill a provider and had no `backoff()`, so a rate
  limit burned all three attempts in seconds and paid for each request that
  reached the model.
- **A stale mount no longer fails every remaining item.** Dispatch stops at the
  first unreadable source, nothing already dispatched is disturbed, and the same
  `--only` keys resume the pass.
- **Terminal outcomes are named.** Failures, cancellations, timeouts and skips
  are reported individually with their processing id, identity and stage, not
  only as counts.

The RMS working-copy leak is closed: `GenerateRmsLog` deletes its temp file after
archiving, in a `finally` so a failed archive leaves no orphan either.

**Partially resolved by commit `6c6b0a7a8` (2026-08-30).** When staging capacity
is declared unmeasurable, definitive dispatch now fails closed unless a small JSON
evidence file binds sufficient `available_bytes` to the exact operation and plan.
The dispatcher no longer accepts `--verify-corpus`, `--poll-interval` or
`--per-file-timeout`, and no longer waits for workers. Every selected source is
size- and SHA-256-checked immediately before staging; a missing/unreadable source
aborts further dispatch as `aborted_stale_mount`, while a readable mismatch is an
identity-level integrity failure.

The remaining work is complete. `historic-import:video-pass-status` requires the
immutable operation and the exact `--only` manifest keys, reads only
operation-bound `MediaProcessingLog` rows, and names every selected item as
`not_dispatched`, `in_progress`, `completed`, `skipped`, `failed`, `cancelled`,
`manual_review` or `mixed_terminal` with its processing IDs and current stages.
It never reads queue state, worker processes or storage. The stale-mount
regression suite now separately proves an existing source can pass the preliminary
filesystem checks yet fail while its contents are read; dispatch stops as
`aborted_stale_mount` with no item-level integrity error.

### Phase 1 outcome

Two of the section's claims did not survive the data.

**Scripture reference needed no repair.** It was applied correctly on fourteen of
fifteen sermons; the fifteenth produced no reference to apply. The field trace
§2.2 called for found the AI job's existing path working as designed.

**ID3 never blocked anything.** ID3 metadata was null on every pilot run, and
`JsonData::stringOrNull` already trims blanks to null at the read boundary. Blank
now reads as absent at every decision site regardless, so it cannot.

What was real: all fifteen sermons kept a filename title, every duration was null,
and the series assignments were worse than absent. `PlaceholderSermonTitle` now
recognises the shapes the pilot produced — bare service slots, and the
`[YouTube backup]` suffix the archive stamps on a recovered upload — with no
false positive on a curated title. Duration is derived from the extracted span.

Series is settled by corroboration. Date adjacency cannot do it: the archive's
series members predate the video corpus by years, so the nearest sibling of even
a correct assignment is thousands of days away. A series named after a book of
the Bible can be checked against the sermon's own reference, and on the pilot
that test accepts all five right answers (John, Job, Philippians, Exodus,
2 Peter) and refuses all three wrong ones ("Easter: Good Friday" on a September
evening, "Abraham" on Genesis 44, "Hope In Hurtful Times" with no reference).
A historic run applies only what it corroborates and records the rest as a
suggestion; a live run is unchanged, because its series is one the model has real
context for.

**Resolved by commit `1f17b3320` (2026-08-30).** The commit proves
the corrected rules for future processing, but the exit gate is specifically
about repairing the completed pilot from banked analysis at zero model spend.
`ProcessTranscriptWithAI` always calls the analysis provider before applying its
result, and duration is repaired only while creating or upserting a sermon. The
new `historic-import:replay-video-pilot-analysis` command instead replays exact,
completed pilot processing IDs owned by the named operation. It uses banked
`ai_analysis` only, preserves curated fields, reports each changed/refused field,
and is idempotent. Its regression test binds an analysis provider that must never
be called and proves a second replay is a no-op.

### Phase 2 outcome

§2.3's diagnosis was right and its scope was not. The projection did synchronise
canonical items before ingesting the source revision explaining them, and since
only the projector writes `source_assertion_hashes`, every synced item was
unevidenced when the guard looked at it. The revision is ingested first now.

But that accounts for a third of the pilot's proposals, not all of them.
`service:reconcile-self-projected-proposals` settles them from stored sections
and source revisions at no model spend, and retired 7 of 21. The other 14 stand
on services that already held evidence-free OpenLP items before the pilot began,
which is the case the guard was built for. They are review work, not defects.

### Phase 3 outcome

The pilot published three song clips it should not have, and the review policy
now holds exactly those and two more — five of 41 song sections — while letting
the other 36 through.

The adjacent pair is not a split song. Sections 677 and 678 of run 945 are
contiguous to the millisecond and resolve to the same song, but their
`song_title_hint` values and summaries disagree: the second is the Doxology,
which the matcher folded into the hymn before it. §2.4's first item asks for a
merge before extraction, and merging these would destroy a distinction the
evidence already records. The pair reaches a reviewer instead.

The private ledger is retained at `storage/app/private/historic-video-pilot-ledger-20260829-v2.json`. It is deliberately not a committed artifact because it contains the complete private processing graph and staging paths. The earlier `historic-video-pilot-ledger-20260829.json` capture predates explicit graph-error gating and is retained only as superseded evidence.

### Phase 4 outcome

Commit `82be34700` removes `--max-cost` from historic-operation preparation and
removes cap/currency/usage claims from the pilot ledger. The existing schema,
models and isolated ledger tests remain inert for compatibility and IC8 closeout;
no dispatch or preparation path reads or writes them. Provider model/token/request
telemetry, retry backoff and the provider-side project limit remain unchanged.

### Operator decisions outstanding

None. The two that stood on 2026-08-30 are recorded as settled below.

### Operator decisions settled

1. **Internal cost control (2026-08-30).** Do not build reservation and
   settlement accounting for the Luna-based historic pipeline. Neutralise the
   historic-operation cap and cost ledger before the canary, then delete their
   inert schema/code at IC8 closeout. Retain ordinary provider usage telemetry,
   retry controls and the provider-side project limit.
2. **Pass orchestration (2026-08-30).** A pass is a bounded dispatch checkpoint,
   not a process the operator must keep alive and not an evidence round. Verify
   and copy each selected source into operation-owned staging, enqueue it, then
   let the normal workers and database-owned status carry the run.
3. **Corpus verification (2026-08-30).** Do not re-read the untouched ~1 TB
   corpus before a bounded pass. Verify the immutable manifest/plan binding and
   hash only that pass's selected sources immediately before their durable copy.
4. **Residue tolerance (2026-08-30).** Settled at zero, not ~12.5%. Every
   operation-3 identity reached `completed`: `2023-08-20-morning` produced sermon
   891 and `2024-07-28-morning` sermon 890, so both 2026-08-29 content defects
   are closed. Fifteen identities dispatched, one (`2023-09-03-morning`) skipped
   because sermon 862 already stood on the date.
5. **The 4.9 GB unreadable staging file (2026-08-30).** Moot and closed. The file
   is gone — the pilot batch root's `livestream/temp` was emptied at 07:38 on
   2026-08-30 — and `diskutil verifyVolume` reports the exFAT volume clean.
   It was a partial copy the failed run *wrote*, never source evidence, so an
   aborted write explains it without implicating the drive. The drive exposes no
   SMART data over USB, so filesystem verification plus per-file SHA-256 at
   dispatch is the available health signal.
6. **Verification scope fix (2026-08-30).** Fix at the call site by passing
   `verifySourceContents: false` rather than restoring a `--verify-corpus` flag.
   Existence, symlink, root-containment and byte-size checks still run for every
   manifest entry, the manifest and plan hashes are unchanged, and
   `HistoricVideoImporter` re-checks size and SHA-256 per file immediately before
   FFmpeg, so no dispatched byte goes unverified and no config seam is re-added.
7. **Canary operation (2026-08-30).** The canary dispatches under the existing
   operation 3 (`historic-video-full-corpus-20260826`), as the first bounded pass
   of the cumulative operation rather than a separate evidence island.

### Phase 0 — Freeze and inventory the pilot

Create one authoritative, read-only pilot ledger before modifying or deleting anything.

Record:

- exact manifest membership and disposition of all 16 selected identities;
- the relationship between the reported 13 sermons, completed processing runs, pre-existing sermons and failures;
- disk use per identity, separated into durable output, retryable input, concatenation, temporary data and unexplained residue;
- every resulting service, sermon, children's talk, song video, usage record, section and merge proposal;
- current operation state and deadline, plus the legacy cost fields and usage rows as descriptive evidence rather than effective authority;
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

### Phase 4 — Neutralise the internal cost-accounting apparatus

The reservation/settlement design was proportionate when the historic pipeline
used Sol and a single mistaken bulk run could create material spend. The pipeline
now uses Luna, the operator no longer considers model cost a launch risk, and the
existing apparatus does not protect the video pipeline anyway:
`HistoricImportCostLedger` has no production pipeline call sites. Completing it
would add concurrency, retry, pricing-version and currency correctness problems
to prevent a risk now better bounded externally.

Before the canary, neutralise the unused internal cost-control surface:

- stop requiring or writing `max_cost_minor_units` when a historic operation is
  prepared;
- remove cap/currency claims from operation fingerprints, ledgers, closeout
  output and related plans where they imply enforcement that no longer exists;
- preserve model identity and provider-returned token/request telemetry already
  emitted by the actual analysis stages. Do not add pricing snapshots, currency
  conversion, reservations or settlement records;
- retain progressive retry backoff, request rate limiting and the provider-side
  project spending limit. These remain the fail-safe for accidental retry storms
  or a mistakenly enlarged dispatch.

Do not make destructive schema cleanup a launch dependency. Leave the old column,
table, model and compatibility surface inert during the import. At historic
closeout, after proving no production caller depends on them, remove
`HistoricImportCostLedger`, `HistoricImportUsageEntry`, their isolated tests and
the persistence schema using the repository's expand/contract deployment rule.
This avoids spending two deployments on dead storage before the canary while
still ending with no permanent cost-accounting residue.

This simplification does not weaken idempotency: stable request/job keys and the
canary's zero-additional-call replay criterion remain mandatory. Cost telemetry
must not be confused with call deduplication.

**Pre-canary exit gate:** No production or command path requires, reads or writes
the internal cost cap/ledger, the release remains compatible with the old schema,
and model/token/request telemetry plus the external provider limit remain
available. Dropping the inert schema is an IC8 closeout task, not a dispatch gate.

### Phase 5 — Prepare minimum custody instrumentation for the canary

Do not build the previously proposed per-pass Bundle A → transfer → audit →
reclaim protocol unless measured canary evidence proves it necessary. It was a
response to an incorrect capacity premise and would make runtime checkpoints
look like independent evidence rounds.

Build only the minimum custody path the canary needs:

1. Every pass writes services, sections, assertions, provenance and review state
   into the same operation-bound cumulative database.
2. Once a durable output is complete, promote it directly from working staging
   to its permanent private quarantine path using create-only semantics.
3. Verify destination byte size and SHA-256, persist the private destination
   identity on the owning record, and verify the database/media link before
   removing the working copy.
4. Instrument peak working bytes, bytes promoted, bytes retained on staging and
   unexplained residue. Ordinary cleanup may delete only temporary FFmpeg,
   concatenation, extraction and duplicate staging copies whose durable
   destination is verified and which no active, queued or retryable job references.
5. Retain inputs and working assets needed by failed-retryable runs until those
   runs reach a truthful terminal disposition or an explicit operator decision.
6. Never delete source-drive files, private quarantine assets or public assets as
   part of processing cleanup.
7. Re-running the same pass must reuse the cumulative records and promoted
   assets, perform no duplicate model calls, and make cleanup an exact no-op.

The canary in Phase 7 is the design proof: it must report peak working bytes, bytes retained
on staging after promotion, bytes promoted to private quarantine and unexplained
residue. Do not require a comprehensive promotion/cleanup refactor before that
measurement. If the existing path plus the minimum safeguards leaves capacity
safe, no further custody implementation is needed. If it does not, stop and design the smallest exact
change supported by the measured residue; do not generalise from the old pilot
estimate.

Bundle A is not a per-pass advancement mechanism. Generate the authoritative
portable bundle only after the cumulative corpus has converged, optionally split
by release era. If processing and the convergence database are on different
machines, a per-pass bundle may be used purely as transport into the same
cumulative destination graph; it does not close that pass as a mini-corpus.

**Ready-for-canary gate:** Direct promotion is create-only and hash-verified,
cleanup cannot touch sources, quarantine assets or active/retryable work, and the
four byte measures are instrumented. Phase 7 closes the custody question from
measured results; any follow-up must name the residue that justifies it.

### Phase 6 — Make dispatch short-lived and database-owned

#### Pass selection and sizing

- Select passes with immutable `--only` manifest keys, never `--limit`.
- Use 10–16 identities only for the representative canary. It is not a bulk-pass rule.
- After the canary, calculate each bulk pass from a resource envelope: selected source bytes, largest concurrent sources, measured p95 peak working bytes, measured p95 duration, worker concurrency and the chosen 12- or 24-hour operating window.
- Reserve enough disk for the configured minimum-free threshold, the largest selected source, FFmpeg working copies and all concurrent in-flight jobs.
- Do not require three same-sized cycles before changing a pass. Change the membership whenever the same measured byte/time envelope supports it; a pass may contain fewer large identities or more small ones.

#### Verify, copy, enqueue and exit

- Remove `--verify-corpus`. Do not re-read future pass members merely because they share the frozen manifest.
- For each selected item, verify the mount, expected size and SHA-256, then copy the source into operation-owned staging before enqueueing. The worker must never depend on the removable source drive remaining mounted.
- A read/I/O failure stops new copies and dispatches as `aborted_stale_mount`; it does not mark the remaining selection permanently failed. A readable hash/size mismatch remains a source-integrity failure.
- Once selected sources are durably staged and their processing IDs recorded, the command exits. Remove importer polling, `--poll-interval`, `--per-file-timeout` and `waitForInflight()` rather than maintaining a multi-hour outer process.
- Queue workers own execution. A separate operation/pass status report reads processing IDs and truthful terminal dispositions from the database; it never infers completion from the dispatcher still running or from an empty queue.
- Stopping future work means stop invoking the dispatcher. Graceful worker restart remains an ordinary queue operation for jobs already running, not a historic wrapper PID procedure.

#### Queue safety

- Align `PrepareSectionPublicationCandidates`' overlap lock with its 1,800-second timeout plus grace.
- Add bounded/exponential backoff and rate limiting to paid external stages.
- Surface failures, cancellations, timeouts, skips and manual-review outcomes separately, with affected processing IDs and stages.
- Define a bounded retention policy for failed-run working files.

**Exit gate:** The dispatcher verifies and durably stages only the selected sources,
records their processing IDs, enqueues them and exits; status is reproducible from
the database; an interrupted or repeated dispatch resumes without duplicate
records, assets, notifications or provider calls.

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
- the dispatcher exits after all selected sources are verified, durably staged and enqueued;
- every input reaches a truthful terminal disposition;
- titles, references, durations, series policy and speaker-review evidence are correct;
- no projection-generated legacy-item conflicts;
- song and section eligibility behaves correctly;
- model/token/request and peak-disk telemetry are complete per identity;
- measured source bytes, peak working bytes, p95 duration and worker concurrency produce the explicit 12- or 24-hour resource envelope used to size bulk passes;
- the neutral/unobservable transcript rate is measured rather than extrapolated from the six-item calibration set;
- durable outputs are verified in permanent private quarantine and staging retains only bounded active/retryable work;
- the pass's evidence is present in the same cumulative graph as all earlier Email, OpenLP and video evidence;
- re-running the identical canary is a no-op with zero new AI spend.

Any new systemic defect blocks the bulk run. Genuine, enumerated content-review cases do not.

### Phase 7 preparation, 2026-08-30

Prepared and verified; the dispatch and monitored worker run are complete. The canary
was dispatched under operation 3 and resumed after the removable-volume fault described
below.

**Drive.** Mounted via `COMPOSE_FILE=docker-compose.yml:docker-compose.drive.yml`.
`/mnt/cbc-services` shows all 416 date folders read-only; `/mnt/historic-work` is
writable on the dispatcher and all five workers. The first attempt failed on a
stale Docker Desktop `/host_mnt/Volumes/Sonnics` bind entry; remounting the volume
clears it, restarting Docker Desktop does not.

**Pilot repair.** `historic-import:replay-video-pilot-analysis` run against
operations 2 and 3 (21 runs), zero provider calls. Title, slug and duration are
now 21/21; reference 20/21 (888 has none); series 10/21; preacher 21/21. A second
run changes nothing, proving idempotence. Curated titles on 871 and 874 were
correctly refused.

**Two residues remain on the pilot cohort, both pre-existing.** All 21 sermons
still have `asset_disk` null — Phase 5's promotion applies to new runs, and the
pilot's bytes were never promoted out of working staging. All 21 are also
`publication_state = published`, so `SermonExposurePolicy::isWholeContentPublic()`
returns true for every one of them while their assets sit unpromoted on a
removable volume. Repairing their metadata has made them *look* complete on public
surfaces without changing that. Retro-promotion and publication state for the
pilot cohort need an explicit decision before Phase 9.

**Selection (14 identities, 32.8 GB, 9.83 h of content).** Derived from the 470
approved includes minus 22 pilot-touched identities minus 43 whose date already
carries a sermon, leaving a dispatchable pool of 405.

```
--only=2020-04-05-morning,2020-07-12-morning,2021-09-12-morning,2021-12-19-evening,\
2022-01-23-morning,2022-07-24-morning,2022-12-11-evening,2023-07-16-morning,\
2024-03-03-morning,2025-06-08-morning,2025-10-19-morning,2026-04-02-evening,\
2026-05-17-morning,2026-05-24-evening
```

Every Phase 7 stratum is covered: all three corroboration classes, both
concatenation modes, all seven eras, mp4/mkv/webm, both services, evidence
present and absent, songs, children's talks, a >7 GB source, and both sides of the
6 Mbps re-encode threshold. Three constraints shaped it:

- **Only 8 concatenated (`lossless`) identities exist in the whole corpus and the
  pilot consumed 7.** `2026-04-02-evening` is the sole untouched one, so it is
  mandatory in any canary. Its two segments are codec-*matched* (h264/aac both),
  which means **concat codec-mismatch has no untouched representative anywhere**;
  `historic-import:prove-video-reencode-fallback` covers that path in isolation.
- Only 3 pool identities carry Email evidence; all three are selected.
- `2020-07-12-morning` is webm/VP9 reporting **no container duration and no
  bitrate**, which exercises the unreadable-bitrate fail-safe branch of
  `VideoExtractionService::shouldReencode()`.

**Dry run.** Clean: 14 dispatched, 0 skipped, 0 errors, in 23 seconds. The report
returns plan hash `8ecec582…` and manifest hash `1ae7e4fc…`, both matching
operation 3 — confirming a bounded pass binds to the same approved round as a
full one.

### Phase 7 run result, 2026-08-30

**Dispatch and recovery.** Operation 3 (`historic-60b16730090144bd307984abf538a7d7`,
batch `historic-video-full-corpus-20260826`) dispatched the frozen 14-key selection
with the unchanged manifest and plan bindings. The first dispatch stopped after 11
items when the mounted exFAT volume returned `errno=5` during a source hash read.
The volume was unmounted, verified clean with `diskutil verifyVolume`, remounted,
and the Docker bind was revalidated before resuming. The remaining three keys were
then dispatched by the same bounded pass. No further mount or I/O failure occurred;
all four historic queues drained to zero.

**Final database-owned disposition.** Eleven items completed, one is held for manual
review, one failed on content evidence, and one remains unresolved in an orphaned
promotion tail:

- `2023-07-16-morning` is `manual_review`: no speech block met the 20-minute sermon
  threshold.
- `2026-04-02-evening` is `failed`: the stored full-service transcript contained no
  cues. This is a truthful content failure, not a mount failure.
- `2024-03-03-morning` remains `processing` at the notification-skipped tail after
  its `promoting_historic_assets` step was orphaned by the interruption; there is no
  queued job to resume it through an official command.

The other eleven items, including the approved re-extraction runs for `2020-07-12`,
`2021-12-19` and `2022-01-23`, are database-complete. `2026-05-17` was not re-cut:
its source was already cleaned up by the completed run, so no additional overwrite
was attempted.

**Approved overwrite verification.** The re-extraction command was run with its
guarded `--yes` overwrite path only for the three source-available conflicts. The
resulting files are valid media in permanent private quarantine:

| Sermon | Quarantine output | Verified bytes | Verified duration | SHA-256 |
|---|---|---:|---:|---|
| #898 — 2022-01-23 morning | `sermons/898/video.mp4` | 302,346,096 | 1,938.613 s | `c509a426962aec0767ab45f7df5dfa5c8855c20985d5b730dd0a7b91bd309d68` |
| #899 — 2021-12-19 evening | `sermons/899/video.mp4` | 152,303,373 | 822.729 s | `e5cb48f035e75c1551e3e614bdd66477615ca25aa6d034a45f24e8f3e7215f22` |
| #901 — 2020-07-12 morning | `sermons/901/video.mp4` | 116,033,352 | 1,785.002 s | `7eedf051364df6edbccced0adad8c871137c27326bdee6835c34c4b6a56ee5c3` |

The affected database rows now identify `asset_disk = historic_quarantine` and
`publication_state = quarantined`; no live public release occurred. The pre-existing
#893 output remains unchanged at 654,775,915 bytes and 1,128.645 seconds because its
source was unavailable for a safe re-cut.

**Custody measures.** The final read-only status report recorded peak working bytes
of 50.46 GiB, 3.85 GiB promoted during this run, 0 bytes retained on staging, and
4.41 GiB held in quarantine. It also reported 24.68 GiB of unexplained residue,
with 0 bytes attributed by the run-accounting line. That non-zero residue is recorded
as a reconciliation finding rather than treated as clean custody evidence.

**Acceptance outcome.** Phase 7 is operationally complete but does not pass its
acceptance gate. The verified replacement files have durations of 1,938.613 s,
822.729 s and 1,785.002 s, while the corresponding sermon metadata still records
2,124 s, 986.99 s and 1,786.009 s. The unresolved #2024-03-03 promotion tail and
the non-zero custody residue also remain. The identical-canary zero-additional-AI-
spend replay was not run. These findings block the Phase 8 bulk run; no further
historic-video dispatch is authorised until they are reconciled.

### Phase 7 remediation implementation, 2026-08-30

The code-side blockers are repaired, but no production row or asset has been
changed by this implementation work:

- Existing-sermon refresh now derives duration from
  `MediaProcessingLog::extractedSermonMediaDuration()`. A concatenated extraction
  records the sum of emitted spans, not the wall-clock window across omitted gaps,
  and curated sermon fields are unchanged.
- `historic-import:repair-video-sermon-durations` repairs already-completed,
  private operation-owned sermons from that same banked extraction plan. It is an
  exact-ID, dry-run-first, duration-only operation and dispatches no jobs or model
  requests. `--apply` requires `--yes`; an identical replay is a no-op.
- `historic-import:recover-processing-tail` recovers only the idempotent
  `PromoteHistoricAssets` → `CleanupTemporaryFiles` tail of a stale operation-bound
  run. It rejects fresh, terminal, non-historic, wrong-stage, wrong-operation and
  staging-context-mismatched rows. A row-locked recovery claim prevents duplicate
  dispatch.
- Custody measures now activate the operation's recorded
  `historic-batches/{planHash}` staging context. Previous batches and files outside
  that root are excluded; temporary files, sermon assets, RMS logs and retained
  service artifacts owned by the operation are accounted; unknown files inside
  the active batch remain residue.
- `historic-import:repair-video-pilot-custody` provides the fail-closed repair for
  the 21 pre-promotion pilot sermons. It requires the exact owning operation and
  exact completed processing IDs, is dry-run-first, quarantines all valid rows
  before copying, and delegates create-only/hash-verified promotion and staging
  reclamation to the existing custody service. A failed promotion leaves the row
  private and its staging source retained; a repeated successful repair is a no-op.

Focused verification covers 100 tests and 380 assertions across dispatch,
recovery, duration, promotion, custody measures and status. PHPStan reports zero
errors and Pint is clean. The full parallel suite ran 7,436 tests: 7,435 passed
and the existing committed-JSON baseline scan failed because one parallel worker
observed no tracked Git files. That exact test immediately passed alone with 28
assertions, and the companion one-shot deletion-trigger test passed with three;
no historic-processing test failed. Treat the parallel-only scan as a quality-gate
environment finding, not as Phase 7 acceptance evidence.

#### Required operator sequence

1. Deploy this exact tree and restart every historic worker so the dispatcher,
   jobs and commands share one fingerprint.
2. From `historic-import:video-pass-status`, retain the exact processing ID for
   `2024-03-03-morning`. Dry-run the status again, then run
   `historic-import:recover-processing-tail <processing-id> --operation=historic-60b16730090144bd307984abf538a7d7`.
   Wait for promotion and cleanup to reach a truthful terminal state.
3. Retain the exact processing IDs linked to sermons 898, 899 and 901. Run
   `historic-import:repair-video-sermon-durations --operation=historic-60b16730090144bd307984abf538a7d7 --processing-id=<id>`
   once per exact ID set, review the dry-run table, then repeat with `--apply --yes`.
   Metadata must equal the recorded extracted-span duration. FFprobe may differ
   by at most 1.5 seconds for container/timestamp rounding; any larger difference
   remains a blocker.
4. Split the 21 pilot processing IDs by their exact owning operation (2 or 3).
   For each operation run `historic-import:repair-video-pilot-custody` with every
   exact `--processing-id`, retain the dry-run table, then repeat with
   `--apply --yes`. Verify every repaired sermon is `quarantined`, names
   `historic_quarantine`, belongs to that operation, resolves every asset there,
   and retains no duplicate staging copy.
5. Re-run `historic-import:video-pass-status --measures` for the frozen 14 keys.
   Export the path census for every non-zero byte: owned active/retryable work,
   named operation artifact, platform sidecar or genuine orphan. Do not delete a
   path merely because the earlier unscoped report called it residue.
6. Record the acceptance evidence still absent from the first result: per-identity
   title/reference/duration/series/speaker audit, projection and song eligibility,
   model/token/request counts, neutral/unobservable transcript rate, elapsed and
   p95 duration, observed worker concurrency, and the resulting 12- or 24-hour
   resource envelope.
7. Dispatch the identical frozen 14-key selection. It passes only if it creates no
   processing identity, provider call, asset or notification, all 14 identities
   retain truthful terminal dispositions, and custody residue is zero or exactly
   enumerated. Only then may Phase 8 start.

### Phase 8 — Process the remainder as a closed pass loop

For each pass:

1. Preflight the mount, selected-item hashes, operation-bound host disk evidence, workers, models, provider project limit, manifest keys and the measured byte/time envelope. Do not hash unselected future items.
2. Verify and durably stage only those immutable keys, enqueue them, record their processing IDs and let the dispatcher exit.
3. Read pass status from the database and monitor terminal outcomes, provider request/token anomalies, drive health and disk watermark.
4. Stop new dispatch immediately for mount instability, unexpected provider-call growth, unexplained duplicates, destination mismatch or recurring systemic failure. Gracefully stop workers only when already-running jobs themselves must be halted.
5. Reconcile services, sermons, sections, songs and review residue.
6. Verify completed assets in permanent private quarantine and their database/operation ownership.
7. Clean only verified temporary copies no active, queued or retryable run references.
8. Record peak working bytes, staging residue and remaining manifest membership.
9. Re-census the cumulative graph for diagnostics; do not treat a partial-pass census as final convergence.
10. Start the next pass only after the prior pass has truthful terminal dispositions and bounded residue.

The pass report must name every non-zero residue. An empty queue is not evidence of successful completion. Pass closure is operational only: all evidence remains active in the same cumulative corpus for later cross-source convergence.

**Exit gate:** Every approved manifest item is completed, explicitly held/excluded, or named as unresolved; no pass retains unexplained staging data.

### Phase 9 — Final convergence and public release

After all processing passes:

1. Regenerate corpus membership and the proposal census.
2. Re-evaluate the previously uncorroborated services and disagreements against the new full-grade video evidence.
3. Resolve surviving proposals by class rather than by repetitive individual review where a safe deterministic rule exists.
4. Complete editorial QA for titles, slugs, references, series, speakers, songs, children's talks and occasions.
5. Audit exact assets, Scripture settlement, quarantine visibility and notification containment.
6. Regenerate the hymn-usage apply artifact against this exact converged graph, so spreadsheet evidence is compared with all Email, OpenLP and video evidence rather than with individual passes.
7. Generate and verify the authoritative Bundle A, optionally split by release era for transport and release handling.
8. Resolve the current seam between the incremental-round plan and the release command's requirement that the operation be `Complete`. Do not manufacture completion state to bypass it.
9. Complete the required truth-set spot checks and public acceptance journeys.
10. Create separately signed, era-sized release authorisations.
11. Run `historic-import:release-batch --dry-run` before each public release.
12. Release only the exact authorised membership, observe it for the recorded rollback window, and retain the release ledger.
13. At IC8 closeout, remove the inert cost column/table, ledger model/service and isolated tests together with the remaining one-shot historic-import surface.

Public release remains a separate human-authorised act. It is never a side effect of processing, private promotion, temporary cleanup or bundle generation.

## 4. Go/no-go summary

The remainder may start only when all of these are true:

- [ ] Pilot membership and disk ownership reconcile exactly.
- [x] Title, Scripture, duration and slug application pass real-shape regression tests.
- [x] Service projection no longer conflicts with its own evidence.
- [x] Fragmentary/duplicate song candidates fail closed to review.
- [x] Production and command paths no longer require, read or write the internal cost cap/ledger; inert schema deletion is assigned to IC8 closeout, while model/token/request telemetry, retry controls and the provider-side project limit remain.
- [x] Unmeasurable staging capacity fails closed unless sufficient operation-bound host evidence is supplied.
- [x] A content-read I/O failure aborts further dispatch as a stale-mount event.
- [x] Banked pilot analysis repairs the completed pilot records with zero provider calls and is idempotent.
- [x] The dispatcher verifies and durably stages selected sources, records processing IDs, enqueues and exits; no worker depends on the removable source mount and no whole-corpus verification remains.
- [ ] A fresh untouched canary passes all acceptance criteria.
- [ ] The canary proves direct private promotion and bounded temporary cleanup without fragmenting the cumulative evidence graph.
- [ ] The canary's measured byte/time envelope, not an identity-count rule, sizes the first bulk pass.
- [ ] Re-running that canary is an exact no-op with zero additional model spend.

Until then, the remaining historic-video dispatch is **NO-GO**.
