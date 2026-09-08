# Historic Video Pilot-to-Bulk Plan

> **Latest evaluation, 2026-09-07, 14:32–14:41 UTC:** Processing has drained:
> 409 completed and four failed, with zero degraded completions, in the active
> operation-4 cohort. The 60-transcript repair is closed operationally. However,
> sampling found a completed sermon analysed from only its closing prayer, two
> generated single-song clips containing two songs, and further false frozen-video
> rejections. See the [post-run evaluation](#post-run-evaluation-2026-09-07--accuracy-and-weekly-automation)
> for the current census, sample membership and prioritised follow-ups. Completion
> is not release readiness. Speaker identification remains deliberately paused.
> Earlier status paragraphs and checklists are dated history unless restated there.

**Date:** 2026-08-29
**Historical status, 2026-09-02:** **Phases 0–7 complete; step 11 closed; Phase 8 is GO at FFmpeg width one.** The Phase 7 canary ran under operation 3, its blockers were implemented and its rows and assets repaired, and on 2026-09-01 the **operator sequence reached step 10: the identical-canary replay passed**, proving zero new work and zero spend (evidence: `storage/scratch/historic-video-step10-noop-proof-20260901.md`). On 2026-09-02 **step 10 was re-run against the re-frozen manifest** `d25d2085…` under operation 4 and passed again — 0 dispatched, 12 skipped, 0 B processed in 7.2 s, every baseline count unchanged (evidence: `storage/scratch/historic-video-step10-rerun-proof-20260902.md`). The re-freeze reduced the replayable set from fourteen to twelve: `2026-04-02-evening` became a manifest-level exclusion, and `2023-07-16-morning` had its source replaced and its run retired, so it is new work rather than a replay and is **deferred to Phase 8 by operator decision**. **Step 11 (M12's four-identity calibration at FFmpeg width two) ran to completion on 2026-09-02** after the VirtioFS/exFAT mount fault was fixed: 3 of 4 identities completed cleanly (the 4th stopped at a genuine content-layer manual-review disposition, not a technical fault), and the mount held through the exact step that had killed all four the day before. **M12 item 14's gate FAILS**: queue-wait p95 improved 44–98% on every instrumented FFmpeg step, but active-duration p95 got materially worse on the two full-file steps (`extract_sermon` +69%, `prepare_section_publication_candidates` +94%) — confirmed by source-size-normalized throughput, not a bigger-files artefact — so items/hour moved only +1.7%, far short of the 25% bar either metric requires. Per the plan's own fallback, **width was reverted to one** (`.env`, workers recreated, dispatcher config confirmed). Evidence: `storage/scratch/historic-video-step11-calibration-result-20260902.md`. **Bulk processing (Phase 8) can now proceed at width one** — the only width ever proven clean. **On 2026-09-02 the first stratified learning batch (11 identities) ran and returned 5 failed, 6 degraded, 0 clean**: provider 429s failed every structure-detection attempt they touched and made the transcript stage bank empty fallback analysis that reports as `completed`. **Those 429s were diagnosed on 2026-09-02 and are NOT rate limiting**: every one is `service_tier: flex` capacity unavailability (`code: flex_unavailable`, `retry-after: 300`), reproduced live with a 7-token request while the account held 99.98% of both its request and token budgets. Flex capacity is per-model and independent of this project's load; `gpt-5.6-luna` — the structure-detection model — is refused 0/8 on flex and 3/3 on default. Evidence: `storage/scratch/pass1-rate-limit-diagnosis-20260902.md`. The pass also exposed two operational faults — worker daemons that stop honouring `queue:restart`, and a first-job failure that strands a run in a state no retry path accepts. **Both of pass 2's blockers were cleared the same day**: P1-1 falls a `flex_unavailable` 429 back to `service_tier: default` and logs the provider's real error code and headers (verified live while luna's pool was still empty), and P1-2 makes a degraded completion its own `degraded` disposition, names it in the pass report, keeps it out of clean throughput, and makes `ProcessTranscriptWithAI`'s previously-unreachable retry schedule real. **Pass 2 is unblocked. P1-3 and P1-4 are both done, same day.** P1-4: `HistoricImportUsageEntry`, `HistoricImportCostLedger`, the `historic_import_usage_entries` table and the usage-reporting lines in `HistoricVideoPassStatusCommand`/`HistoricVideoPassPerformance` are deleted rather than repaired — the table was empty throughout pass 1, so nothing was lost. P1-3: sermons 907–912 are genuinely re-analysed, but the plan's own premise — "the service transcripts survive" — needed one correction first. The transcript survived, but on the sermon's own `asset_disk` (`historic_quarantine`), not on any of `TranscriptStorageService`'s hardcoded candidate disks; a naive re-dispatch would have re-banked six more hollow completions for a second, different reason. Fixed the disk resolution and a second real bug — `is_degraded_completion` never cleared on a genuine success — then re-dispatched for real: all six now carry real titles, references, summaries and points, verified against the database, not just the flag. All four quality gates pass (Pint, PHPStan, 7676 tests, 55 Dusk tests). See “Pass 1 — first stratified learning batch, 2026-09-02”.
**Scope:** Correct the pilot findings, prove direct private asset promotion and bounded temporary cleanup, run a fresh canary, and process the remaining historic-video corpus safely
**Related plan:** `HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md` remains the authority for the wider historic-import programme

## 1. Decision

**Current decision, 2026-09-07:** the bulk processing run is finished; do not
redispatch the corpus to clear editorial or supersession obligations. Recover
specific defective evidence and outputs, then complete Phase 9 release QA.
The following rationale records the original pre-bulk decision.

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
| 6 — Copy-and-enqueue dispatch | Complete | Commit `6c6b0a7a8` removes polling, adds operation-bound capacity evidence, and aborts stale mounts. Commit `3cb189f5b` adds the database-owned `historic-import:video-pass-status` report and the content-read-I/O regression test. **Its whole-corpus-verification claim was false until 2026-08-30**: `6c6b0a7a8` deleted the opt-in `--verify-corpus` flag *and* the argument it passed, so `plan()` fell back to its `true` default and every invocation — dry runs and `--only` passes included — re-read ~1.0 TB. Now fixed at the call site with `verifySourceContents: false`, proved by `a_bounded_pass_does_not_read_the_contents_of_unselected_sources`. A 14-item dry run went from over three hours to 23 seconds. **Superseded 2026-09-01:** the parameter and its `hash_file()` branch are deleted outright — the only other caller ran after `writeOnce()` and could re-read nothing but files hashed seconds earlier, so `capture-video-curation` was reading the corpus twice. |
| 7 — Fresh canary | **Complete** | Operation 3 was dispatched and resumed after a stale-mount abort; the duration, orchestration, title, boundary and custody defects it surfaced were implemented and its rows and assets repaired. The identical-canary replay passed on 2026-09-01: dispatched 0, resumed 13, skipped 1, errors 0, **0 B processed**, every baseline count unchanged, in 3.9 seconds. Evidence `storage/scratch/historic-video-step10-noop-proof-20260901.md`. **Re-proved 2026-09-02** under operation 4 and the re-frozen manifest `d25d2085…`: dispatched 0, skipped 12, errors 0, **0 B processed**, in 7.2 seconds, with the before/after baseline JSON differing only in its timestamp. Evidence `storage/scratch/historic-video-step10-rerun-proof-20260902.md`. |
| 8 — Bulk processing | Drained; output QA remains | 2026-09-07 final evaluation: 409 completed / four failed active operation-4 runs; see the current evaluation below. |
| 9 — Convergence and release | Outstanding | Processing completion does not authorise public release. |

#### M9, M5 and M7 implemented, 2026-09-01

The three remaining pre-bulk findings landed as `9504501d3` (M9), `8431306ce`
(M5 song boundary evidence), `094640f4b` (M7) and `f6535a278` (review fixes on
the first three). A review of that work found two blockers and two defects, all
now fixed in the working tree:

- **[BLOCKER] The sermon boundary gate halted whole runs, and was not scoped to
  the historic lane.** `ExtractSermon::routeSermonBoundaryReview()` called
  `markProcessingRunForManualReview()`, cleared `$this->chained` and emailed, so
  a material-risk boundary ended the run at `ExtractSermon` — before
  `PrepareSectionPublicationCandidates`, promotion and cleanup. The service
  produced no sermon, no songs, no analysis and no boundary evidence, and its
  staging working set was never released (then pinned by M9's own retention
  predicate). Replayed over every service in the database this fired on **8 of
  63 (12.7%)** — **5 of 28 ordinary weekly livestreams (17.9%)** and 3 of 35
  historic runs — against a plan that had already rejected a 16.7% *section-level*
  review rate as too costly. `ExtractSermon` now records the evidence, sets
  `needs_manual_review` and `FLAG_SERMON_BOUNDARY_MATERIAL_RISK` on the sermon
  section, and **continues the chain**, which is what "routes a sermon to review"
  asks for. Consequently `SermonAutoExtractionPolicy` no longer treats that flag
  as disqualifying — it never should have, because refusing to extract a flagged
  section leaves a replay with no sermon at all to review.
  `FLAG_SERMON_INTERRUPTION_MERGED` keeps its disqualifying behaviour.
- **[BLOCKER] The M7 recut ran without the historic staging context.**
  `PrepareSectionPublicationCandidates::dispatchStandalone()` is called from a
  web request, where no `HistoricStagingContextRegistry` context is active, so
  `queuePayload()` returned `[]` and the worker resolved `source_file_path`
  against the plain disk root rather than the run's batch root — the same failure
  shape as the historic retry bug fixed in `23bcea58f`. The dispatch now runs
  inside the run's own recorded context, exactly as
  `ProcessingRunOrchestrator::withRecordedStagingContext()` does.
- **The long-tail risk was a duration trigger wearing a corroboration label.** It
  required only that *some* non-trailing section followed the tail, which is the
  closing song present in nearly every service. It now requires the absorbed
  section to be attested by a source other than this recording
  (`provenanceSources()` minus `livestream`) — the non-duration evidence the
  finding actually asks for. All four long-tail hits in the corpus were of the
  spurious kind and are gone.
- **The song trailing-tail test could not match a real benediction.**
  `trailingObservation()` required a wordless gap immediately before the *final*
  cue and measured that cue's own duration against a 10-second floor. A
  benediction arrives as several short cues, so M5's headline case — section
  907's ~27-second tail — matched nothing. It now takes the last wordless gap
  inside the tail window whatever follows it, and measures the trailing *span*
  (`minimum_trailing_content_seconds`).
- **A storage failure was indistinguishable from absent evidence.**
  `SongPublicationBoundaryEvidenceService` mapped every `Throwable` to
  `status = 'unavailable'` and thence to a review hold, with nothing logged. An
  unmounted volume or a misconfigured disk would silently convert a whole pass
  into a review backlog — and, through M9, pin every source on the bottleneck
  disk. Storage errors are now named `song_boundary_evidence_unreadable`, carry
  `storage_error` in the per-side evidence, and are logged at warning level;
  genuine absence stays `song_boundary_evidence_unavailable`. Both still hold the
  clip, which is the correct fail-closed posture.

After the fixes the sermon boundary flags **3 of 35 historic runs (8.6%)** and
**1 of 22 ordinary runs (4.5%)** for section-level review, halting none of them,
and every risk is the genuine `sermon_boundary_multiple_following_items` class.
Pint, PHPStan, the full parallel suite and Dusk are green.

**Not measured, and the remaining known risk in this area:**
`leadingObservation()` takes the first wordless gap in the candidate whenever a
cue starts within five seconds of the section start, so a hymn whose singing
begins at the boundary and which contains an inter-verse instrumental pause of
three seconds or more can be held as "spoken framing". The plan's own rule — keep
the inclusive clip and review when the first gap falls beyond
`max_spoken_framing_seconds` — is implemented as written and left as written. Its
false-positive rate could not be measured locally because the archive volume was
not mounted, so **this rate must be read off the Phase 7 canary re-run** (step 10)
against the eleven named M5 sections before the bulk pass is sized.

#### Phase 7 remediation implemented, 2026-08-31

M1–M6 and M10–M12 are implemented with focused coverage; PHPStan, Pint, the full
parallel suite and Dusk are green. A review of that work against this plan found
three defects and three inaccuracies, all now fixed:

- **`CleanupTemporaryFiles` failed a run on a tolerated speaker failure.** The M2
  guard read a `failed` `identifying_speaker` step as terminal unsettled work and
  called `markAsFailed` on the whole run. `IdentifySpeaker` records that state
  deliberately and continues — a deterministic failure is non-blocking and falls
  back to `Visiting Speaker` — so a run that produced good media would end
  `failed` *with cleanup skipped*, retaining exactly the staging bytes the guard
  exists to protect. The guard now blocks only on genuinely active work
  (`pending`/`started`/`processing`); a failed step has released its inputs.
  Regression: `it_cleans_up_after_a_non_blocking_historic_speaker_failure`.
- **The M1 canary repair could not run.** `HistoricVideoSermonDurationRepair` now
  reads `trim.observed_duration`, which only `ExtractSermon` writes, so every row
  the repair exists to fix — extracted before that key existed — failed closed on
  "no positive observed extraction duration". It now measures the durable
  promoted asset through the shared probe and banks the result at the same key,
  which is what step 6's "repair from verified assets" asks for: one FFprobe, no
  re-extraction and no new analysis. A missing or unreadable asset still fails
  closed.
- **The M3 canary repair had no mechanism.** Provenance is deliberately not
  backfilled, so 898 and 899 remain null, and their titles are precisely the
  shapes `PlaceholderSermonTitle` refuses — meaning banked analysis kept refusing
  them and nothing could set `Generated`. New command
  `historic-import:repair-video-sermon-title-provenance`
  (`RepairHistoricVideoSermonTitleProvenanceRepair`), matching the option surface
  of the two existing repair commands, *proves* provenance by recomputing the
  filename-derived title from the run's original filename, date and service slot
  and recording `Generated` only on an exact match. Anything else is refused and
  left null, because a non-null title is not editorial authority merely because
  it exists.
- The title-replacement policy moved to `Sermon::titleMayBeReplacedByAnalysis()`;
  it was duplicated verbatim in the live and banked writers, which could have
  drifted into disagreeing about which titles are safe to replace.
- `song_videos.asset_disk` was missing from the committed schema dump. Rebuilt by
  the documented procedure (committed dump + pending migrations only, never the
  drifted dev DB); the diff is that one column plus its migration row.
- `HistoricProcessingFingerprint::LEGACY_FORMAT` implied a version gate it does
  not implement — the format tag is unchanged, so tolerating `throughput` is a
  property of that field, not of an older schema. Renamed and documented
  truthfully; behaviour is unchanged, and the format tag stays put deliberately
  because bumping it would mark every processed run stale for no byte-level gain.

#### Second review pass, 2026-08-31 — four acceptance gaps closed

A second review found four material gaps against the plan's own acceptance
criteria, none of which the existing tests exercised:

- **[P1] M4 promoted song videos but not review-held clips.** Promotion
  enumerated `SongVideo` rows only, so the nine canary held candidates stayed on
  the working volume with nothing recording where they live — neither
  attributable in a custody census nor reclaimable, which is the whole point of
  M4 item 4's "release-eligible **and** review-held". `HistoricAssetPromotion`
  now promotes held `ServiceSection` candidates too, through the same
  create-only copy, size verification and staging reclaim. A new
  `service_sections.asset_disk` mirrors the `sermons`/`song_videos` columns and
  `ServiceSection::extractedAssetDisk()` — already the single resolution seam —
  prefers it. **`publication_status` is untouched**: promotion is a custody
  transition, not a review decision, so the approval gate is exactly as strict as
  before. The custody repair now also selects runs that hold candidates without
  any pending song video, which it previously skipped entirely.
- **[P1] M1's dry run mutated durable metadata.** `inspect()` measured a legacy
  asset and immediately saved `trim.observed_duration`, before the command had
  even looked at `--apply` — a default-safe command writing durable state and
  then reporting that nothing changed. Measurement and banking are now split:
  inspection is read-only and reports the value's source (`banked` or
  `measured`), and only `apply()` banks, inside its locked transaction.
- **[P2] M11 still opened each selected single source before its staging copy.**
  `historicImportMetadata()` FFprobed the archive path for `codec_fingerprint`,
  and MIME detection then read the original `UploadedFile` again — failing the
  plan's "one content open: the source-to-staging copy" proof and paying that I/O
  on the removable drive. The codec fingerprint is now deferred: the importer
  marks it `codec_fingerprint_source = staged_copy` and
  `LivestreamSegmentationService` fills it from the staged file once that copy is
  closed and size-verified. MIME for a historic dispatch comes from the staged
  copy too. Concat still probes its sources, correctly — comparing codecs is what
  decides whether a lossless concat is even possible.
- **[P2] M12 attributed current worker widths to completed runs.** The report
  read `configuredWidths()` from live configuration, so a retrospective produced
  after changing or reverting widths described the machine rather than the pass.
  It now summarises the execution profiles persisted with the selected runs as
  `observed_worker_widths`, with explicit `uniform`/`mixed`/`missing` status,
  the distinct values seen, and a `runs_missing_profile` count that stays visible
  even when the profiles that exist agree. Current configuration is still
  reported, separately and labelled as such.

`service_sections.asset_disk` is excluded from `HistoricNormalOutputContract`
for the same reason as its counterparts — the audience boundary and storage
layout are destination decisions — which the contract's own schema-coverage test
required to be stated explicitly.

One review finding was withdrawn on evidence: FFprobing
`Storage::disk($tempDisk)->path(...)` in `ExtractSermon` is not an S3 hazard,
because `VideoExtractionService` already writes its output through the same call.
The whole extraction path requires a local-path-capable temp disk, `temp_disk`
defaults to `local`, and an unreadable path fails closed with a clear message.

Still outstanding, and all operator work: **M12 items 12–14 only** — the
four-identity two-worker calibration pass (operator sequence step 11). M12 item 11,
the operation-3 retrospective report, was produced on 2026-09-01
(`historic-video-canary-performance-20260901.json`), and operator sequence steps
2–10 are complete.

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
`--per-file-timeout`, and no longer waits for workers. At that commit every
selected source was size- and SHA-256-checked immediately before staging; M11 and
decision 9 intentionally supersede the content-hash half of that implementation.
A missing/unreadable source still aborts further dispatch as
`aborted_stale_mount`, while a readable size mismatch remains an identity-level
integrity failure.

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
   inspect only that pass's selected source paths and metadata before their
   durable copy. Decision 9 below supersedes the original requirement to re-hash
   selected source contents.
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
   SMART data over USB, so filesystem verification plus ordinary read/copy
   failures are the available drive-health signals. A content hash is not a
   meaningful health monitor for a mount that disappears loudly.
6. **Verification scope fix (2026-08-30).** *Superseded 2026-09-01 — the parameter is now deleted;
   see decision 12.* Fix at the call site by passing
   `verifySourceContents: false` rather than restoring a `--verify-corpus` flag.
   Existence, symlink, root-containment and byte-size checks still run for every
   manifest entry, and the manifest and plan hashes are unchanged. Decision 9
   removes the later selected-file content re-read as a deliberate
   proportionality decision; no config seam is re-added.
7. **Canary operation (2026-08-30).** The canary dispatches under the existing
   operation 3 (`historic-video-full-corpus-20260826`), as the first bounded pass
   of the cumulative operation rather than a separate evidence island.
8. **Sermon ending versus closing-song introduction (2026-08-30; refined
   2026-08-31).** Prefer an inclusive sermon ending. A closing-song introduction
   may be the sermon's rhetorical conclusion, so an `other` classification, a
   generated title such as "Closing Song Introduction", proximity to a song or a
   transcript phrase such as "let's sing" is not enough authority to truncate it.
   Stop the sermon automatically only where affirmative timed evidence establishes
   that the following material is a separate item. Otherwise retain a short,
   adjacent spoken bridge automatically when it plausibly serves as both sermon
   conclusion and song introduction. Ambiguity alone is an acceptable inclusive
   result, not a review reason. Require review only for material-risk evidence:
   conflicting boundaries, clearly unrelated content, multiple following items
   merged into the sermon, or an unusually long tail corroborated by evidence
   other than duration alone. Do not add a provider call or a blanket review gate
   to make this decision; use the existing service-structure evidence and record
   the boundary decision and its evidence. Sermon and song assets need not
   partition the source: the sermon may retain the rhetorical introduction while
   the song asset starts later at the singing.
9. **Routine historic-video hash reads (2026-08-31).** Remove them from bounded
   dispatch and ordinary new-asset promotion. The frozen manifest retains the
   SHA-256 values captured when the corpus was approved, but a pass does not
   re-read selected source contents merely to prove they still have those values.
   The accepted residual risk is that a same-size, silently corrupted or replaced
   file could pass path/size checks and be processed. That event is judged
   extremely unlikely in this one-operator, non-adversarial import; the archive
   originals remain unchanged through closeout, generated assets remain private,
   FFmpeg/media review catch most material defects, and an affected identity can
   be reprocessed. The observed risk is loud mount/I/O failure, which ordinary
   copy operations already expose. Hash only on an existing-destination conflict,
   where exact equality distinguishes an idempotent replay from a refusal, or
   during a targeted investigation. Bundle/export/transport hashes are outside
   this decision because they protect portable evidence crossing a machine or
   trust boundary after processing, not throughput during the bulk run.

### Phase 0 — Freeze and inventory the pilot

Create one authoritative, read-only pilot ledger before modifying or deleting anything.

Record:

- exact manifest membership and disposition of all 16 selected identities;
- the relationship between the reported 13 sermons, completed processing runs, pre-existing sermons and failures;
- disk use per identity, separated into durable output, retryable input, concatenation, temporary data and unexplained residue;
- every resulting service, sermon, children's talk, song video, usage record, section and merge proposal;
- current operation state and deadline, plus the legacy cost fields and usage rows as descriptive evidence rather than effective authority;
- deployed commit and durable processing fingerprint, including the byte-affecting
  models, reasoning effort, size limits and storage roots; record queue routing
  and configured worker widths separately as the execution profile required by
  M12.

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
3. Verify destination byte size, persist the private destination identity on the
   owning record, and verify the database/media link before removing the working
   copy. Hash only when a destination already exists and exact equality must
   distinguish an idempotent replay from a conflict.
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

**Ready-for-canary gate:** Direct promotion is create-only and size-verified,
cleanup cannot touch sources, quarantine assets or active/retryable work, and the
four byte measures are instrumented. Phase 7 closes the custody question from
measured results; any follow-up must name the residue that justifies it.

### Phase 6 — Make dispatch short-lived and database-owned

#### Pass selection and sizing

- Select passes with immutable `--only` manifest keys, never `--limit`.
- Use 10–16 identities only for the representative canary. It is not a bulk-pass rule.
- After the canary, calculate each bulk pass from a resource envelope: selected source bytes, largest concurrent sources, measured p95 peak working bytes, measured p95 duration, worker concurrency and the chosen 12- or 24-hour operating window.
- Reserve enough disk for the configured minimum-free threshold, every selected
  input not already staged and the configured number of concurrent FFmpeg working
  sets. Retained review sources are already reflected in current free space: name
  them in the evidence, but do not add their bytes to the requirement again.
- Do not require three same-sized cycles before changing a pass. Change the membership whenever the same measured byte/time envelope supports it; a pass may contain fewer large identities or more small ones.

#### Verify, copy, enqueue and exit

- Remove `--verify-corpus`. Do not re-read future pass members merely because they share the frozen manifest.
- For each selected item, verify root containment, absence of symlinks, regular-file existence and expected byte size, then copy the source into a unique operation-owned staging path before enqueueing. Verify the copy call succeeded and the closed destination has the expected byte size. Do not re-hash the source or the ordinary new destination. The worker must never depend on the removable source drive remaining mounted.
- A read/I/O failure or short/long copy stops new copies and dispatches as `aborted_stale_mount`; it does not mark the remaining selection permanently failed. A readable byte-size mismatch remains a source-integrity failure. Same-size silent corruption/replacement is the explicitly accepted residual risk in decision 9.
- Once selected sources are durably staged and their processing IDs recorded, the command exits. Remove importer polling, `--poll-interval`, `--per-file-timeout` and `waitForInflight()` rather than maintaining a multi-hour outer process.
- Queue workers own execution. A separate operation/pass status report reads processing IDs and truthful terminal dispositions from the database; it never infers completion from the dispatcher still running or from an empty queue.
- Stopping future work means stop invoking the dispatcher. Graceful worker restart remains an ordinary queue operation for jobs already running, not a historic wrapper PID procedure.

#### Queue safety

- Align `PrepareSectionPublicationCandidates`' overlap lock with its 1,800-second timeout plus grace.
- Add bounded/exponential backoff and rate limiting to paid external stages.
- Surface failures, cancellations, timeouts, skips and manual-review outcomes separately, with affected processing IDs and stages.
- Define a bounded retention policy for failed-run working files.

**Exit gate:** The dispatcher metadata-checks and durably stages only the selected sources,
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
- the dispatcher exits after all selected source paths/sizes are checked, copied, destination-size-verified, durably staged and enqueued;
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

### Phase 7 initial remediation implementation, 2026-08-30

The blockers known from the first status reconciliation are repaired in code, but
no production row or asset was changed by this implementation work. The later
media-output evaluation below found additional blockers; this section must not be
read as claiming Phase 7 is code-complete:

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
  before copying, and delegates create-only/size-verified promotion and staging
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

### Phase 7 media-output evaluation and bulk decision, 2026-08-30

The canary media and its stored graph were reviewed after the initial remediation.
This was a read-only evaluation: it changed no row, asset or operation state. The
review covered every produced canary asset rather than only the three replacement
videos:

- 12 sermon videos: the eleven database-complete sermons plus the stranded
  `2024-03-03-morning` video on staging;
- 32 song videos: 23 `SongVideo` rows automatically marked `published` and nine
  extracted candidates correctly held for approval or review;
- four extracted children's-talk candidates; and
- the timestamped normalized service transcripts, section boundaries, extraction
  plans, AI analysis, speaker evidence, quality verdicts, FFprobe duration/codec/
  audio results, storage paths and operation ownership for those assets.

Every reviewed file was decodable and carried audio. The sermon bodies were
visually coherent, the song identities were generally supported by lyrics or
transcript, and the four children's-talk candidates contained the expected talk.
The canary also behaved correctly in several important fail-closed cases:

- `2023-07-16-morning` remained manual review because no speech block met the
  20-minute sermon threshold;
- `2026-04-02-evening` truthfully failed because its full-service transcript had
  no cues;
- sermon 903's very dark video was rejected as `mostly_black`; and
- short, partial, adjacent or inferred song cases such as sections 802, 806, 813,
  820, 832, 846 and 872 were not automatically published.

Those successes do not offset the systemic findings below. Phase 7 remains
**NO-GO** for bulk.

#### Finding M1 — extracted media, not planned wall-clock span, is duration authority

Fresh concatenated sermons still store the outer source window rather than the
length of the emitted media. `SermonCreationOptions::fromLivestream()` receives
only `segment_start_time` and `segment_end_time`; `resolvedDuration()` therefore
subtracts them and includes the gap deliberately omitted by `concat_spans`. The
initial remediation corrected only the existing-sermon refresh branch and its
repair command. It did not correct creation of a fresh sermon.

| Sermon | Stored duration | FFprobe duration | Explanation |
|---|---:|---:|---|
| 900 — 2021-09-12 | 2,039.900 s | 1,789.722 s | Fresh concat; stored outer window. |
| 899 — 2021-12-19 | 986.990 s | 822.729 s | Fresh concat; stored outer window. |
| 898 — 2022-01-23 | 2,124.000 s | 1,938.613 s | Fresh concat; stored outer window. |
| 897 — 2022-07-24 | 2,371.940 s | 2,129.979 s | Fresh concat; stored outer window. |
| 895 — 2025-10-19 | 1,881.000 s | 1,758.354 s | Fresh concat; stored outer window. |
| 902 — 2020-04-05 | 1,349.000 s | 1,320.297 s | Requested span ran beyond the emitted source. |

The extraction job also records `trim.final_duration` and the later repair derives
duration from planned segment boundaries. That remains theoretical when FFmpeg
emits less media at source EOF. The durable extracted file must therefore be
probed after extraction, and its observed duration stored separately from the
requested plan. The requested segments remain provenance; they are not playable-
duration authority.

**Read this before starting — a misleading docblock will tell you the work is
already done.** `MediaProcessingLog::recordedSermonExtraction()` is documented as
returning the *"true media duration"*, and `extractedSermonMediaDuration()` reads
that value. It is not observed duration: the body sums the planned segment spans
(excluding the concat gap), which is the same theoretical number this finding is
about, one step better than the outer window. The initial remediation wired the
existing-sermon refresh to it, which is why that branch looks fixed and is not.
Correcting that docblock is part of this work, not an aside.

Implementation and proof required:

1. After successful extraction, FFprobe the exact emitted video and reject an
   unreadable or zero-length result. Do **not** add a fourth ad-hoc
   `FFProbe::create()` — `ExtractAudioFromVideo`, `MetadataExtractionService` and
   `StorageAdapterHelper` each build their own, and only
   `StorageAdapterHelper::createFFMpeg()` guards that both binaries exist and are
   executable. Reuse that guarded resolution. Note it returns `null` under
   `app()->environment('testing')`, so the probe must be injectable and the tests
   must supply the duration rather than shell out.
2. Persist the result at the metadata key `trim.observed_duration`, beside the
   existing `trim.final_duration`. `final_duration` keeps its current meaning —
   the planned sum — and must not be redefined in place, because the bounded
   repair and the pass-status report already read it and a silent change of
   meaning is unauditable. Expose it as a new
   `MediaProcessingLog::observedSermonMediaDuration()`, distinct from the existing
   `extractedSermonMediaDuration()`, and correct the latter's docblock to say it
   is the planned sum.
3. Pass that observed duration into fresh `SermonCreationOptions` through the
   existing `duration:` parameter — `resolvedDuration()` already prefers an
   explicit non-zero duration over the segment subtraction, so no new precedence
   rule is needed. `ExtractSermon` runs immediately before `SubmitToProcessing` in
   every livestream chain, so the value is on the log by creation time. Use it in
   the existing-sermon refresh and in `HistoricVideoSermonDurationRepair`, which
   currently reads `extractedSermonMediaDuration()` at two call sites.
4. Keep segment start/end as source timestamps. Do not rewrite them into output-
   relative timestamps merely to make subtraction work.
5. Add regression tests for a fresh concat sermon, an existing concat sermon, a
   single span truncated by source EOF and idempotent banked repair. Stored and
   FFprobe duration must differ by no more than 1.5 seconds. Extend the existing
   `tests/Integration/Jobs/ExtractSermonTest.php` and
   `tests/Integration/Jobs/SubmitToProcessingTest.php` and the repair service's
   own test rather than creating new files.
6. Repair all affected canary rows from their verified assets after deployment;
   do not pay for new analysis.

#### Finding M2 — sermon video storage is outside the operation-owned chain

`SubmitToProcessing` dispatches `StoreSermonVideo` independently. The serial chain
can proceed to quality assessment, thumbnailing, promotion and cleanup without
knowing whether that job is queued, running, retrying or permanently failed. It is
not a `HistoricImportNestedJob`, so historic readiness cannot account for it.

The canary reached this race rather than merely exposing it in theory. Sermons 898
and 899 logged three create-only overwrite failures during re-extraction; later
quality assessment reported `missing_video_file`. Promotion and cleanup then
removed the staging sermon audio while repeated speaker-identification work still
needed it, producing two `Audio file not found` provider errors. The rows failed
closed to `Visiting Speaker`, but these were system failures rather than genuine
low-confidence decisions. The same detached work explains why a terminal-looking
chain can strand a promotion tail.

> **DECISION RECORDED 2026-08-31: keep `StoreSermonVideo` nested. Do not move it
> into the serial chain.** Register it as tracked historic nested work, and await
> it explicitly before the promotion and cleanup segment only — not before
> transcription. This is the finding's own stated fallback, chosen deliberately:
> it confines the change to the historic lane and leaves the live pipeline's
> timing untouched, at the cost of slightly more work than reordering would take.
> The three defects below are all reachable without moving the job: quality
> assessment must distinguish "storage pending or retryable" from "video
> genuinely absent"; cleanup must refuse while queued, active or retryable work
> references a path; readiness must name the job's state.
>
> Superseded question, retained for context — whether `StoreSermonVideo` moves
> into the serial chain or stays nested-and-registered. The finding presents this as a preference;
> it is a fork with consequences outside the historic lane and an implementer must
> not choose it alone.
>
> **Why it is not a historic-only question:** `SubmitToProcessing` dispatches this
> job on *every* livestream run, not only historic ones, and the dispatch is
> deliberately independent — the comment above it reads "so it does not block
> audio processing". Moving it into the chain therefore changes the timing of the
> live Sunday pipeline, where video storage would gate transcription and
> everything after it. That blast radius must be accepted explicitly, not
> discovered.

Implementation and proof required:

1. Make sermon storage an ordered, operation-owned prerequisite of video quality,
   thumbnailing, promotion and cleanup. Prefer putting it in the serial chain; if
   it must remain nested, register it, await it explicitly and make readiness name
   its state.
2. A permanent storage failure must make the processing disposition truthful.
   Quality assessment must never turn a storage race into `missing_video_file`.
3. Cleanup must not delete any source, extracted video, audio or enhanced audio
   referenced by queued, active or retryable storage, quality or speaker work.
4. Preserve create-only idempotence. A retry against an existing destination uses
   the M11 conflict-only hash comparison: identical bytes are success and
   different bytes remain a guarded conflict. A new destination uses exact-size
   verification without a routine hash pass.
5. Follow the existing historic queue conventions: timeout below `retry_after`,
   bounded backoff, explicit `failed()` handling and operation-bound tests covering
   delayed storage, retry, failure, promotion and cleanup ordering.

#### Finding M3 — generated filename titles still survive good analysis

Sermons 898 and 899 retained `Sunday 23 January 2022 101` and
`Carols By Candlelight 19 December 2021` even though banked analysis supplied
`Praying for our daily needs` and `God’s indescribable gift`. The placeholder
recogniser cannot safely infer every filename shape and correctly refuses to
broaden itself far enough to overwrite a possibly curated event title.

The durable fix is provenance, not another permissive regular expression:

1. Persist how the incumbent title was produced, in a new nullable
   `sermons.title_provenance` column cast to a new
   `App\Enums\SermonTitleProvenance` with three cases: `Generated` (filename or
   service-slot fallback), `AiAnalysis` (supplied by banked analysis) and
   `Curated` (an editor, a custom title or a portable/manifest fact). **Null means
   unknown, not generated** — it is the state of every row predating this column,
   and it must route to the legacy recogniser rather than to overwrite.
   Do not reuse `TitleGenerationStrategy` for this: that enum records the strategy
   *requested* at creation, and `AiWithFallback` cannot tell you whether the
   result came from the model or from the fallback, which is the exact question
   here.
2. Set it at each of the three creation paths — `SermonCreationOptions::fromAudioUpload()`,
   `fromVideoUpload()` and `fromLivestream()` — at the point the title is
   *resolved* in `SermonCreationService`, not from the requested strategy.
3. Allow analysis to replace the title only where provenance is `Generated`, or
   where provenance is null **and** the existing `PlaceholderSermonTitle`
   recogniser matches. Never overwrite `Curated`. Preserve the regex recogniser
   unchanged as that legacy fallback; do not broaden it.
4. Backfill nothing. Existing rows keep a null provenance deliberately, so
   behaviour for them is exactly what it is today.
5. Preserve curated titles and slugs. Re-slug only when the replaced title also
   generated the current slug.
6. Test both canary shapes, a genuine curated date/event title, a null-provenance
   legacy row that the regex does and does not match, reruns and banked repair
   with zero provider calls.
7. Repair 898 and 899 only after their provenance is proven; do not treat their
   current non-null text as editorial authority merely because it exists.

#### Finding M4 — the song custody model exists but the historic path does not use it

The canary automatically created 23 `SongVideo` rows holding about 783 MiB. Every
row has database `publication_state = published`, null
`historic_import_operation_id`, and a path that resolves only through the current
global sermon disk. A further nine held song candidates occupy about 240 MiB under
section-publication paths. These files explain a substantial and legitimate part
of retained staging, but their present rows cannot prove durable ownership or
survive a disk/configuration change.

**Scope correction (2026-08-31).** Most of the custody model is already built, so
this is a wiring gap rather than a new subsystem. Migration
`2026_08_10_090000_add_publication_quarantine_to_song_videos_table` already gives
`song_videos` a `publication_state` column and a
`historic_import_operation_id` foreign key, both in `SongVideo::$fillable`;
`SongVideo::scopePubliclyReleased()` already gates reads on `published`; and
`SongVideoService::url()` already calls `HistoricStagingUrlGuard::assertAllowed()`.
What is genuinely missing is (a) a per-row `asset_disk`, the one new column, (b)
population of the two existing columns on the historic publication path, and (c)
promotion coverage — `HistoricAssetPromotion` iterates `Sermon` only. Do not build
a parallel custody model, and do not migrate columns that already exist.

**What this finding is not (2026-08-31).** This is a local custody-accounting
defect, not a production-exposure risk, and it must not be implemented as though
it were one. The canary ran in the local environment against the local database,
so no row it created is reachable by a visitor. More importantly, the audience
boundary cannot be crossed by an export even if these columns stay wrong:
`HistoricNormalOutputContract` deliberately excludes `publication_state`,
`asset_disk` and `historic_import_operation_id` from the portable contract for
both `sermons` and `song_videos`, on the stated grounds that the audience
boundary is a destination decision, and `HistoricMediaGraphPersister` sets
`Quarantined` plus the destination's own disk on every sermon and song video it
applies. A wrong `publication_state` here therefore cannot travel.

What is actually harmed is byte accounting on the working volume — the bottleneck
this whole pass is constrained by. A row that names neither its disk nor its
operation cannot be attributed during a custody census, which is a direct cause of
the unclassifiable residue in M8, and it cannot be promoted or safely reclaimed.
That is the reason to fix it before a 454-identity run, and it is a sufficient
reason on its own. Note also that the missing operation binding does not block
export: `HistoricProcessingResultInventory` collects song videos by
`service_section_id`, not by operation.

Before bulk:

1. Add the one missing column, `song_videos.asset_disk`, mirroring
   `sermons.asset_disk`, and populate the existing
   `historic_import_operation_id` on the historic publication path.
2. Create historic song rows quarantined by default, using the
   `publication_state` column that already exists. Database `published` must
   never describe a private staging-only historic asset.
3. Resolve URLs from the recorded disk rather than whichever disk happens to be
   globally configured later. Keep the existing `HistoricStagingUrlGuard` call;
   it is the private-staging guard this depends on.
4. Extend `HistoricAssetPromotion` to song assets: promote release-eligible and
   review-held clips create-only into private quarantine, verify exact size and
   database linkage, then reclaim only the verified duplicate working copy. Hash
   both sides only when an already-existing destination needs to be classified as
   an identical replay or a conflict, following M11.
   `PrepareSectionPublicationCandidates` already runs before
   `PromoteHistoricAssets` in the livestream chain, so the assets exist by the
   time promotion runs and no chain reordering is required.
5. Backfill the 23 canary rows and account for the nine held candidates without
   making anything public. Only the new `asset_disk` column needs
   expand/contract; the existing columns need backfill, not migration.
6. Test create, retry, conflicting destination, partial failure, cleanup refusal,
   URL resolution and idempotent canary repair. Put the promotion coverage beside
   the existing `tests/Integration/Services/HistoricMedia/` suite.
7. Name the canary backfill command `historic-import:repair-video-song-custody`,
   class `RepairHistoricVideoSongCustodyCommand`, mirroring the two commands that
   already exist — `RepairHistoricVideoSermonDurationsCommand` and
   `RepairHistoricVideoPilotCustodyCommand`. Match their option surface exactly:
   `--operation`, repeatable `--processing-id`, dry run by default, `--apply`
   with `--yes` to act. Do not invent a different confirmation convention.

#### Finding M5 — song publication needs a song-specific boundary gate

The 23 automatically published rows generally matched the intended hymn, but at
least the following canary sections carry material spoken framing or following
content and form the mandatory regression/review set: 894, 881, 884, 862, 824,
828, 830, 907, 919, 899 and 901. Section 894 is the clearest example: its
`O Church Arise` asset contains roughly 29 seconds of spoken introduction and
roughly 29 seconds of the following benediction. Section 907 retains about 27
seconds after the singing. Several others retain 14–25 seconds of introduction.

Read this with M4's scope note: song boundaries are an output-quality and
rework-cost problem, not a publication-safety one. The destination applies its own
quarantine on import and release is a separately authorised act, so a clip with a
ragged edge cannot reach the public by inattention. Build the boundary evidence
and the review routing; do not add a second safety gate to duplicate one the
contract already enforces.

This does **not** imply that the sermon must be cut at the same point. Sermon and
song are different editorial products and may legitimately overlap in source
time. Apply the following asymmetric policy:

- For the sermon, preserve an ambiguous/interwoven conclusion and song
  introduction. Automatically stop only on affirmative timed evidence for a
  separate next item. A short, adjacent spoken bridge that plausibly belongs to
  both products remains in the sermon automatically; ambiguity by itself must not
  set `needs_review` or otherwise block release. Route only material-risk cases to
  review: conflicting boundary evidence, clearly unrelated content, multiple
  following items merged into the sermon, or an unusually long tail corroborated
  by non-duration evidence. A duration threshold may be a configurable guard but
  must never be the sole authority for a cut or review hold.
  **Clarification (2026-08-31).** This does not condemn the existing
  `max_sermon_duration_seconds` ceiling in
  `SermonExtractionPlanResolver::resolveSermonEnd()`, which drops the absorbed
  trailing sections when they would take the span past the plausible ceiling.
  That guard never truncates detected sermon material: it declines to *extend*
  the span and falls back to the sermon section's own end, so its failure mode is
  a slightly short tail rather than a cut into the preaching. It exists to catch
  under-segmentation, where a single "section" has swallowed the rest of the
  service. Keep it. The rule above forbids using duration alone to cut into
  material the detector attributed to the sermon, or to raise a review hold —
  not this bounded refusal to absorb.
> **DECISION RECORDED 2026-08-31: do not derive tighter song intervals before
> bulk.** Keep the inclusive candidate, record the boundary evidence on the
> section, and route only the material-risk classes to review. Sequence the
> interval work after bulk: a measurement pass over the existing corpus first,
> the heuristic second.
>
> **The evidence needed to design it survives cleanup**, which is what makes
> deferral safe. `service_transcript_path` is deliberately excluded from
> `MediaProcessingLog::temporaryFilePaths()` and `rms_log_path` was never in it,
> so the timed transcript and the energy log outlive the run even though the media
> does not. Only the eventual recut needs media back.
>
> **What the target actually is, when it is built (corrected 2026-08-31).** Not
> "where does the singing start" — *where does the speech stop*. The musical
> introduction and outro belong in the song clip; the spoken framing ("now we're
> going to sing") does not. The cut therefore lands between the end of the spoken
> framing and the resumption of words, and both error directions are benign:
> slightly early keeps a second of speech, slightly late sits inside the
> instrumental introduction.
>
> **Anchor the cut on the wordless gap, not on lyric recognition.** A first draft
> of this spec proposed identifying the first cue whose text matches the matched
> song's lyrics. Do not build that. Measured over every matched song section in
> this database, the evidence source breaks down as `title_hint_fuzzy` 84,
> `ocr` 46, `title_hint_canonical` 39, `title_hint_first_line` 8 — and
> **`lyrics` zero**. Transcript-lyric matching is the last of three fallbacks in
> `MatchSongsFromTranscript` (title hint, then OCR, then transcript lyrics), so it
> is consulted only on sections the first two could not identify, and on every
> such occasion it also returned nothing: those sections are the corpus's eleven
> `unmatched` rows. There is no evidence in this corpus that lyric recognition
> from a sung transcript works, which is consistent with how the transcriber
> handles singing. A cut anchored on it would misclassify a garbled sung cue as
> speech and trim into the song's opening line.
>
> Use the structure instead. Words produce cues; an instrumental introduction
> produces none. Near the section start the shape is: spoken cues, then a gap with
> no cues, then cues resume. Cut in that gap — no judgement about *what* the
> resuming words are is required. Corroborate with the RMS log, which separates a
> music-under-no-speech gap from dead air or a scene change. Bound the trim to a
> configured maximum (the canary's spoken framing ran 14–29 seconds), and if the
> first wordless gap falls beyond it, keep the inclusive clip and review. If the
> transcriber hallucinates text across the introduction there is no gap, which
> fails closed to the inclusive clip — the correct outcome.
>
> The inputs already exist and survive cleanup: the stored service transcript is
> `{"cues":[{"start","end","text"}]}` and the RMS log is not in
> `temporaryFilePaths()`.
>
> An earlier draft of this finding asked for a "defensible inner performance
> interval derived from positive evidence" and judged the risk asymmetric on the
> grounds that a mistimed cut clips a first line. That framing was wrong and is
> superseded by the paragraphs above.

- For the song video, the releaseable product should be the singing/performance,
  not an unrelated announcement, sermon conclusion, benediction or next item.
  Before bulk this means keeping the existing inclusive candidate and routing the
  material-risk cases to review; interval derivation is deferred by the decision
  above. Never guess a tighter destructive cut from title text or one transcript
  phrase.
- A matched song identity and ordinary duration do not prove clean boundaries.
  Record why the interval is release-eligible, including the evidence for its
  start and end, so an intentionally short song can still pass.

Of the 12 canary services that produced sermon sections, ten had no trailing
`other` section. The other two retained spoken bridges of approximately 44 and 11
seconds. Both were inspected in the media evaluation and read as sermon
conclusions rather than as separate following items, so both are acceptable
inclusive sermon outputs under this policy and add zero sermon-boundary reviews.
That is a judgement on two observed cases, not a measured corpus-wide rate: it is
sufficient to reject a blanket review gate on cost grounds, and it is not
evidence that every ambiguous tail in the remaining corpus will read as well. Treating either ambiguity as a hold would create a
16.7% canary review rate and is explicitly rejected as too costly before a
hundreds-of-services run. This does not relax any independent review reason, and
it does not change the existing mandatory approval policy for children's talks.

Regression fixtures must include: a clean song; a long spoken introduction; a
trailing benediction; two adjacent songs; an interwoven sermon conclusion/song
introduction that stays in the sermon but not the releaseable song clip; a short
ambiguous spoken bridge that is retained without creating a sermon review; and a
material-risk transition that is held because independent evidence conflicts or
shows unrelated/merged content. Assert that title text, one transcript phrase and
duration alone cannot create either a destructive sermon cut or a review hold.

#### Finding M5a — remove the zero-yield sung-transcript lyrics fallback

The corpus result recorded in M5 is also a deletion decision, not an invitation
to tune the matcher. `MatchSongsFromTranscript` currently tries three evidence
sources in order for every unmatched song section: title hint, projected-lyrics
OCR, then the slice of the full-service transcript covering the section. The
first two paths produced all 177 observed matches; the final transcript-to-lyrics
path produced zero and left all eleven sections that reached it unmatched. The
generic matcher is not the failed component: the same
`SongLyricsMatchingService::matchFromLyrics()` method turns clean OCR and title
text into useful catalogue matches. The failed assumption is that the existing
speech transcript contains reliable sung lyrics.

**Decision recorded 2026-08-31: remove only the final full-service
transcript-to-lyrics fallback before the next canary/bulk run.** Do not remove
`SongLyricsMatchingService`, title-hint matching, OCR sampling/matching, stored
OCR evidence or the `lyrics_threshold` used by those productive paths. Do not
lower the threshold, add a music transcription provider/model, add a dedicated
song-opening transcription call or otherwise start a lyric-transcription project.
The demonstrated upside is bounded to the eleven residual sections, while a
best-of-catalogue fuzzy false positive can write a catalogue link and influence
downstream song usage and publication handling. An unmatched section already
has the correct fail-closed outcome: `UnmatchedSongReviewApplicator` keeps it for
review.

Implementation scope for the next agent:

1. In `app/Jobs/MatchSongsFromTranscript.php`, stop after the OCR attempt. If
   title hint and OCR both fail, leave the section unmatched and let the existing
   post-loop `UnmatchedSongReviewApplicator` apply the review state. Do not change
   matching order, matched counts or review handling for the surviving paths.
2. Delete `matchSectionFromServiceTranscript()`, the private
   `loadServiceTranscript()` helper that becomes unused, and the now-unused
   `App\Data\ChurchServiceTranscript` import from that job. Keep the service
   transcript itself and its durable path: other pipeline stages use it, and M5's
   deferred boundary measurement depends on it.
3. Preserve `SongLyricsMatchingService::matchFromLyrics()` and its integration
   tests. It remains the shared clean-text matcher for title hints and OCR, so
   neither the service nor fuzzy/canonical/first-line matching is dead code.
   Preserve `SongLyricOcrService` and all `match_source = ocr` and title-hint
   source values unchanged.
4. Replace
   `MatchSongsFromTranscriptTest::it_matches_an_unmatched_section_from_the_full_service_transcript()`
   with a regression proving the opposite production policy: with OCR disabled,
   no title hint and a service-transcript slice that exactly resembles a
   catalogue song's lyrics, the job leaves the section unmatched, creates no
   `transcript_song_match`, and retains the `unmatched_song_section` review flag.
   Keep the existing title-hint and OCR feature tests as proof that the two
   productive paths still match.
5. Do not migrate or rewrite historical metadata whose
   `transcript_song_match.match_source` is `lyrics`; it remains valid historical
   evidence. Require only that no live code path can create a new `lyrics` match
   source. Do not add a replacement config flag for a path being deliberately
   deleted.
6. Run the focused `tests/Feature/Jobs/MatchSongsFromTranscriptTest.php`, then
   PHPStan, Pint and the full parallel suite under the repository's normal Sail
   workflow.

Acceptance is observable: the focused fail-closed regression passes; existing
title-hint and OCR matches still pass with their original source/confidence
metadata; a code search finds no live writer of `match_source = lyrics`; and an
unmatched section no longer loads or scans the stored service transcript and song
catalogue after OCR has failed. This is a small pre-bulk deletion: it removes a
zero-yield decision path and its false-positive surface without changing any
input, provider call or successful result observed in the measured corpus.

#### Finding M6 — inferred song eligibility does not enforce its stated confidence

`SongPublicationHandler::isEligible()` documents a high-confidence inferred match
but accepts every inferred match with a linked song: it tests
`hasInferredSongMatch()` — a bare enum equality — and a non-null
`churchServiceItem->song_id`. The link alone is insufficient.

> **DECISION RECORDED 2026-08-31: add no threshold and no config key. Make
> `Inferred` ineligible for automatic publication and route it to
> `SongPublicationReviewPolicy` as a named doubt.**
>
> `Inferred` *is already the threshold decision*, applied upstream at match time.
> `MatchSongsFromTranscript::applyMatch()` sets
> `$writeCatalogueTitle = $confidence >= $writebackThreshold && ! $markerMismatch`
> and labels the section `Confirmed` when that holds and `Inferred` when it does
> not. The enum says the same in its own description: "the transcript suggested
> this catalogue match below the confidence required to trust it without review."
> Re-testing confidence in the publication handler would re-apply a test that has
> already been applied.
>
> **A confidence gate would be actively harmful here, not merely redundant.** The
> canary's inferred sections carrying a confidence value score 0.95–1.00, because
> they were labelled `Inferred` by the *marker mismatch* half of the condition,
> not the confidence half — the section's own title contradicts the match. A
> confidence threshold would wave through exactly the rows the label exists to
> warn about. The code already says so: "Confidence cannot arbitrate a naming the
> detector already contradicted itself on: both observed mismatches scored 0.98
> and 1.000."
>
> This also disposes of the seven inferred sections that carry no
> `transcript_song_match` metadata at all, five of which have a linked `song_id`
> and are therefore eligible today. They need no null-handling branch: they are
> held because they are `Inferred`, like everything else in the class.
>
> **Not a bulk blocker.** No inferred match has ever produced a `SongVideo` — all
> 106 song videos with a match type are `Confirmed`, at 0.95–1.00. This is a
> latent guard, worth closing cheaply, sequenced whenever convenient.
>
> Superseded, retained for context — the earlier draft said to reuse an existing
> configured confidence policy. **No such policy exists.** An earlier draft said to "apply the existing configured
> confidence/corroboration policy" and not to "encode the threshold a second
> time". There is no song-publication threshold in config. What exists is
> `media-processing.song_matching.title_writeback_min_confidence` (0.75), which
> decides whether to *display* the catalogued title in `MatchSongsFromTranscript`,
> and `media-processing.section_publishing.require_high_confidence`, a boolean
> read only by the children's-talk handler. **Reusing the 0.75 title threshold for
> a publication decision would be wrong** — it governs naming, not release — and
> it is the nearest plausible-looking key, so say so explicitly rather than
> leaving an implementer to find it.
>
> **What must be decided:** the config key name, its default value, and whether
> corroboration can substitute for confidence below the threshold.
>
> **What already exists to build on:** the match confidence is persisted per
> section at `metadata.transcript_song_match.confidence`, written by
> `MatchSongsFromTranscript::applyMatch()`, so the value is available and no new
> plumbing is needed. `SongPublicationReviewPolicy` is the right home — it already
> names short, adjacent-duplicate and uncorroborated-partial doubts, and this is a
> fourth doubt of the same kind, not a new gate.

Hold, do not reject: an inferred match reaches a person with its doubt recorded,
like every other reason in `SongPublicationReviewPolicy`. Tests must cover an
inferred match with high confidence and a marker mismatch, an inferred match with
no confidence metadata at all, and a confirmed match that still publishes
automatically. Do **not** reintroduce a threshold comparison in the handler.

#### Finding M7 — children's-talk safety is sound, but boundaries create scale risk

Mandatory approval kept all four children's-talk candidates private, which is the
correct safety property. Three candidates nevertheless contain following material:
section 891 includes about 140 seconds of subsequent prayer, while 842 and 809
include introductions to the next song. Section 874 was clean in the reviewed
samples.

Do not automatically trim a prayer merely because it follows the talk: as with a
sermon conclusion, it may be editorially integral. Preserve the inclusive
candidate, surface the tail evidence to the reviewer, and support a bounded recut
when the reviewer decides it is separate. Improve section evidence where possible,
but keep mandatory approval. This is a manual-work and output-quality risk at bulk
scale, not an unsafe automatic-publication defect while that gate remains.

#### Finding M9 — a flagged run's source is deleted before anyone can review it

M5 and M7 both end in "hold it for review, and recut it if the reviewer decides
the tail is separate". Neither is executable today, because the media is gone by
the time a reviewer opens the item.

`CleanupTemporaryFiles` runs last in the same chain and deletes every path in
`MediaProcessingLog::temporaryFilePaths()` unconditionally — `source_file_path`,
`enhanced_audio_file_path`, `extracted_segment_path`, `extracted_audio_path` and
`temp_video_path`. `sermons:re-extract` detects the absence and refuses by name
rather than failing inside FFmpeg, and the import command rejects `--force` on an
operation-bound identity, so reprocessing is not an escape either. What survives a
completed run is the service sections, the service transcript (deliberately
excluded from cleanup), the RMS log and the published assets — everything except
the one thing a recut needs.

For historic imports the original recording is permanent on the archive drive, so
a source can in principle be rebuilt with the byte-identical
`ffmpeg -f concat -c copy` restaging recipe. That is an operator procedure per
item, not a review workflow, and it does not scale to a hundreds-of-services run
whose whole point is to leave a reviewable backlog behind it.

**Decision (2026-08-31): retain the source where the run leaves something flagged
for review.** Cleanup becomes conditional rather than unconditional:

1. At cleanup time, ask whether this run leaves any unresolved review or approval
   obligation. The predicate is exactly these four, all of which exist today and
   none of which depends on unbuilt work:
   - a `ServiceSection` in `ServiceSectionPublicationStatus::PendingApproval`
     (every children's talk reaches this by mandatory approval, and every song
     clip held by `SongPublicationReviewPolicy` does too);
   - a section with `needs_manual_review` set;
   - a `SermonVideoQualityStatus::NeedsReview` verdict on the run; or
   - a run whose own status ended in manual review.
   If any holds, retain `source_file_path` and skip only that deletion.
   **Deliberately not in the predicate:** M5's material-risk sermon boundary
   class. It does not exist yet, and M9 must not block on it. When M5's pre-bulk
   half lands, its review routing will set one of the flags above, so the
   predicate picks it up with no change here. Do not add a fifth trigger that
   anticipates it.
2. Retention is bounded by resolution, not by a clock. When the last obligation
   on a run is approved, rejected or published, the retained source becomes
   reclaimable and a sweep deletes it. A run with nothing flagged cleans up
   exactly as it does today, so the common case costs nothing.
3. Retention must be measured, not assumed free. `temp_disk` pressure is the
   known bottleneck, so the pass-status measures must report retained-for-review
   bytes as their own line beside peak working, promoted, retained and residue,
   and the headroom check must count them before dispatching more work.
4. The retained source is a working copy, not a durable asset. It stays on the
   working disk under the run's own key, is never promoted into quarantine, and
   is never addressable over HTTP.
5. Because sources now outlive their run, cleanup gains a second obligation: it
   must never delete a retained source that a queued, active or retryable job
   still references. This is the same ordering requirement as M2 and should reuse
   whatever that finding builds rather than inventing a second reachability rule.

Implementation and proof required: a run with no flagged item deletes its source
exactly as today; a run with a held children's talk, a held song clip and a
material-risk sermon boundary each retain it; resolving the last obligation makes
it reclaimable and the sweep deletes it; a second sweep is a no-op; the retained
bytes appear in the measures and in the headroom decision; and `sermons:re-extract`
succeeds against a retained source without restaging. Note that re-extraction is
still the sanctioned repair — the detector is not deterministic across passes, so
a recut must reuse the existing sections rather than reprocess.

This finding is a prerequisite for M5 and M7 rather than an independent
improvement.

**Correction (2026-08-31) on how urgent it is.** An earlier draft called this the
one item bulk makes irreversible. That is wrong in every lane. Cleanup deletes the
*working copy* under the processing key, never anyone's master: the archive drive
holds every historic original permanently, and an ordinary run's source still
exists wherever it was uploaded from. Nothing here is data loss. The honest case
for doing this before bulk is cost and workflow — a hundreds-of-services run that
leaves a review backlog with no local media turns every subsequent correction into
a manual restage. Weigh it as such, and note that the artefacts needed to *analyse*
a boundary (the timed service transcript and the RMS log) survive cleanup already;
it is only the recut that needs media back.

#### Finding M10 — the direct lane creates historic sermons `published`

`sermons.publication_state` defaults to `Published` in
`2026_08_09_223000_add_publication_quarantine_to_sermons_table`, and the direct
processing lane never overrides it at creation. A historic sermon is therefore
born `published` with a null `asset_disk`, and is *demoted* to `Quarantined` only
when `HistoricAssetPromotion::bindToQuarantine()` runs at the end of the chain.
Sermon 896 is the canary's instance: a valid 1,967.030-second staging video, null
disk and operation ownership, `published` state, because its run never reached
promotion.

This is the same wiring gap M4 records for `SongVideo`, on the sermon side, and
the bundle lane already does it correctly — `HistoricMediaGraphPersister` creates
its sermons `Quarantined` with `asset_disk` set. The direct lane is the outlier.

**Bounded like M4: this is custody accounting, not exposure.** The canary runs
locally, and `HistoricNormalOutputContract` excludes `publication_state`,
`asset_disk` and `historic_import_operation_id` from the portable contract, so a
wrong state here cannot travel to a destination. What it does is leave rows that
name neither their disk nor their operation for the whole length of a run, which
is unattributable during a census and indistinguishable from a genuine
publication if the run strands. With M2 making stranded tails a known reachable
state, that window is not theoretical.

Implementation and proof required:

1. Create quarantined at insert on the direct lane when the run is historic. The
   predicate is `MediaProcessingLog.historic_import_operation_id` being non-null;
   set `publication_state` to `Quarantined`, `asset_disk` to the staging disk the
   run is writing to, and the operation id, at the same moment the sermon row is
   created.
2. Leave the column default alone. An ordinary upload or livestream must keep
   creating `Published` sermons; this narrows to the historic predicate only.
3. Promotion keeps its current job — rebinding `asset_disk` from staging to
   quarantine — and must become a no-op with respect to `publication_state`,
   which is already correct by then. Verify it stays idempotent for rows created
   before this change, which arrive `published` and must still be demoted.
4. Test a historic run that strands before promotion (the row must be quarantined
   and disk-bound throughout), a historic run that completes, an ordinary
   livestream that must remain `published`, and promotion replay over both a
   pre-change and post-change row.
5. Repair sermon 896 through the existing recovery path in the operator sequence;
   no separate command is needed.

#### Finding M11 — remove routine hash I/O from processing passes

The command no longer verifies the whole corpus, but a selected single-file item
is still read four times for SHA-256 before or around the copy that staging
actually needs:

1. the import loop calls `assertApprovedSourceFilesAreUnchanged()`;
2. `dispatchItemWithinStagingContext()` immediately calls it again without an
   asynchronous or trust boundary between the two;
3. `historicImportMetadata()` hashes every source again for provenance; and
4. `UnifiedMediaProcessor::computeFileHash()` hashes the `UploadedFile` even
   though the historic lane supplies its own manifest-item `dedup_key`.

The subsequent `storeAs()` is a fifth full source traversal because it performs
the necessary copy. Concatenated items repeat the integrity assertion once more
before FFmpeg, and FFmpeg then necessarily reads the inputs. On a 32.8 GiB
canary this turns a bounded dispatch into well over 100 GiB of avoidable reads;
on the roughly 1 TiB corpus it can add several terabytes of I/O. Repeated hashes
also do not prove the staged copy: every comparison happens before the ordinary
copy completes.

**Decision (2026-08-31): accept metadata-and-copy verification for this bounded
one-shot import.** Decision 9 records the risk acceptance. Implement it exactly
as follows:

1. Keep manifest creation unchanged. The already-frozen SHA-256 values remain
   approval/provenance evidence and still participate in the manifest and plan
   hashes. Do not regenerate the manifest merely because runtime verification is
   being removed.
2. Keep `HistoricVideoCurationManifest::plan(... verifySourceContents: false)`.
   For every manifest member it must still reject a missing root, path escape,
   symlink, non-file and byte-size mismatch without opening unselected contents.
   *Superseded 2026-09-01: the parameter is deleted and `plan()` never reads
   contents. Every check named here is unchanged and still unconditional.*
3. Replace `assertApprovedSourceFilesAreUnchanged()` with a metadata-only selected
   source check: approved relative path, root containment, no symlink in any path
   component, regular readable file and exact manifest byte size. Delete the
   duplicate calls and `sourceFileSha256()`; do not leave a dormant flag that can
   restore them.
4. Do not add a pre-copy in front of `VideoStorageService::storeUploadedVideo()`:
   its existing `storeAs()` is already the necessary source-to-staging traversal,
   and wrapping it with another staging upload would copy the bytes twice. For a
   single-source item, move or narrow that existing storage boundary so the
   `storeAs()` write is closed, reports success, and leaves an exact-size file in
   the operation's unique staging path **before** `ProcessingInitiator` creates a
   log or any job is enqueued. A read error, disappeared mount, short copy or long
   copy is `aborted_stale_mount`; delete only the incomplete unique staging
   destination. Never modify or delete the archive source.
5. Run every worker from that operation-owned staged file, never the removable
   archive path. For a concatenated item, copy each archive segment exactly once
   to operation input staging, concatenate only those staged segments, then pass
   the derivative through a narrowly guarded "adopt already-staged historic
   derivative" path instead of letting `storeAs()` copy it again. Adoption must
   require the active operation context, an allow-listed path beneath that
   operation, the exact manifest item/dedup identity, a regular non-symlink file
   and the expected derivative byte size. It is unavailable to ordinary uploads.
   FFmpeg output size/decodability remains ordinary processing validation, not
   source approval.
6. Stop calculating a generic `file_hash` for historic uploads. The historic
   manifest-item key remains the exact deduplication identity, and
   `media_processing_logs.file_hash` is already nullable. Add a narrowly guarded
   processor option that may skip `computeFileHash()` only when all three are
   present: an operation-bound historic staging context, an explicit historic
   manifest-item `dedup_key`, and approved source metadata. Ordinary uploads keep
   their current hash/dedup behaviour.
7. `historic_import.sources` must remain truthful without opening the file.
   Populate path, approved size and approved SHA-256 from `source_files`, retain
   observed `mtime` only as descriptive metadata, and add
   `sha256_basis = approved_manifest_not_reverified_at_dispatch`. Do not present
   the frozen value as an observed runtime hash. A concatenated derivative may
   leave `file_hash` null; its stable processing identity is still the manifest
   item key.
8. For ordinary promotion into a new quarantine path, replace routine source and
   destination hashing with create-only copy plus exact source/destination byte
   sizes. Recheck destination existence/size after the database binding and before
   deleting staging. If the destination already exists, first compare size; only
   then hash both sides to distinguish an identical idempotent replay from a
   same-size conflict. A mismatch fails closed and retains staging. Do not weaken
   the path allow-list, operation binding, private state or create-only rule.
9. Leave authoritative Bundle A export/import, cross-machine asset transfer and
   release-ledger hashing unchanged. Their trust boundary and later transport
   purpose are distinct from processing-pass throughput.
10. Preserve the archive corpus without mutation until IC8 closeout. This is the
    recovery source for the explicitly accepted same-size silent-corruption risk;
    do not pair the reduced verification with source deletion.

Focused proof must replace, not merely delete, the old integrity assertions:

- a selected same-size content mutation now proceeds, documenting the accepted
  risk, while wrong size, symlink, path escape and unreadable/copy-failing sources
  still create no processing state;
- an instrumented selected single source is opened for content only by the
  existing storage service's one source-to-staging copy, with no pre-copy, second
  `storeAs()` or hash traversal; an unselected source is never opened;
- each concat segment is copied from the archive once, the concat derivative is
  adopted without a second copy, and the adoption guard rejects an ordinary
  upload, wrong operation/path, symlink, wrong item key and wrong size;
- processing state is not created until the operation-owned staged path exists at
  exact size, and that staged path is the only path workers receive;
- historic provenance uses the frozen manifest value with the explicit basis,
  `file_hash` may be null, and an identical redispatch still reuses the same run;
- ordinary uploads still calculate `file_hash` and retain their existing dedup;
- new-destination promotion uses size verification only; an identical existing
  destination is an idempotent no-op, a different same-size destination is a
  conflict, and a failed/short copy retains staging;
- Bundle A and cross-machine transfer hash tests remain unchanged.

#### Finding M12 — make pass performance measurable and concurrency truthful

The canary retained enough raw evidence to diagnose performance but did not
produce the report Phase 7 required. `MediaProcessingLog` stores run
`started_at`/`completed_at`; `SermonProcessingStep` stores canonical step
`started_at`/`completed_at`; structured logs retain API response times and memory.
The surviving evidence shows local Whisper at roughly 25–28x real time, while a
single FFmpeg worker had six jobs queued and Whisper/LLM/orchestration were idle.
The visible bottleneck is therefore FFmpeg/CPU scheduling, not transcription.

Two implementation defects obscure that result:

- `--parallel` changes only staging-headroom arithmetic. It does not start workers
  or control execution; real widths come from `HISTORIC_MEDIA_WORKERS_FFMPEG`,
  `_WHISPER`, `_LLM` and `_ORCHESTRATION`, all currently one.
- `HistoricProcessingFingerprint::forStagingContext()` is rebuilt for every
  dispatch, repeatedly hashing the FFmpeg/FFprobe binaries and starting both
  `-version` processes even though the fingerprint is invariant for a pass.

Implement retained measurement and calibration in this order:

1. Extend `historic-import:video-pass-status` with `--performance` and an optional
   create-only `--performance-report=<absolute-json-path>`. Reuse the command's
   existing IC8 deletion trigger; do not create another one-shot command. Put the
   calculation in a focused `HistoricVideoPassPerformance` service so console
   formatting is not the source of truth.
2. Select runs only through the exact operation plus `--only` manifest keys. For
   each run report item key, processing id, terminal disposition, attempt count,
   source bytes, media/content seconds where known, `created_at`→`started_at`
   queue delay and `started_at`→`completed_at` elapsed time. Missing or
   non-terminal timestamps are `null`/`incomplete`, never zero.
3. Before using a fresh pass as a calibration, persist canonical step timings for
   every mapped high-cost job that currently omits them: `GenerateRmsLog`,
   `AnalyzeSegments`, `ExtractAudioFromVideo` and `GenerateThumbnail`. Follow the
   existing `SermonProcessingStep` transition conventions: record start before
   the expensive call, terminal completion on success, and an explicit failed or
   skipped terminal disposition on every exit path. A retry may replace the
   canonical timestamps, matching current semantics; do not claim attempt-level
   history. Add focused job tests for success, failure and skip so a future job
   cannot disappear from performance evidence silently.
4. For each canonical processing step report sample count, completed/failed/
   skipped count, p50, nearest-rank p95 and maximum active duration. Also report
   the gap from the preceding completed step to the next started step as
   queue/wait time. Add a fail-explicit step-to-stage mapping in
   `HistoricProcessingThroughput` for every step emitted by the historic
   pipeline; an unknown step is reported as `unknown`, never silently assigned to
   orchestration. For the old operation-3 retrospective, mark the four formerly
   uninstrumented jobs as missing coverage; run wall time is still valid, but no
   FFmpeg active-duration percentile may be inferred from absent rows.
5. At pass level report earliest start, latest terminal completion, wall time,
   items/hour, source-GiB/hour, content-hours/wall-hour, maximum overlapping runs,
   maximum overlapping step intervals per stage, configured worker widths, runs
   missing timings and runs with `attempt_count > 1`. Emit both `all_runs` and a
   `clean_first_attempt` aggregate; retries overwrite canonical step timestamps,
   so the report must state that it is not an attempt-history ledger.
6. Keep model/token/request counts and API response-time summaries alongside the
   timing report. Use durable database evidence for acceptance; structured logs
   may enrich yesterday's retrospective but cannot be the only source for the
   next pass because Horizon completed-job detail expires quickly.
7. Remove `--parallel` from the import signature and remove the unused importer
   parameter. `HistoricStagingHeadroom` must derive concurrent FFmpeg working-copy
   allowance from `media-processing.historic_import.stages.ffmpeg.workers`, the
   configuration that defines the worker pool. Its pre-copy requirement is the
   minimum-free floor plus bytes for selected inputs not already staged plus the
   concurrent transient allowance. Until measured calibration replaces the
   bootstrap estimate, the allowance may remain twice the sum of the largest N
   FFmpeg working sets, where N is the configured FFmpeg width. Report retained
   M9 review-source bytes, but do not add them again: current `available_bytes`
   already reflects them and double-counting would understate capacity. Resolving
   pending/idempotent keys before headroom is optional; counting them is a safe
   conservative overestimate. Print all four configured stage widths and every
   formula term in preflight. A command-line number can no longer claim
   concurrency that does not exist.
8. Separate byte-affecting identity from execution tuning. Configured worker
   widths and queue-routing hashes belong in
   `processing_metadata.historic_import.execution_profile`; they affect headroom
   and performance interpretation but must not change the durable processing
   fingerprint or Bundle A output equivalence. Implement backward compatibility
   explicitly in `HistoricProcessingFingerprint`: add one canonical normalization
   method that removes legacy `throughput`; have new `forStagingContext()` output
   the width-independent form; allow `assertPortable()` to accept `throughput`
   only on the existing legacy schema/version and normalize it before comparison;
   and make `assertMatchesCurrentConfiguration()` compare normalized durable
   fingerprints. Do not silently drop any other unknown key.
9. Apply the same normalization in
   `HistoricProcessingResultBundleExporter::persistedProcessingFingerprint()`:
   normalize every persisted legacy/new value before equality, then write one
   canonical width-independent fingerprint to Bundle A. Preserve each run's
   separate execution profile in reporting/evidence. A focused mixed-corpus test
   must export a legacy width-one run and a new width-two run together while
   proving their execution profiles retain the different widths; a genuinely
   byte-affecting fingerprint difference must still fail export.
10. Compute the durable processing fingerprint and execution profile once at the
   start of one importer pass and pass those immutable arrays into every item's
   metadata. This avoids repeatedly hashing FFmpeg/FFprobe binaries and launching
   their `-version` processes. Do not add a process-wide or persistent cache: a
   new command/service instance after configuration or binary changes must
   recompute both. `assertMatchesCurrentConfiguration()` keeps its independent
   normalized comparison behaviour.
11. Reconstruct the one-worker baseline for operation 3 from retained DB timestamps
   and `storage/logs/laravel.log`, saving the JSON report under `storage/scratch`.
   Mark mount-failed, retried and manually re-extracted runs separately; do not
   use their end-to-end elapsed values as clean throughput samples. State the
   historic FFmpeg-step instrumentation gap in the report rather than filling it
   from log guesses.
12. After all correctness/custody blockers are fixed and the identical 14-key
   canary proves a zero-work replay, run a four-identity **fresh calibration pass**
   from the untouched approved pool with two FFmpeg workers and one worker in each
   other stage. An identical canary cannot measure two-worker throughput because
   correct deduplication makes it a no-op. Select at least one high-bitrate
   re-encode, one ordinary stream-copy source, one large source and one modern MKV;
   this work belongs to the same cumulative operation and is not throwaway.
13. Before that pass, set `HISTORIC_MEDIA_WORKERS_FFMPEG=2` consistently for the
    dispatcher and worker runtime, recreate/restart the historic workers, verify
    two actual FFmpeg worker processes, retain the new execution profile, and
    require the formula from step 7 to admit all selected input copies and two
    concurrent FFmpeg working sets above the free-space floor.
14. Keep width two only if the retained report shows observed FFmpeg overlap of
    two and at least a 25% improvement in either clean content-hours/wall-hour or
    clean FFmpeg queue-wait p95, while individual FFmpeg active-duration p95 is
    not materially worse, failures/retries do not increase, no mount instability
    or later-stage starvation appears, and measured peak working bytes stay
    within the admitted envelope. Four samples make nearest-rank p95 equal the
    maximum; label it accordingly and treat a noisy or incomplete comparison as
    inconclusive, returning width to one. Retain the execution-profile decision;
    it is not a durable-output fingerprint change. Do not
    widen Whisper: the canary already shows it far faster than real time and its
    local service is intentionally serialized around the single GPU. Widen LLM or
    orchestration only if a later retained report identifies them as the queue.

Focused proof: percentile edge cases (including four-sample nearest-rank p95) and
missing timestamps; operation/item scope; retry separation; success/failure/skip
step instrumentation; stage mapping, queue wait and overlap calculation; JSON
create-only output; selected-input bytes and configured widths used by the
headroom formula without double-counting retained bytes; removal of `--parallel`;
a multi-item import computes binary evidence once; a fresh importer recomputes
it; changing FFmpeg width changes headroom and execution profile but not durable
fingerprint; mixed legacy/new widths export together; and a byte-affecting
fingerprint mismatch still fails closed.

#### Finding M8 — current canary state still needs exact reconciliation

The post-evaluation status remains 11 completed, one manual review, one failed and
one in progress. The corrected scoped measures report 50.46 GiB peak working,
3.85 GiB promoted, 2.29 GiB retained on staging, 5.19 GiB residue, 2.29 GiB of it
accounted by runs, and 4.41 GiB held in quarantine. The non-zero difference still
requires a path census; the labels above are not permission to delete it.

Canary-specific repairs after M1–M7, M9 and M10 are implemented:

- recover `2024-03-03-morning` processing
  `127173a4-4a90-4ec7-8f0a-2184a04db4e6`; sermon 896 currently has a valid
  1,967.030-second staging video but null disk/operation ownership and a published
  state;
- re-extract sermon 893 from a freshly verified operation-owned source, or hold
  it explicitly: its surviving 1,128.645-second asset does not represent the
  current 2,168-second concat plan. Establish which is possible before committing
  to the repair: its run has completed, so under M9's predecessor behaviour its
  source was deleted and `sermons:re-extract` will refuse by name. If the source
  is gone, rebuild it with the byte-identical `ffmpeg -f concat -c copy` restaging
  recipe against the archive-drive original, writing to the exact
  `source_file_path` the log records under the batch root, and verify the
  rebuilt duration against the structure's last section end before recutting;
- repair the duration rows enumerated in M1 from observed media duration;
- repair titles 898 and 899 from banked analysis only after provenance proves the
  incumbent is generated;
- replay speaker identification for 898 and 899 only after the ordered custody
  fix makes their banked audio available, with no duplicate sermon-analysis call;
- quarantine, operation-bind and promote the 23 song rows, and account for every
  held song/children's-talk candidate; and
- enumerate every byte behind the remaining 5.19 GiB residue before reclaiming any
  path.

Each repair must bind exact operation and processing/section IDs, default to dry
run, require explicit confirmation to apply, make no provider call unless the
specific repair intrinsically requires one, and prove an identical replay is a
no-op.

#### Implementation map for the next agent

Start from these existing seams; do not create a parallel historic pipeline:

| Work | Primary code seams | Minimum focused proof |
|---|---|---|
| M1 duration | `app/Jobs/ExtractSermon.php`, `app/Models/MediaProcessingLog.php`, `app/Data/SermonCreationOptions.php`, `app/Jobs/SubmitToProcessing.php`, `app/Services/HistoricMedia/HistoricVideoSermonDurationRepair.php` | Fresh and existing concat, source-EOF truncation, repair dry-run/apply/replay. |
| M2 ordering | `app/Jobs/SubmitToProcessing.php`, `app/Jobs/StoreSermonVideo.php`, `app/Services/Processing/ProcessingPipelineBuilder.php`, `app/Services/HistoricMedia/HistoricProcessingResultReadinessService.php` | Delayed/retried/failed storage cannot be overtaken by quality, promotion or cleanup. |
| M3 title provenance | `app/Support/PlaceholderSermonTitle.php`, `app/Jobs/ProcessTranscriptWithAI.php`, `app/Data/SermonCreationOptions.php`, `app/Services/Sermon/SermonCreationService.php`, the bounded banked replay service | Both canary filename shapes change; a curated date/event title never does. |
| M4 song custody | `app/Models/SongVideo.php`, `app/Services/Song/SongVideoService.php`, `app/Services/HistoricMedia/HistoricAssetPromotion.php`, filesystem schema/migrations, custody census and cleanup | Historic create/promote/resolve/retry/conflict/cleanup plus exact canary backfill replay. |
| M5–M6 song gate | `app/Services/ChurchService/SectionPublication/SongPublicationHandler.php`, song matching/write-back, sermon/section publication candidate preparation | The boundary fixtures named in M5, including no sermon hold for the short ambiguous bridge, and threshold/corroboration cases in M6. Do not add a separate model call or blanket sermon-review rule. |
| M5a transcript-lyrics deletion | `app/Jobs/MatchSongsFromTranscript.php`, `tests/Feature/Jobs/MatchSongsFromTranscriptTest.php` | Exact lyric-like service transcript remains unmatched after title/OCR failure; existing title-hint and OCR matches remain unchanged; no live `lyrics` source writer remains. |
| M7 talk review | sermon section publication handler, section candidate preparation and the existing approval/re-extraction path | Inclusive ambiguous tail remains private; reviewed recut is exact and idempotent. |
| M10 historic sermon quarantine | `app/Services/Sermon/SermonCreationService.php`, `app/Jobs/SubmitToProcessing.php`, `app/Services/HistoricMedia/HistoricAssetPromotion.php` | Stranded historic run stays quarantined and disk-bound; ordinary livestream still publishes; promotion replay idempotent over pre- and post-change rows. |
| M9 review-source retention | `app/Jobs/CleanupTemporaryFiles.php`, `app/Models/MediaProcessingLog.php` (`temporaryFilePaths()`), `app/Services/HistoricMedia/HistoricVideoPassMeasures.php`, `HistoricStagingHeadroom`, the reclamation sweep and `sermons:re-extract` | Unflagged run cleans up as today; each flagged shape retains its source; resolution makes it reclaimable and the sweep is idempotent; retained bytes appear in measures and alongside headroom evidence without being added twice to current free-space use. |
| M11 proportionate integrity | `app/Services/Media/Video/HistoricVideoImporter.php`, `app/Services/Processing/UnifiedMediaProcessor.php`, `app/Services/Media/Video/VideoStorageService.php`, `app/Services/HistoricMedia/HistoricAssetPromotion.php`, `app/Services/HistoricMedia/HistoricProcessingResultAssetTransfer.php` | One selected-source content traversal for staging copy, no routine historic hash pass, exact path/size/copy failures, null historic `file_hash`, truthful approved-hash provenance, conflict-only hashing, ordinary-upload and Bundle A behaviour unchanged. |
| M12 performance and concurrency | `app/Console/Commands/HistoricVideoPassStatusCommand.php`, new focused `app/Services/HistoricMedia/HistoricVideoPassPerformance.php`, `app/Jobs/GenerateRmsLog.php`, `AnalyzeSegments`, `ExtractAudioFromVideo`, `GenerateThumbnail`, `app/Services/HistoricMedia/HistoricProcessingThroughput.php`, `HistoricStagingHeadroom`, `HistoricProcessingFingerprint`, `HistoricProcessingResultBundleExporter`, importer metadata construction and historic worker configuration | Scoped run/step p50/p95, queue-wait and overlap report; explicit missing/retry treatment; success/failure/skip timing for high-cost jobs; create-only JSON; selected bytes and configured widths drive headroom without retained-byte double count; no `--parallel`; one pass-scoped fingerprint/profile computation; legacy-width normalization and mixed-width export; recorded one-versus-two-FFmpeg calibration. |

Inspect sibling tests before adding new ones. Keep PHPUnit `#[Test]` style and the
repository's existing historic operation factories/helpers. A reported defect
gets its reproducing test first, then the fix. Do not add coverage to the legacy
duplicate admin suites named in the repository do-not-invest list.

#### Required operator sequence

1. Implement M1–M7 and M9–M12 with focused regression coverage. Run PHPStan, Pint
   and the full parallel suite. Do not deploy only the row-repair commands while
   the forward pipeline can recreate the same defects. M9 gates M5 and M7: do not
   ship a review hold whose media the same chain then deletes.
   **Done as of 2026-09-01** — see "M9, M5 and M7 implemented" above for the two
   blockers and two defects that review found and fixed. Steps 2–11 below are all
   still outstanding, and step 10 now additionally has to report the
   leading-framing false-positive rate over the eleven named M5 sections.
   **The M2, M5 and M6 decisions are recorded in their findings as of
   2026-08-31; no open decision blocks remain.** M6 shrank to making `Inferred`
   ineligible, and is not a bulk blocker. M5's interval work is deferred until
   after bulk, so the pre-bulk song work is boundary evidence and review routing
   only. M1, M3 and M4 are
   specified to the level of exact columns, keys, commands and test files and may
   be picked up as they stand. M9's retention predicate depends on M5's
   material-risk definition, so settle M5 first. M11 deliberately changes the
   earlier hash-verification acceptance tests; update them to the recorded risk
   decision rather than preserving contradictory assertions. M12 must land before
   the next processing pass so its evidence is retained rather than reconstructed
   from chat again.
2. Deploy that exact tree and restart every historic worker so the dispatcher,
   jobs, nested-work tracking and repair commands share one byte-affecting build/
   model fingerprint. Record the worker widths and queue routing separately in
   the pass execution profile.
3. From `historic-import:video-pass-status`, retain the exact processing ID for
   `2024-03-03-morning`. Dry-run the status again, then run
   `historic-import:recover-processing-tail <processing-id> --operation=historic-60b16730090144bd307984abf538a7d7`.
   Wait for promotion and cleanup to reach a truthful terminal state and verify
   sermon 896 is private, operation-bound and resolvable from quarantine.
4. Repair or explicitly hold sermon 893, then run the observed-media duration
   repair for every M1 row. The existing command may be reused only after it reads
   the persisted observed duration rather than summing the requested plan. Retain
   the exact processing IDs linked to sermons 898, 899 and 901 when running
   `historic-import:repair-video-sermon-durations --operation=historic-60b16730090144bd307984abf538a7d7 --processing-id=<id>`
   once per exact ID set, review the dry-run table, then repeat with `--apply --yes`.
   Metadata must equal FFprobe within 1.5 seconds; any larger difference remains a
   blocker.
5. Apply the provenance-bound title repair to 898 and 899 with
   `historic-import:repair-video-sermon-title-provenance --operation=historic-60b16730090144bd307984abf538a7d7 --processing-id=<id>`,
   reviewing the dry-run table before `--apply --yes`: it records `Generated`
   only where the stored title exactly reproduces from the run's filename, and
   refuses everything else. Replaying banked analysis afterwards is what actually
   changes the titles. Then run the bounded speaker replay to their exact banked audio. Prove curated fields are unchanged and no
   sermon-analysis request was made.
6. Run the dry-run-first song-custody repair for the exact 23 canary `SongVideo`
   rows and every held canary candidate. Verify private state, operation and disk
   ownership, destination sizes and absence of duplicate staging copies. Hash only
   an already-existing destination to resolve an idempotent replay or conflict.
7. Split the 21 pilot processing IDs by their exact owning operation (2 or 3).
   For each operation run `historic-import:repair-video-pilot-custody` with every
   exact `--processing-id`, retain the dry-run table, then repeat with
   `--apply --yes`. Verify every repaired sermon is `quarantined`, names
   `historic_quarantine`, belongs to that operation, resolves every asset there,
   and retains no duplicate staging copy.
8. Re-run `historic-import:video-pass-status --measures` for the frozen 14 keys.
   Export the path census for every non-zero byte: owned active/retryable work,
   named operation artifact, platform sidecar or genuine orphan. Do not delete a
   path merely because the earlier unscoped report called it residue.
9. Run `historic-import:video-pass-status --performance` and retain its create-only
   JSON report. Record the acceptance evidence still absent from the first result: per-identity
   title/reference/duration/series/speaker audit, projection and song eligibility,
   model/token/request counts, neutral/unobservable transcript rate, elapsed and
   p50/p95 duration, queue wait, observed/configured worker concurrency, retry and
   missing-timing counts, and the resulting 12- or 24-hour resource envelope.
   Record the M5 canary sections as reviewed, not silently accepted because their
   song identities were correct.
10. Dispatch the identical frozen 14-key selection. It passes only if it creates no
   processing identity, provider call, asset or notification, all 14 identities
   retain truthful terminal dispositions, observed media durations remain correct,
   all sermon/song assets are private and operation-owned, inclusive ambiguous
   sermon bridges remain automatic, every material-risk boundary remains
   reviewable, and custody residue is zero or exactly enumerated.
11. After that no-op proof, run M12's four-identity fresh calibration with two
   FFmpeg workers. Retain or revert width two from the recorded 25%/failure/disk
   gates, record the resulting execution profile after any revert, and use the
   accepted width plus measured resource envelope to size the first Phase 8 pass.
   Worker width must not regenerate or split the durable-output fingerprint. Only
   then may Phase 8 start.

### Operator sequence steps 10 and the work that unblocked it, 2026-09-01

Step 10 could not run while two of the fourteen identities sat in non-terminal
states. Both are now resolved, and the replay has passed.

**A drive-wide search for missing or miscategorised sources found none.** All 510
video files on `/mnt/cbc-services` appear in the curation worksheet; none is
unreferenced. Exactly ten directories hold more than one video and exactly ten
worksheet entries are marked `concatenation: lossless` — a one-for-one match, so no
multi-segment service is unconcatenated. The church's practice of stopping the
recording for each song is real and visible (`2024-01-14` morning is five segments
with 2.5–5 minute gaps, `2024-12-22` evening is ten), and it is already handled.
Ninety-five audio files (73 mp3, 20 wav, 2 m4a across 86 dates) are on the drive
referenced by nothing in the video worksheet; only two coincide with short video
items and neither is under twenty minutes, so they fill no gap. Whether the legacy
MP3 lane covers them is open.

**Two recording practices, and the sermon gate was wrong for one of them.** Morning
recordings run to a median of 64.8 minutes and hold the whole service; evening
recordings run to a median of 23.8 and hold the sermon alone. Where the sermon sits
inside the file separates them cleanly: sermon-only captures put it within 50 s of
the start and cover 92.5–98.0% of the recording, whole-service captures 31.4–67.0%.
`SermonCandidateConfidenceService` applied a flat 1200 s floor to both, which in a
sermon-only recording no longer selects a sermon out of competing speech — there is
none — but merely measures how long the sermon ran, rejecting every evening sermon
shorter than twenty minutes.

Corrected across `13501bef9` and `c0cbc3e86`. A sermon-only recording now has **no
qualifying floor at all**; its candidate is compared with what that service's sermon
usually runs to (morning 1500 s, evening 900 s) and, below that, routes to manual
review under a new reason `sermon_shorter_than_typical`. Nothing is refused on
length, because a carol service may legitimately carry an eight-minute sermon and
length cannot distinguish that from a non-sermon item recorded on its own. An
unnamed service takes the stricter figure. The whole-service path is untouched.
Effect on the approved manifest: 26 items now extract automatically that no run
could previously reach, and 10 route to review on length.

**Four non-service items and four short items were excluded** in the adjudicated
worksheet, taking approved membership from 470 to **462**. The frozen manifest was
deliberately *not* re-frozen: operation 3 records
`manifest_hashes.historic_video = 1ae7e4fc…`, which
`ProcessingRunOrchestrator::stagingContextMatchesOperation()` checks, so re-freezing
in place would have invalidated the very replay step 10 depends on. Re-freeze into a
new manifest bound to the Phase 8 operation. Full list:
`storage/scratch/historic-video-short-item-dispositions-20260901.md`.

### Decision 12 — content verification deleted, manifest re-frozen (2026-09-01)

**The verification machinery is gone.** `$verifySourceContents` and the
`hash_file()` comparison in `verifiedPath()` are deleted. Its two callers were a
hardcoded `false` and one that ran after `PrivateEvidenceFile::writeOnce()`, so on
any frozen manifest it was unreachable and on a fresh capture it only re-read files
hashed seconds earlier — `historic-import:capture-video-curation` was reading ~1.0 TB
**twice**. Decision 6's premise was also false: `assertApprovedSourceMetadata()`
compares **byte size only** and never the hash, and the "existing tamper test" it
cites does not exist. The unconditional stat checks are untouched. The `sha256`
*field* stays in schema v4 — removing it is a v5 migration that would orphan the
`manifest_hash` on completed runs, and `fileContentIdentity()` needs it as soon as a
`duplicate_of` is declared.

**Re-freeze.** Seven morning identities were replaced or augmented from the church
PC. New manifest `historic-video-curation-manifest-20260901.json`, manifest hash
`d25d2085…`, plan hash `9351fa4e…`, batch key unchanged. 474 identities / 517
declared recordings; **464 includes** (up from 462: two exclusions reversed) and 10
excludes; 292 `full`, 163 `short_partial`, 9 `fragmented`. Capture was carry-forward
— reuse the frozen hash where `(relative_path, byte_size)` is unchanged — 504 reused,
13 computed, 2.29 GB in 19.5s, then validated through the real `plan()`.

| identity | before | after |
| --- | --- | --- |
| `2020-06-14-morning` | 1 file, 23.78 min | higher-bitrate `[NO NAME]` copy governs; old copy parked in a new excluded sibling. Same duration — it was **not** a more complete recording |
| `2020-06-28-morning` | *excluded*, two 49s clips | **reversed** — 23.18 min sermon included; clips parked in an excluded sibling |
| `2023-07-16-morning` | *excluded*, 6.75 min children's talk | **reversed** — 67.49 min, single, `full`. Its reason said the sermon "is in no file held on the drive" |
| `2024-07-28-morning` | 5 fragments, 52.42 min | single 64.47 min, `full`. Declared but blocked by the completed run and sermon 890, left in place by operator decision |
| `2025-05-18-morning` | 1 file, 24.62 min | 5 files, 54.38 min, `fragmented` |
| `2025-08-10-morning` | 1 file, 24.99 min | 5 files, 52.21 min, `fragmented` |
| `2026-03-15-morning` | 1 file, 22.88 min | 2 files, 25.15 min, `fragmented` |

Grade coupling is why only two reach `full`: `corroboration === fragmented` iff more
than one file, so an identity that *gains* segments cannot claim whole-service
corroboration however long it now runs.

**`prepare-operation` run 2026-09-01.** New operation `historic-c24f1acfc3b4f9986882be35c917b73f`,
same batch key, plan `9351fa4e…`, manifest `d25d2085…`, deadline `2026-09-30T23:59:59+01:00`
(matches operation 3's). Rehearsal target, no `--runtime-evidence` supplied (optional there).
The 28 runs completed under `1ae7e4fc…` will report `skip-exists` rather than `resume-completed`
when replayed under this operation, because the resume `dedup_key` is keyed to the manifest
hash — expected, not a corpus collision. Steps 10 and 11 re-run against the new plan once the
drive is reachable again (see below).

**Re-running step 10 is currently blocked on a drive-mount fault** — a new variant of the
Docker Desktop stale-`/host_mnt`-bind class: `diskutil verifyVolume` came back clean but a
throwaway `docker run -v /Volumes/Sonnics/Services:/x alpine ls /x` still fails with
`open /host_mnt/…: not a directory`, i.e. Docker Desktop's VM itself holds a stale type for
the path, not just the long-running containers. A full Docker Desktop restart was underway at
time of writing.

**A run whose source was replaced can now be retired (2026-09-02).** `historic-import:exclude-run`
could not do this: an exclusion is terminal, it deliberately never touches
`MediaProcessingLog.status`, and the dispatcher's date-block
(`HistoricVideoImporter::checkExistence()`) reads only `status`, so excluding a run never
unblocks its date. Retirement is the opposite decision — the recording changed, so the run's
result is *withdrawn* and the identity is expected to import again.

`historic-import:retire-run` (dry-run default, `--apply --yes`, note required, idempotent,
refuses another operation's run) uses the supersession the schema already carried:
`media_processing_logs.superseded_at`, which `ServiceSection` and the review dashboard already
honour, so a retired run's sections drop out of every reader with no second mechanism. Two
gaps had to close around it:

- `checkExistence()`'s completed branch ignored supersession. The manual-review branch has
  honoured it since July; the completed branch did not, so a completed-then-retired run
  blocked its own re-import. Now `notSuperseded()`.
- `HistoricVideoPassStatus::disposition()` would have read a retired run's own status. It now
  filters retired runs first: an identity with only retired runs reports `retired`, and where a
  later run exists that run alone gives the disposition, so retire-then-reimport reads
  `completed` rather than `mixed_terminal`.

The sermon needed explicit work because sermons have no supersession state, and adding one to
`SermonPublicationState` was rejected: the enum is shared with `SongVideo` and
`SongUsageReport`, and `SermonStorageService` branches on `=== Quarantined` in five places
where a third state would fall through to the public disk path. Instead the sermon's assets
move to `superseded/{operation}/{sermon}/` on their own disk, a `retirement.json` inventory is
written beside them, the same inventory (id, slug, title, duration, paths, byte sizes, sha256)
goes into `processing_metadata.retirement`, and the row is deleted — leaving every existing
sermon query correct with no change. A published sermon, or one any service section has
published, is refused; `service_sections.published_sermon_id` is `ON DELETE RESTRICT`, so the
schema already fenced the destructive path.

**Both deferred identities were retired 2026-09-02** under operation
`historic-60b16730090144bd307984abf538a7d7`, note recorded as the manifest re-freeze:
`2023-07-16-morning` (run 959, no sermon, 2 sections; its `no_sermon_in_source` exclusion is
kept as true history of the *old* source) and `2024-07-28-morning` (run 943, sermon 890
withdrawn, 4 assets / 471.2 MiB relocated, 16 sections). Verified: sermons 865 → 864, log count
unchanged at 946, superseded logs 6 → 8, both dates now report no existence block, both
identities report `retired`.

**An operator can now record why a run was excluded (`1643ab001`).** The only
exclusion the system could express was a silent source, which `AnalyzeSegments`
detects without anyone looking; a recording that simply holds no sermon needs a
person, and that decision had nowhere to go. Exclusion reasons are now a set, with
operator reason `no_sermon_in_source` and command `historic-import:exclude-run`
(dry-run by default, `--apply --yes`, note required, idempotent, refusing another
operation's run and refusing to hand-write the reason the pipeline owns). The pass
reader tests exclusion **before** every other terminal reading, manual review
included, and the run keeps its own status so nothing about what happened is
rewritten.

**Both blockers cleared.** `2026-04-02-evening` was restarted from `rms_generation`
— the retry plan resumes at the failed step, which is two phases downstream of where
the exclusion is decided — and reached `completed` in 27 s carrying
`source_audio_silent` with 22,857 frames all `-inf`, releasing 183,569,962 stranded
staging bytes. That is the first real-data proof of the silent-source path, at no
provider cost. `2023-07-16-morning` was excluded through the new command with its
transcript as the note.

**Step 10 then passed**, in full, in 3.9 seconds. Every acceptance item and the path
census are recorded in `storage/scratch/historic-video-step10-noop-proof-20260901.md`.
Custody residue is enumerated rather than zero: 2.90 GiB, censused by path, nothing
deleted.

Three things Phase 8 must carry forward:

1. **Every real dispatch requires `--host-capacity-evidence`.** Staging free space is
   not measurable from inside the container — it reports the parent filesystem, not
   the drive — so the figure must come from a host `df` on
   `$CBC_HISTORIC_WORK_PATH` and be written into an operation-and-plan-bound JSON.
2. **`queue:restart` did not cycle the workers.** The worker process age was still
   25,382 s after the signal; the containers had to be restarted directly. Verify
   with `ps -eo etimes,cmd | grep queue:work` inside a worker, never with container
   uptime. A fix is not live until that check shows a fresh process.
3. **A retry resumes at the failed step, not at the fix.** Any run that failed
   downstream of a subsequently-fixed job must have its `current_step` pointed back
   at a phase whose retry action is a full restart before it will re-enter the fix.

Two findings outside step 10's scope, both open:

- **Six pilot sermons overstate their video by 4–15 minutes** — 878, 889, 881, 891,
  884 and 885, measured as stored duration against the probed file. All are
  quarantined, so nothing public is affected, but they would be wrong if released.
  Same class as the 893 truncation already repaired; no frozen-14 sermon is
  affected. Note that `sermon_end_time - sermon_start_time` on the processing log is
  the candidate span and **not** the authority — probe the file.
- **The dispatcher's skip logic does not know about exclusions.** It reports
  `2023-07-16-morning` as `[skip-pending-review] awaiting manual sermon review`
  though the run is excluded. It skips either way, so the no-op holds, but this is
  the same untruthfulness fixed in `HistoricVideoPassStatus::disposition()`, in a
  second place. Fix before Phase 8 if an excluded run can remain in a manifest.

**Only step 11 remains before Phase 8.**

### Step 10 re-run under the re-frozen manifest, 2026-09-02

The 2026-09-01 proof ran against manifest `1ae7e4fc…` and plan `8ecec582…`, both of
which the same evening's re-freeze retired. Step 10 was therefore re-run against
manifest `d25d2085…`, plan `9351fa4e…` and operation 4
(`historic-c24f1acfc3b4f9986882be35c917b73f`). Evidence:
`storage/scratch/historic-video-step10-rerun-proof-20260902.md`.

**The drive-mount fault that blocked this cleared on its own.** Both
`/mnt/cbc-services` and `/mnt/historic-work` read normally from inside the
containers; no Docker Desktop restart was needed.

**The frozen fourteen no longer resolve to fourteen replayable identities, and the
selection had to be split before it could be dispatched.** Twelve carry a
byte-identical source and are replayable. `2026-04-02-evening` was promoted from a
run-level exclusion to a **manifest-level** one, so the dispatcher refuses it in
`--only` as "not an included work item" rather than skipping it — exclusions moving
upstream is the stronger placement. `2023-07-16-morning` had its source replaced
(`10-43-29.mkv`, 134,980,780 B → `Sunday 16th July 2023.mp4`, 291,240,303 B) and its
run retired, so it is new work; including it would have dispatched a real pipeline
run with provider spend, which is the opposite of what step 10 asserts.

**The twelve replayed clean in 7.2 seconds**: dispatched 0, skipped 12, errors 0,
0 B processed, 32.5 GiB skipped. Every acceptance item passes — no processing
identity, no provider call, no asset, no notification, all twelve videos probing to
their stored duration with a delta of **0.000 s**, all twelve `quarantined` on
`historic_quarantine`, the one automatic sermon bridge still unflagged and the
material-risk boundaries still flagged. The before/after baseline JSON differs only
in its `captured_at` field.

Three things this establishes for Phase 8:

1. **A re-freeze permanently weakens the idempotency evidence for every item
   carried across it.** The resume key is
   `sha256("historic-video\0{manifestHash}\0{item_key}")`, so a run completed under
   the old manifest can never match a resume key under the new one. All twelve
   reported `skip-exists` where they previously reported `resume-completed`: the
   dispatcher now proves "this service is already processed" rather than "this exact
   manifest item is already processed". The no-op still holds; the claim behind it
   is one notch weaker.
2. **Supersession kept the section reader honest with no new code.** The
   review-flag count fell from 13 to 12 because retired run 959's flagged section
   dropped out of `ServiceSection`'s reader automatically — the existing mechanism
   the retirement work deliberately reused.
3. **Operation 4's only filesystem side effect was an empty batch-root skeleton**
   at `staging/historic-batches/9351fa4e…` (512 K). Its custody measures are all
   zero; total staging is unchanged at 6.1 G (op 2 1.8 G, op 3 4.3 G).

**Operator decision: `2023-07-16-morning` is deferred to Phase 8**, alongside the
other six replaced or added sources (`2020-06-14-morning`, `2020-06-28-morning`
(new), `2024-07-28-morning`, `2025-05-18-morning`, `2025-08-10-morning`,
`2026-03-15-morning`). No import runs before step 11 has set the FFmpeg width.
Step 10 therefore stands as **passed for the twelve carried-over identities with one
identity explicitly named as outstanding**, not as silently complete. Whichever pass
carries `2023-07-16-morning` is also the first real-data test of the
sermon-only/short-sermon rework (`c0cbc3e86`) against a source that previously found
no sermon at all — watch it in that pass rather than assuming it.

### Step 11 — M12 item 14 calibration completed, width reverted, 2026-09-02

The four runs staged the day before (970/969/968/967) had all been blocked
identically at `rms_generation` by the VirtioFS/exFAT mount fault (see
`storage/scratch/historic-video-step11-calibration-20260902.md`). After that fault
was fixed by switching Docker Desktop's file sharing from VirtioFS to gRPC FUSE,
the four runs were retried in place — via `UnifiedMediaProcessor::retry()`, the
same code path the API's retry endpoint uses — with no re-dispatch and no re-copy
of the 20.4 GiB already staged. Full evidence:
`storage/scratch/historic-video-step11-calibration-result-20260902.md`.

**The mount fix held under real concurrent write load.** All four runs cleared
`rms_generation` — the exact step that killed all four the day before — with no
recurrence.

**3 of 4 completed cleanly; the 4th stopped for a content reason, not a technical
one.** `2026-05-10-morning` failed at `manual_review_required`: the detected
service structure had a song section overlapping the sermon section by 3.1
seconds. The structure detector correctly stopped rather than publish an
overlapping section — unconnected to worker width or the mount fix, and a
separate item for an operator to look at later.

**M12 item 14's gate fails, decisively.** Queue-wait p95 improved 44–98% on
every instrumented FFmpeg step (`extract_sermon` 2,515s→278s,
`prepare_section_publication_candidates` 2,128s→49s, `audio_enhancement`
1,107s→621s, `assessing_video_quality` 1,229s→185s) — comfortably past the 25%
bar. But active-duration p95 got **materially worse** on the two steps that touch
the full source file: `extract_sermon` +69% (649s→1,096s) and
`prepare_section_publication_candidates` +94% (482s→936s). This is not a
bigger-files artefact: normalizing `extract_sermon`'s active duration by source
size shows the width-two runs doing roughly half the baseline's per-GiB
throughput on comparably-sized sources (baseline ~0.011–0.015 GiB/active-second
vs ~0.0069–0.0088 at width two). **Two concurrent FFmpeg workers genuinely split
the host's FFmpeg throughput rather than adding capacity for free** — the
queue-wait gain and the active-duration loss cancel out, leaving items/hour at
+1.7% (3.021→3.074) and content-hours/wall-hour at +14.7%, both far short of the
25% bar item 14 requires from either metric. The sample was also incomplete
(3 of 4 clean), which item 14 itself instructs to treat as inconclusive.

A confound is worth recording though it doesn't change the verdict: the width-one
baseline was captured under VirtioFS, and this pass ran under gRPC FUSE (switched
for the unrelated mount fault). Width and file-sharing driver changed together,
so how much of the active-duration hit is worker contention versus gRPC FUSE's
own I/O characteristics isn't separated by this data. Item 14 already fails on
its stated gates regardless; a future width-two attempt should re-baseline width
one under gRPC FUSE first to isolate the two variables cleanly.

**Reverted per the plan's own pre-written fallback.** `.env` backed up to
`.env.backup-before-step11-revert-20260902`, `HISTORIC_MEDIA_WORKERS_FFMPEG`
restored to `1`, `sail up -d` removed the second FFmpeg worker container, worker
1 restarted deliberately (the same stale-in-memory-config trap that applied going
to width two), and the dispatcher confirmed reading width 1 afterwards. The three
width-two completions keep their own recorded `worker_width: 2` execution profile
permanently — per M12 items 8–10, width is execution tuning, not a durable-output
fingerprint, so it doesn't collide with width-one runs on export.

**Step 11 is closed. Phase 8 is unblocked at FFmpeg width one** — the only width
ever proven clean end-to-end. Two loose ends carry forward outside step 11's
scope: `2026-05-10-morning`'s section-17 overlap needs an operator look, and the
31 webm/VP9 sources noted in the prior write-up still need one identity proved
before bulk.

### Pass 1 — first stratified learning batch, 2026-09-02

Run as a deliberately small learning batch rather than an overnight throughput
pass, at the operator's direction: process a few hours, then iterate the
processing logic on what real services reveal. Selection was **stratified, not
chronological** — the first 40 chronological items are 100% morning and 100%
from 2020, so a chronological pass samples one service shape and teaches almost
nothing about the corpus. The 11 chosen covered every year 2020–2026, both
services, all three corroboration grades, both concatenation modes, and two VP9
sources, at a 2.47 GiB mean against the corpus mean of 2.27 GiB.

Evidence: `storage/scratch/pass1-{dispatch,capacity-evidence,baseline-BEFORE,
baseline-AFTER,performance-BEFORE,performance-AFTER}-20260902.json`.

**Result: 5 failed, 6 degraded, 0 clean, in 1.3 h.** The wall time is not
throughput — it is inflated by five runs failing fast. Corpus delta: +11 runs,
+6 sermons, +10 song videos, +64 sections, +49 segments, +11 alerts.

Dispatch itself was clean: 11 dispatched, 2 lossless concatenations, 0 errors,
`aborted_stale_mount: false`, 29.1 GB staged. **The codec fix from `fc7e7ecd3`
was confirmed firing inside a real bulk run**, not just a re-extraction:
`Re-encoding video extract: source video codec is not deliverable`,
`source_video_codec: vp9`.

#### The 429s are flex-tier capacity, not rate limiting — diagnosed 2026-09-02

**Superseded reading (kept so the correction is legible):** the pass was first
recorded as hitting "41 `Request rate limit has been exceeded` errors, escalating
1/min at 16:32 to 7/min at 17:01", with RPM-versus-TPM undetermined. Both the
cause and the count were wrong.

`config/openai.php` sets `service_tier => env('OPENAI_SERVICE_TIER', 'flex')` and
`.env` does not override it, so every paid call asks for flex. OpenAI answers a
flex request it has no spare capacity for with **HTTP 429**, and
`openai-php/client` raises every 429 as `RateLimitException` whose message is a
**hardcoded constant**, `"Request rate limit has been exceeded."` Nothing in the
pipeline reads the response body or headers, so the real reason never reached a
log. Reproduced live on 2026-09-02 with a 7-token `ping`:

    HTTP 429  type: resource_unavailable  code: flex_unavailable
    "Flex does not have sufficient resources available to fulfill your request …
     or change service_tier=default."
    retry-after: 300
    x-ratelimit-remaining-requests: 4999 / 5000
    x-ratelimit-remaining-tokens:   1999997 / 2000000

**Flex capacity is per-model and independent of this project's load.** Probed
three times each, both tiers: `gpt-5.6-luna` (structure detection) **0/8 on flex,
3/3 on default**; `gpt-5.6-terra` (analysis) and `gpt-5.4-mini` (song OCR) 3/3 on
both. That is the pass's pattern exactly — luna failing intermittently while the
three `song_lyric_ocr` calls at 16:40 sailed through. Swapping the detector model
is not a workaround: the config default `gpt-5.6-sol` is **1/4 on flex**. The
frontier structure-detection models are the starved ones.

**As of this writing pass 2 cannot run at all on flex** — luna's pool is refusing
everything, so every structure detection would fail. The analysis model is
currently healthy, so P1-3's re-analysis of sermons 907–912 is not blocked.

Three independent facts rule the rate-limit reading out:

- **The budget was untouched** — 99.98% of both the request and token allowance
  remained at the moment of a 429.
- **The pass could not have generated load.** `DetectServiceStructure`,
  `ProcessTranscriptWithAI` and `MatchSongsFromTranscript` all route to
  `historic-llm` (`HistoricProcessingThroughput::JOB_STAGES`), which runs **one**
  worker. At most one completion was ever in flight: 32 calls in 44 minutes,
  0.73/min, serialised — and two of them succeeded 35 s apart while three
  song-OCR calls succeeded inside 5 s.
- **Failure does not track request size.** The **largest** transcript in the batch
  (292,887 B → 67,806 input tokens, `168199ed` 2025-01-12) succeeded first time,
  while the two **smallest** (55,404 B and 57,937 B, ≈14–15k tokens) failed all
  three attempts. There is no TPM story.

The "41 errors" figure counted wrapper log lines — a permanent job failure emits
three (`Processing run failure`, `job failed permanently`, the raw exception), and
those clustered late, manufacturing the apparent escalation. The API-level count
is **23** (17 structure + 6 analysis), roughly flat across the window.

Sizing a pass on FFmpeg throughput is still wrong, but the binding constraint is
a provider capacity pool this project cannot influence by pacing itself.

Full evidence and reproduction: `storage/scratch/pass1-rate-limit-diagnosis-20260902.md`,
`storage/scratch/pass1-rate-limit-probe-20260902.php`.

#### The two paid stages disagree about what a provider failure means

`DetectServiceStructure` carries `tries = 3` with `backoff() = [120, 300, 600]`
and used all three attempts. `ProcessTranscriptWithAI` declares the same, but
**its retries are dead code**: `catch (\Exception $e)` at line 171 runs
`createFallbackAnalysis()`, sets `is_degraded_completion` and returns *without
rethrowing*, so the queue never sees a failure. All six analysis calls got exactly
**one** attempt, 12–18 s long. The two stages diverge, and that divergence is the
real defect:

- **`DetectServiceStructure` fails hard.** `OpenAiServiceStructureService`
  catches only `TypeError`, so a 429/503 propagates and the run fails. All five
  failures were at this step.
- **`ProcessTranscriptWithAI` degrades silently.** It substitutes
  `createFallbackAnalysis()`, sets `is_degraded_completion = true`, and the run
  **completes** (`ProcessTranscriptWithAI.php:233`).

So provider pressure does not degrade output smoothly. It splits into loud
failures and silent hollow successes, and the silent half is the half that gets
banked. **Judged on completed count, a pass that met more provider refusals looks
better than one that met fewer.**

`createFallbackAnalysis()` returns `reference: null`, `summary: null`,
`points: ['Main Message']` and a generated title. Observed on sermons 907–912:

    sermon 907  title="Sunday 26Th January 2025 [Yo…"  ref=NULL  summary=NULL
    sermon 909  title="Morning"                        ref=NULL  summary=NULL

Titles are raw source filenames, one carrying a `[YouTube backup]` fragment.
This is the absence of analysis recorded as completed work — arguably worse than
failing, because a failure is retryable and this looks done.

`WithoutOverlapping` on `DetectServiceStructure` is keyed per run, so it does not
pace across runs — but that is moot: the whole `historic-llm` stage is one worker,
so provider calls are already strictly serial, and corpus-wide pacing would not
have prevented a single one of these 429s.

#### Measured stage timings — gRPC FUSE at width one

The measurement that did not previously exist. Step 11's baseline was captured
under VirtioFS, which it flagged as an unseparated confound.

| step | stage | n | p50 active | p95 active | p95 queue wait |
|---|---|---|---|---|---|
| rms_generation | ffmpeg | 11 | 101 s | 152 s | — |
| analyzing_segments | ffmpeg | 11 | 1 s | 2 s | 1,029 s |
| transcribe_full_service | whisper | 11 | 192 s | 431 s | 2,036 s |
| detect_service_structure | llm | 11 | 35 s | 109 s | 1,243 s |
| extract_sermon | ffmpeg | 6 | 354 s | 633 s | 457 s |
| prepare_section_publication_candidates | ffmpeg | 6 | 18 s | 262 s | 503 s |

Two findings. **Queue wait dominates active time at every stage** — Whisper
waits 2,036 s to do 431 s of work — so the pipeline is queue-bound at width one,
not CPU-bound. That reframes M12 item 14: step 11 rejected width two on
*active-duration* regression, but wall time is being spent waiting, not working.
And **`extract_sermon` p95 is 633 s under gRPC FUSE against step 11's 649 s
under VirtioFS**, so the driver is not materially slower for extraction, which
partially clears step 11's confound.

#### Two operational faults, independent of the provider

Both were hit before any run reached a paid stage, and both are recorded in
detail in the session memory rather than repeated here:

1. **The worker daemons stopped honouring `queue:restart`.** The restart key was
   newer than the worker boot, the queue was empty, and the daemon sat idle 19
   minutes without exiting; three `queue:restart` calls did nothing. Only
   `docker restart` on the worker containers cleared it. Always verify worker
   PID-1 age actually dropped before concluding anything about a code change.
2. **A first-job failure strands a run in a state no retry path accepts.** The
   run stays `pending` with no queued jobs; `ProcessingRunOrchestrator::retry()`
   refuses ("not in failed or cancelled state") and `HistoricVideoImporter`
   classifies `Pending` as `resume-inflight` and merely waits. The cause is that
   the staging activation runs in a `Queue::before` listener
   (`AppServiceProvider.php:168`), so an exception there never reaches the job's
   own `failed()` handler, which would have marked the run. Recovery required
   force-setting `status = failed` with an operator note, then retrying.

Together these are the silent-wedge mode for an unattended pass: every run fails
at the first guard, every run lands unreachable, and the pass looks identical to
healthy queuing. **Pass monitoring must alarm on "nothing in flight while the
pass is incomplete", not on a failure count.**

#### Remediation before pass 2

- **P1-1 — Make the paid stages survive flex unavailability. DONE 2026-09-02.**
  *Rewritten after the diagnosis above; the original text called for a wider
  backoff window or corpus-wide pacing, and neither touches the actual fault —
  a shared capacity pool this project does not load.* All four items landed, and
  the result was verified against the live provider while luna's flex pool was
  still empty: the flex request was refused, the fallback re-sent it on
  `default`, it succeeded, and both the refusal and the effective tier reached
  the log. What was required:
  1. **Fall back to `service_tier: default` on `flex_unavailable`** — one shared
     helper alongside `App\Support\OpenAiTransientFailure`, used by
     `OpenAiServiceStructureService` and `SermonAnalysisService`. This keeps the
     flex discount whenever the pool has room and never fails a run because it did
     not. A blanket `OPENAI_SERVICE_TIER=default` also works but pays full price
     on all ~450 remaining structure detections.
  2. **Honour `Retry-After`** on any retry that stays on flex.
     `OpenAiTransientFailure::delayMs()` already does this and is wired only into
     the OoS path; the provider asked for 300 s and the first backoff step is
     120 s, so attempt 2 was near-certain to fail too.
  3. **Stop discarding the cause at the client boundary.** `RateLimitException`
     carries status, body and headers; every consumer reads only `getMessage()`,
     which is a constant string — that alone is why this was misdiagnosed for a
     day. Wire `App\Support\OpenAiRateLimitDiagnostics` into both paid stages, and
     fix `SermonAnalysisService::executeAiRequest()`, which rethrows
     `new Exception('OpenAI API call failed.')` **without** `previous`, breaking
     the cause chain any diagnostic would walk. (`RateLimitException` extends
     `Exception`, not `ErrorException`, so it misses that method's earlier
     rethrow — the same class-hierarchy trap `OpenAiTransientFailure`'s docblock
     records for the legacy OoS extractor.)
  4. **Log `CreateResponse::meta()`** — the `x-ratelimit-*` headers OpenAI returns
     on *successful* calls — next to the existing usage line, so headroom is
     visible without spending anything extra.

  As built: `App\Support\OpenAiFlexFallback` sends the payload, and on a 429
  logs the provider's real `error.code` and rate-limit headers before deciding —
  re-sending on `default` only for `flex_unavailable`, and rethrowing every other
  429 untouched, because a genuine rate limit is about this account and changing
  tier would neither help nor be honest. It returns an
  `App\Support\OpenAiTieredResponse` carrying the tier the call actually ran on,
  which `OpenAiUsageLogger` records instead of the configured value — a usage
  line that said `flex` after a fallback would be a fresh instance of the exact
  defect that caused this misdiagnosis. Wired into `OpenAiServiceStructureService`,
  `SermonAnalysisService` and `SongLyricOcrService`; the OoS email path keeps its
  own retry loop and is untouched. `SermonAnalysisService::executeAiRequest()` now
  attaches `previous`, and `OpenAiTransientFailure::isTransientInChain()` walks
  the chain, which is what makes P1-2's retry decision possible at all.
  The live verification is appended to
  `storage/scratch/pass1-rate-limit-diagnosis-20260902.md`.
- **P1-2 — A degraded completion must never read as a success. DONE 2026-09-02.**
  `is_degraded_completion` existed and nothing surfaced it. Now:
  - `HistoricVideoPassStatus` returns a **`degraded` disposition** rather than
    `completed`, and `mixed_terminal` where only some of an identity's runs
    degraded — so the summary line and the exit gate agree by construction.
    Adding this exposed a live defect: the report's explicit `get([...])` column
    list omitted the field, and `isDegradedCompletion()`'s `bool` return type
    turned the absent attribute into a hard `TypeError` rather than a silent
    `false`. The column is now selected.
  - `HistoricVideoPassStatusCommand` **names each degraded identity** in words,
    with its processing id and the instruction to re-analyse.
  - `HistoricVideoPassPerformance` carries `is_degraded_completion` per run, a
    `degraded_completion` classification and a `degraded` terminal disposition,
    and **excludes degraded runs from `clean_first_attempt`** — the aggregate a
    retrospective quotes as throughput. Report **version bumped to 2**: a v1
    `clean_first_attempt` counted runs that banked empty analysis, so the two
    versions are not comparable.
  - **The "should it bank at all" question is answered by making the retry real
    rather than by removing the fallback.** `ProcessTranscriptWithAI` now
    rethrows a transient provider failure while attempts remain, so degradation
    happens only after three genuine attempts on `[120, 300, 600]`. Before this,
    `tries` and `backoff()` were unreachable: the catch degraded on the first
    exception and never rethrew, so each of pass 1's six analysis calls got
    exactly one 12–18 s attempt. The fallback stays for failures that would fail
    identically three times over (a malformed response, a validation refusal),
    which is the case it was written for.
- **P1-3 — Re-analyse sermons 907–912. DONE 2026-09-02.** All six carried hollow
  analysis: no scripture reference, no summary, placeholder points,
  filename-derived titles. None are public — all are quarantined and unreleased
  — so this gated public release rather than production.
  **The plan's own premise needed one correction before this could run: "the
  service transcripts survive" was true but incomplete.** By the time of the
  repair, each transcript had moved off `historic_staging` (swept once its run
  completed) onto the sermon's own `asset_disk` (`historic_quarantine`) —
  exactly like its audio and video. `TranscriptStorageService` only checked a
  fixed candidate-disk list that has no way to know a per-operation quarantine
  disk name, so a naive re-dispatch of `ProcessTranscriptWithAI` would have hit
  "transcript is empty or unreadable" before ever calling the provider and
  banked a second hollow completion — the same symptom as pass 1, a different
  cause. **Fixed**: `TranscriptStorageService::readTranscriptFromPath()` now
  takes an optional `$ownerDisk`, checked before the generic candidates
  (mirroring the pattern `SermonTranscriptReader` — the public sermon-page
  reader — already used); `ProcessTranscriptWithAI` and `SermonTranscriptReader`
  both pass the sermon's `asset_disk` through it, and `SermonTranscriptReader`'s
  own duplicate private method is retired in favour of the shared one.
  **Second bug found and fixed the same way**: `ProcessTranscriptWithAI`'s
  success path always overwrote the hollow fields but never cleared
  `is_degraded_completion`, so a genuinely repaired run would have reported as
  degraded forever. It is now reset to `false` on every real success.
  **New command** `historic-import:reanalyse-degraded-completions
  --operation=<id> --processing-id=<id>...` validates each target is a
  completed degraded run owned by the named operation, then dispatches
  `ProcessTranscriptWithAI` on the calibrated `historic-llm` queue — no new
  analysis pipeline, the existing job's retry/backoff and flex-tier fallback
  apply unchanged. **Run for real** against all six: four succeeded on the
  first attempt, two hit a transient provider 503 (correctly classified,
  retried per the 2/5/10-minute backoff, succeeded on attempt 2 — P1-2's fix
  working as designed, not a new problem). All six sermons verified with real
  titles, Scripture references, summaries and points — checked against the
  database, not inferred from the cleared flag. Evidence: live queue-worker log
  (`ServerException: Server error (HTTP 503)`, correctly retried) and the
  before/after database read of sermons 907–912. All four quality gates pass:
  Pint, PHPStan (900 files, no errors), the full suite (7676 tests, 0
  failures) and Dusk (55 tests).
- **P1-4 — Remove the usage/cost reporting surface rather than repair it. DONE
  2026-09-02.**
  `op4.usage_entries` went 0 → 0 across a pass that made dozens of paid calls and
  met 23 provider refusals, and the report shows "API response-time samples: 0", so
  Phase 8 item 3's "monitor provider request/token anomalies" is inoperative.
  **Operator decision, 2026-09-02: cost reporting is not wanted now the pipeline
  uses Luna, so delete this surface instead of fixing it** — extending Phase 4's
  neutralisation to the telemetry its third bullet had preserved.
  `HistoricImportUsageEntry`, `HistoricImportCostLedger`, the
  `historic_import_usage_entries` table and the usage lines in
  `HistoricVideoPassStatusCommand` / `HistoricVideoPassPerformance` go together
  with the Phase 9 item 13 closeout deletion.
  **Done, 2026-09-02:** the model, the service, the table (via a new drop
  migration — the create migration is left in place per the repository's
  expand/contract convention, and the schema dump was regenerated), the
  `usageEntries()` relations on `HistoricImportOperation`/`HistoricImportCheckpoint`
  and both commands' usage-reporting output are all deleted (`HistoricVideoPassPerformance`
  report format bumped to version 3). The table held zero rows, so this loses no
  data. Phase 9 item 13's remaining scope — the inert `max_cost_minor_units` /
  `accepted_cost_minor_units` cap columns and the rest of the one-shot
  historic-import surface — is unchanged and still deferred to IC8 closeout,
  since nothing currently reads them for enforcement and no plan item asked for
  their removal now. All four quality gates pass: Pint, PHPStan (899 files, no
  errors), the full suite (7666 tests, 0 failures) and Dusk (55 tests).
  **Caveat, settled 2026-09-02:** the earlier worry was that P1-1 needed the
  provider's rate-limit headers and deleting this surface would take them. It
  would not have — `App\Support\OpenAiRateLimitDiagnostics` already recovers those
  headers from a 429's response and has never depended on the ledger, and
  `CreateResponse::meta()` carries them on successful calls. The ledger deletion
  is unblocked; P1-1 item 3 owns the diagnostic.

  **Note on what "recorded nothing" meant:** the DB ledger was empty, but
  `OpenAiUsageLogger` was writing `OpenAI chat completion usage` lines to the
  application log throughout the pass, model, effort, tier and token counts
  included. Those lines are the source of the input-token figures in the
  size-versus-failure table above. Log-level usage telemetry works; only the
  ledger is inert, and only the ledger is being deleted.

**All four items in this batch — P1-1, P1-2, P1-3 and P1-4 — are done as of
2026-09-02.** Pass 2 has no known blockers.

The reasoning that made them blockers still stands and is worth keeping: no
unattended overnight pass should run while a degraded completion can silently
bank empty analysis, because the failure mode is invisible in the pass summary
and compounds across every identity in the pass.

### Phase 8 — Process the remainder as a closed pass loop

**2026-09-06 review:** Phase 8 is underway. The database-backed findings and proposed
maintenance sequence below supersede earlier log-only assessments of output quality.
Speaker identification is deliberately paused while the corpus is assembled; it is
not a processing fault and must not be re-enabled as a backlog-clearing shortcut.

For each pass:

1. Preflight the mount, selected-item path/size metadata, operation-bound host disk evidence, actual and configured worker widths, models, provider project limit, manifest keys and the measured byte/time envelope. Do not content-read unselected future items and do not re-hash selected ones.
2. Metadata-check, copy and destination-size-verify only those immutable keys, enqueue them, record their processing IDs and let the dispatcher exit.
3. Read pass status from the database and monitor terminal outcomes, **degraded completions**, provider 429s **by their `error.code`** (`flex_unavailable` is a capacity signal about the tier, `rate_limit_exceeded` is one about this account — they need opposite responses and the client's exception message distinguishes neither), drive health and disk watermark. **Alarm on “nothing in flight while the pass is incomplete”, not on a failure count** — the 2026-09-02 wedge made a dead pass look identical to healthy queuing. (Provider request/token *cost* reporting was removed under P1-4, 2026-09-02, and is not monitored.)
4. Stop new dispatch immediately for mount instability, unexpected provider-call growth, unexplained duplicates, destination mismatch or recurring systemic failure. Gracefully stop workers only when already-running jobs themselves must be halted.
5. Reconcile services, sermons, sections, songs and review residue.
6. Verify completed assets in permanent private quarantine and their database/operation ownership.
7. Clean only verified temporary copies no active, queued or retryable run references.
8. Record peak working bytes, staging residue and remaining manifest membership.
9. Retain the pass performance JSON: clean/all-run elapsed p50/p95, per-stage active and queue-wait p50/p95, observed/configured concurrency, retry/missing-timing counts and throughput per wall-hour.
10. Re-census the cumulative graph for diagnostics; do not treat a partial-pass census as final convergence.
11. Start the next pass only after the prior pass has truthful terminal dispositions, bounded residue and a measured envelope supporting the next membership.

The pass report must name every non-zero residue. An empty queue is not evidence of successful completion. Pass closure is operational only: all evidence remains active in the same cumulative corpus for later cross-source convergence.

**Exit gate:** Every approved manifest item is completed, explicitly held/excluded, or named as unresolved; no pass retains unexplained staging data. **A run completed with `is_degraded_completion` does not count as completed for this gate** — it is a named unresolved item until re-analysed.

**Gate position after the 2026-09-07 closeout — operator call, not claimed here.** Every
clause now has evidence behind it: `is_degraded_completion = true` across the operation is
**0**; 441 of 464 identities are complete; and the remaining 23 are each named below with a
disposition (14 non-historic-blocked, 5 review-blocked, 3 supersession artefacts, 1
content verdict), as are the 4 unrecovered failures. The retained batch root is explained —
it is pinned by the 260 review obligations. On its own wording the gate therefore appears
satisfiable, but **passing it authorises Phase 9, so the call is the operator's**; this
records the evidence for making it, not the verdict. See *Post-pass closeout, 2026-09-07*
at the end of this phase.

#### Phase 8 review, 2026-09-06 — outputs and lessons for routine services

**Status: findings recorded; implementation and data repair pending.** The operator
requested this plan update, not a stop or a repair. No workers, configuration,
processing records or publication decisions were changed during the review.

The live database was queried through Sail after resolving sandbox access to the
Docker socket. The initial claim that Docker was stopped was incorrect. Counts
below are a snapshot beginning at **2026-09-06 18:28:57 UTC**, not a final census;
queries ran sequentially while processing could continue. Scope is
`historic_import_operation_id = 4` and `superseded_at IS NULL`, including operation
4's earlier calibration work, not only the 400-item dispatch. Do not mix this
denominator with the narrower restart log cohort.

| Database measure | Snapshot |
|---|---:|
| Completed runs without the degraded flag | 156 |
| Completed runs with the degraded flag | 1 |
| Processing runs | 249 |
| Failed runs | 9 |
| Sections marked `needs_manual_review` | 295 across 145 runs |
| Sections awaiting publication approval | 151 |
| Runs with a concatenated extraction plan | 156, including 59 completed |

Completion is a pipeline disposition, not proof that the content is correct or
ready for public release. Publication approval and processing review can overlap
and must remain separately reported. Seven of the nine failed runs are explicitly
at `manual_review_required`; two retain technical failures. The degraded run is
log **1038**, sermon **972**, dated **2025-12-14**.

The surviving logs since the 5 September restart independently showed 78 distinct
runs reaching projection (54 with the latest logged `needs_review = false`, 24
true), 231/237 initially unmatched song candidates resolved, and four distinct
runs encountering structural review stops. These are stage observations, not a
success rate or an independently verified accuracy score.

##### P8-Q1 — align sermon transcripts with the extracted media (first priority)

**Confirmed defect.** Sermon **1058**, 2024-10-06 morning, has this banked plan:

- Bible reading: **2005.65–2099.00 seconds**.
- Sermon: **2337.00–3999.99 seconds**.
- The **238-second gap** is omitted from the concatenated video/audio.

`CreateSermonTranscriptFromService::handle()` instead slices the full-service
transcript between `sermon_start_time` and `sermon_end_time`, the outer bounds
stored by `ExtractSermon`. The saved `quarantine/transcripts/sermon_1058.md`
therefore includes the intervening hymn and substantial garbled repeated lyrics.
This unwanted material is also supplied to subsequent sermon analysis.

- [ ] Write a failing regression test for non-adjacent reading/sermon spans, then
  make transcript selection consume the same ordered extraction spans as media.
  Cover single-span behaviour, gaps, cue overlap at boundaries and empty evidence;
  preserve the intentional inclusion of the preached Bible reading.
- [ ] Census all current concatenated runs using their stored plans. The **59
  completed** runs are an audit/repair candidate set, not 59 individually proven
  bad transcripts. Compare saved text with the text selected by the exact spans.
- [ ] Regenerate changed transcripts from retained service transcripts without
  re-transcribing audio or repeating video extraction. Re-analyse only affected
  derived metadata, preserving curated fields, established provenance and public
  URLs. Record unavailable evidence as unresolved rather than substituting blanks.
- [ ] Verify transcript/media agreement on #1058 and a representative sample of
  repaired single- and multi-span outputs before closing this item.

##### P8-Q2 — reconcile review state with the current policy

**Confirmed stored-state inconsistency; historical writer cause not yet proved.**
**142 song sections** have `needs_manual_review = true` with
`structure_oos_cross_type_inversion` as their **only** stored review flag.
`SectionReviewFlagPolicy` explicitly demotes that flag on every section type.
Examples **1331, 1333 and 1419** are on completed runs. These are candidates for
automatic policy reconciliation, not 142 required human content decisions.

Flags on the 295 reviewed sections were:

| Stored flag | Sections | Interpretation |
|---|---:|---|
| `structure_oos_cross_type_inversion` | 156 | Informational under current policy; 142 carry no other flag |
| `structure_low_confidence` | 90 | 76 songs, 6 readings, 5 children's talks, 3 sermons |
| `song_title_marker_mismatch` | 25 | 13 runs; independent evidence may settle identity |
| `unmatched_song_section` | 16 | 16 runs; investigate available matching evidence |
| `structure_oos_same_type_inversion` | 9 | Retain review where ordering is genuinely ambiguous |
| `childrens_talk_speaker_review` | 7 | All seven stored predictions are `ambiguous` |
| `structure_sermon_boundary_material_risk` | 6 | Directly affects extracted/published content |
| `structure_micro_section` | 4 | Assess downstream consequence, not duration alone |
| `structure_missing_preached_reading` | 4 | Check whether the reference is actually unresolved |
| `structure_sermon_interruption_merged` | 1 | Inspect the retained span and interruption |

Flags overlap; their counts must not be added to estimate human tasks. In
particular, the 156 cross-type occurrences are not all independent review causes.

- [ ] Reproduce the policy/state mismatch in a regression test and determine
  whether it comes from old worker code, unreconciled historical state or a writer
  bypassing policy. Current `MatchSongsFromTranscript` already calls the policy;
  do not assume the current source still contains the historical writer defect.
- [ ] Preview and apply narrowly scoped reconciliation using current policy,
  preserving other review reasons and separate publication restrictions. Verify
  a subsequent match/projection/publication-preparation pass does not reinstate
  demoted-only flags. Re-census affected services as well as section booleans.
- [ ] Separate song **identity confidence** from **boundary confidence** in the
  investigation. Example section 950 questions the inferred title; section 1258
  says two songs have no observable separating boundary. A catalogue match may
  settle the first question but cannot settle the second.
- [ ] Evaluate targeted slide OCR/lyric corroboration for title/marker conflicts.
  OCR fallback already exists; the gap is that a successful title match can skip
  independent evidence and a mismatch alone need not enter the unmatched path.
  Do not broadly lower confidence thresholds or let either title win by default.

##### P8-Q3 — recover truthful video assessments

**Confirmed assessment/evidence mismatch.** **83** sermons carry
`video_quality_status = unassessed`, `video_quality_reason = missing_video_file`.
Of these, **82** use `historic_quarantine`; every one of their corresponding
`/Volumes/Staging/HistoricWork/quarantine/sermons/{id}/video.mp4` files was checked
and is present and nonempty. The remaining one uses `historic_staging` and was
not included in that file check. Existence does not prove playback quality.

`AssessSermonVideoQuality` currently supplies the configured sermon disk rather
than selecting the sermon's own `asset_disk`. This is a concrete disk-resolution
concern; drive loss and promotion timing may also explain historical failures.

- [x] Test assessment of an asset on its owning disk, including promotion and
  temporary storage unavailability. Fix resolution/recovery as the reproduction
  warrants; do not turn an unavailable file into a passing assessment.
  **DONE 2026-09-08** — see *P8-Q3 closed* below.
- [x] Reassess the named existing files and investigate the staging exception.
  Regenerate thumbnails where a successful assessment makes that appropriate.
  Keep the existing five rejected videos (two frozen, three mostly black) subject
  to their own evidence; missing-file repair is not blanket quality approval.
  **DONE 2026-09-08.** The staging exception is gone from the data; no thumbnail
  needed regenerating, and no existing rejection was touched.
- [x] For routine processing, distinguish a recoverable evidence-access failure
  from an editorial decision and provide bounded targeted recovery.
  **DONE 2026-09-08** — two guards plus a `--reason` filter.

##### P8-Q4 — preserve the speaker corpus; identification remains paused

**Operator clarification, 2026-09-06:** speaker identification is deliberately
paused to assemble a corpus, try bucketing recordings into speakers, and then
probably name those speaker buckets manually. The earlier recommendation to
re-enable identification to clear the backlog is withdrawn.

The snapshot showed **140 completed runs** recording “Speaker identification
disabled”. Among completed runs linked to sermons, 151 retained default attribution
with review required and four had accepted speaker-model attribution. These are
not evidence that the currently paused model failed 140 times. Children's-talk
predictions separately comprise seven ambiguous, 33 skipped and 120 not populated;
many runs are still in progress, so this is not a final outcome distribution.

- [ ] Keep identification paused while assembling the corpus. Preserve usable
  speech audio and its sermon/section, service, source and extraction-span
  provenance through cleanup and private promotion. Verify retained permanent
  assets satisfy this need before deleting temporary copies; no blanket retention
  of every full-service temporary video is required.
- [ ] Design and evaluate speaker bucketing on isolated speaker speech, avoiding
  mixed reading/sermon voices and music. A service or concatenated reading-plus-
  sermon recording must not automatically be treated as one speaker sample.
- [ ] Present representative samples and outliers for manual bucket naming;
  retain uncertain/outlier assignments rather than manufacturing identities.
  Evaluate the rebuilt approach before using it for routine automatic attribution.
- [ ] Treat “Visiting Speaker” with default provenance as unresolved attribution,
  not a verified identity or a clustering label. Preserve confirmed attributions.

This rebuild is **not a prerequisite for resuming Phase 8 corpus collection**.
Speaker settlement remains part of the later release QA.

##### P8-Q5 — finish analysis and make remaining human checks informative

- [x] Re-analyse sermon **972** from retained valid evidence. Completed on
  2026-09-07 with substantive title, reference, summary and points, as verified in
  the closeout and later sample; the earlier degraded output is superseded.
- [ ] Record original flags and before/after review outcomes when a human changes
  or confirms identity, title, type or boundaries. `ConfirmServiceSection`
  currently removes the flags and records reviewer/time, making it difficult to
  measure which review reasons actually caught an error. Reuse the speaker-review
  candidate-rank pattern where appropriate; avoid a parallel audit framework.
- [ ] Make material-boundary review quick with clips around the disputed start,
  end or join. Retain review for genuine multiple-sermon, merged-song and boundary
  ambiguity. Five log examples merged multiple following items into a sermon;
  the database's six material-risk sections are a different snapshot/measure.
- [ ] Verify the already implemented corrective structure retry, projection
  reason counts, children's-speaker outcome logging and separation of processing
  review/publication approval in running workers. The reviewed newer projection
  logs still lacked reason counts; source code presence is not deployment proof.

Content QA so far is encouraging but limited: transcripts for **1053** (Daniel
11:36–45), **1058** (Luke 14:1–6) and **1059** (Daniel 9:20–27) were read and their
saved titles, references, summaries and points checked for consistency. The
metadata fits the sermons. All three remain quarantined with unresolved speaker
placeholders, and #1058 has the transcript defect above. No complete audiovisual
listening/viewing audit or corpus-wide accuracy claim was made.

##### Recommended maintenance sequence — not yet executed

**Recommend a short controlled pause for P8-Q1 before further transcript analysis
multiplies avoidable repair and model calls.** Take P8-Q2/P8-Q3 fixes in the same
maintenance window if their reproductions remain bounded. Do not wait for the
speaker rebuild, broad song adjudication or every historical repair to finish.

1. Stop any still-active dispatcher and gracefully drain/pause the relevant
   workers using the pass-control runbook. Stopping dispatch alone does not stop
   the already queued corpus. Do not kill active FFmpeg jobs or clear queues.
   Independent upstream work can continue only if queue routing is verified to
   isolate it from the affected stages; otherwise use a brief coordinated drain.
2. Implement and test the shared pipeline fixes, prioritising P8-Q1. Use red/green
   regression tests, PHPStan, Pint and the full parallel suite for these non-trivial
   changes; run Dusk if a later review-UI change affects browser behaviour.
3. Deploy/reload the workers and verify they actually run the new code. Validate
   a bounded affected example against saved spans and source text before allowing
   new downstream analyses. Resume existing queued work without re-importing
   completed media or changing publication authority.
4. Run scoped, resumable repairs of saved transcripts/analysis, review state and
   video assessments; report corrected, unchanged and unresolved counts. Retain
   the repair membership so the 59/142/83 snapshot counts are not mistaken for
   current targets as the corpus grows.
5. Continue corpus collection with speaker identification paused. Complete the
   remaining quality work before Phase 9 public-release acceptance. Quarantine
   containment makes delayed repair possible; it does not make defective output
   correct. No pause or release authorisation is implied by this recommendation.

#### Phase 8 review, 2026-09-07 — larger cohort and next improvements

**Status: investigation and recommendations only.** No processing records, worker
configuration, queues, media or publication decisions were changed. Existing
uncommitted implementation work was preserved. Evidence comes from read-only Sail
MySQL queries, the existing transcript-repair command in its default dry-run mode,
local logs, source inspection and sampled saved outputs. No paid model calls were
made. This supplements P8-Q1–Q5 above and supersedes their implementation-status
claims where explicitly stated below.

##### Comparable progress and limits of the census

The first query was at **2026-09-07 06:48:26 UTC**. Scope remains operation **4**,
`historic-c24f1acfc3b4f9986882be35c917b73f`, with `superseded_at IS NULL`.
Queries were sequential while workers continued. These are overlapping pipeline
snapshots, not one transaction or the final 400-item dispatch result.

| Measure | 6 September review | 7 September initial snapshot |
|---|---:|---:|
| Completed runs, including degraded | 157 | 221 |
| Processing | 249 | 183 |
| Failed | 9 | 10 |
| Sections needing manual review | 295 / 145 runs | 250 / 128 runs |
| Sections pending publication approval | 151 | 265 |
| Sole cross-type-inversion review flag | 142 | 94 |

The total live-run denominator changed from 415 to 414; do not infer that all
changes are successful completions or calculate an accuracy rate from these
totals. Later in this review, completion reached **223** (222 ordinary and one
degraded), with 181 processing and 10 failed. The degraded completed run remains
**#1038 / sermon #972**. Another degraded run, **#1168 / sermon #1087**, is still
processing with a filename-like title. Both need actual analysis recovery, not
just a cleared flag.

At a subsequent stage snapshot, **180 runs were at `ai_analysis_completed`**, one
at `ai_analysis_fallback`, and one preparing section-publication candidates.
Recent logs show active song extraction, audio enhancement, publication
preparation, promotion and cleanup. This points to the downstream media tail as
the current drain bottleneck; it does not establish queue wait times or prove
that any individual run is stuck.

- [ ] For routine monitoring, distinguish queued/waiting from actively executing
  at each stage and report oldest wait, completed work and pending approval
  separately. Measure the section-extraction/enhancement tail before increasing
  concurrency; more transcript/model workers will not clear that tail.

##### P8-Q1 follow-up — historical pre-repair snapshot

**Later status:** the closeout records all 60 repaired and reanalysed; the
resumable analysis-freshness defect below remains open under P8-Q12.

Commits `ce58f84de` and `0c4ed22d6` now select transcript cues from the recorded
media spans and provide `historic-import:repair-sermon-transcript-spans`.
The operation-scoped preview during this review inspected **222 completed runs**:
**60 repairable, 41 already matching, 121 unaffected**, with no unresolved rows
reported. “Already repaired” is the command's text-equality disposition, not
proof that the repair command was run: the database had **zero** operation-4
`transcript_span_repair` records. Newly produced matching transcripts therefore
provide useful evidence of the new behaviour. Sermon #1193 is one such example.

**New source-confirmed workflow defect:** the command advises that after
`--execute`, rerunning with `--execute --reanalyse` will refresh the analysis.
However, `handle()` passes only currently `repairable` entries to
`dispatchReanalysis()`. After a successful text-only repair those rows become
`already repaired`, so that later invocation skips them. A crash after writing
text but before dispatching analysis has the same recovery gap. This is a code
finding, not a reproduced live mutation; the review did not execute a repair.

- [ ] Add a failing regression for text-only repair followed by reanalysis, then
  track analysis owed/completed independently of whether text still differs.
  Prefer binding analysis to a transcript content hash/version, so retries can
  establish freshness without charging for unchanged input.
- [ ] Repair the current exact membership and refresh affected derived analysis;
  confirm successful job completion, not merely dispatch. The historical count
  of 59 is now stale. Preserve curated fields and asset ownership.
- [ ] Update the maintenance advice above: the shared transcript fix no longer
  needs implementation. Verify its running-worker coverage and close the repair
  backlog; do not pause the corpus merely to implement code already present.

##### P8-Q6 — frozen-video rejection has a demonstrated false positive

Across linked sermons in the live scope, assessments currently comprise **210
approved, 19 frozen-frame rejections, four mostly-black rejections, 83 missing-file
assessments and 86 unassessed without a reason**. This includes unfinished runs
and existing linked sermons; it is not a completed-output quality rate.

**Sermon #1167 is not globally frozen.** Its saved assessment records
`frozen_pair_ratio = 1`, eight frozen pairs and both window ratios equal to one.
Read-only frame extraction from its quarantine video shows different postures at
500 and 1100 seconds. More decisively, a five-frame sequence at approximately
586–592 seconds, around the first configured one-third-duration burst window
(video duration **1767.247528 seconds**), shows clear arm and head movements.
This is direct visual counterevidence to the blanket frozen verdict, though not
a full playback audit or proof against intermittent freezes elsewhere.

`SermonVideoQualityAssessmentService::frameFingerprint()` reduces the **whole
image to 16×16 luminance** and averages pixel differences; the rejection threshold
is 0.01. A small moving preacher against a large unchanging wall can therefore
look numerically frozen. This is a concrete calibration concern for routine
fixed-camera services, not simply an archive-specific fault.

- [ ] Build a regression fixture from this moving fixed-camera case plus genuine
  frozen and black controls. Evaluate higher-resolution/local-region motion and
  corroboration across time before allowing automatic frozen rejection.
- [ ] Reassess the 19 named-class candidates individually under a validated rule;
  do not bulk approve them or weaken the independent black-frame gate. Distinguish
  intentionally static slides, low motion and actual capture failure.
- [x] P8-Q3 still applies: all **83** missing-file assessments are now completed
  sermons on `historic_quarantine`; the job still supplies the configured disk.
  This review did not repeat yesterday's exhaustive file-existence check. Recover
  owning-disk access and reassess; the unchanged count is not proof of resolution.
  **DONE 2026-09-08** — the job now resolves `asset_disk`, and all 81 remaining
  rows were reassessed. Thirteen of them landed as rejections and are now part of
  P8-Q6's calibration set, which grew from 26+8 to **29 frozen and 21 mostly
  black**. See *P8-Q3 closed 2026-09-08*.

##### P8-Q7 — probable duplicate and conflicting service identity

**Runs #1248/#1249, sermons #1167/#1168**, both have the title *Love that was
promised*, reference Isaiah 9:1–7 and exactly **3969.219002 seconds** of source
duration. Their filenames are respectively:

- `Morning service - Sunday 10th December 2022-164.mp4`
- `Morning service - Sunday 10th December 2022 [YouTube backup].mp4`

The first is assigned **2022-12-11 morning**, the second **2022-12-10 morning**.
10 December 2022 was a Saturday. Their saved transcript openings contain the same
reading and page number, with a short introduction retained in one. These facts
strongly suggest a backup of the same occasion with conflicting date resolution;
they are not sufficient to silently merge or choose an authoritative source.

- [ ] Resolve against the approved manifest/source provenance and service evidence
  before Phase 9. If confirmed, retain one canonical occasion/output and record
  the other source's supersession; preserve any better media or metadata.
- [ ] For routine uploads, flag weekday/date contradictions and likely backup
  duplicates before expensive work. Existing filenames, duration and transcript
  similarity can nominate candidates; none alone should authorise merging. Do
  not reintroduce the bulk full-file hashing removed by M11.

##### P8-Q8 — reject unusable speech evidence before expensive extraction

The ten failures include eight `manual_review_required` and two technical stops.
Logs identify the latter more usefully than their generic UI error:

- **#1004:** stored full-service transcript contains no cues.
- **#1280 / sermon #1197:** extracted bounds contain no sermon text, failed after
  three attempts on 6 September at 19:22:34 UTC.

#1280's fallback plan extracted **1405.5–2854.74 seconds** using the dominant RMS
speech segment because there was no high-confidence sermon section. That entire
span lies inside a retained **1011.7–2990.4** `retranscription_failed` window.
The projection has a 33-minute supposed reading, with notes explicitly saying
its content and endpoint are unobservable. Logs show video storage and audio
enhancement already happened before the empty-text failure.

- [ ] Add a preflight using recorded span/cue coverage and known unobservable
  windows before cutting/enhancing a sermon. Route absent evidence to targeted
  transcript/source recovery with the interval and reason visible to the operator.
  Do not declare sermon absence from failed transcription or invent the passage.
- [ ] Avoid three identical retries against unchanged empty evidence. Retry the
  stage able to recover that evidence, within bounded policy; retain a human
  decision where the source itself remains unreadable.
- [ ] Validate the pending corrective structure retry on the eight structural
  holds. In particular, #1299/#1377 have incompatible OoS claims, #1253/#1338
  containment conflicts, and #1268 containment plus two sermons. The uncommitted
  retry changes already address several of these failure classes; their presence
  in this checkout is not evidence that those held runs have been recovered.

##### P8-Q9 — target the largest real review workload

The structure flags still include **94 demoted-only cross-type cases**: finish
P8-Q2 reconciliation rather than ask a person to adjudicate them. Conversely,
the rising publication-approval count is a different workload. A later census of
pending song-review reasons found the following overlapping occurrences:

| Publication reason | Occurrences |
|---|---:|
| Spoken framing exceeds limit | 85 |
| Spoken framing | 84 |
| Trailing content | 45 |
| Short song clip | 23 |
| Uncorroborated partial recording | 4 |
| Adjacent same song | 2 |

**72 pending sections have spoken framing as their only recorded publication
reason.** This is the strongest candidate group for evaluating future boundary
automation, not 72 clips proved safe to publish. RMS-active, wordless transcript
gaps do not themselves prove singing: instrumental introductions, missed speech
and transcription failure can also produce them. Section **3478**, for example,
has an 80-second offset to its first such gap and is correctly held under the
current policy. Boundary metadata explicitly retains the inclusive candidate
under `no_recut_before_bulk`; automation would be a new evaluated policy.

- [ ] Sample and label the 72 simple cases first. Evaluate a proposed recut only
  where independent evidence supports sung onset/end and preserves the whole
  song; keep long introductions, mixed items and ambiguous edges in review.
- [ ] Surface the already recorded candidate time, gap, reason and short playback
  excerpt to reduce review time even before automatic recutting is trustworthy.
  Measure false trims and reviewer corrections, not just fewer flags.
- [ ] Reconcile passage evidence across stages: section **2240 / run #1159** still
  has a null structure reference and sole missing-reading flag, while later sermon
  analysis supplies **Psalms 51:1–12**. Offer this as a corroboration candidate,
  not automatic truth. Sections **1300 and 1513** already have references but
  retain independent material-boundary/interruption flags; do not clear those.

##### Sampled content judgement and recommended order

The saved text and metadata examined for **#1193** (*Worshipping God when the
going gets tough*) and **#1192** (*Come and see Jesus*) support their titles,
references, summaries and points. #1193 reads Job 1 in full but explicitly says
the sermon focuses on **1:13–22**: the narrower sermon reference is correct, not a
reading/reference mismatch to “fix”. #1192's four recorded points follow the
speaker's record/reason/revelation/result outline. Lyric quotations inside #1193's
sermon are intentional illustrations; keyword-based song removal would damage it.

There are residual transcription inaccuracies requiring caution: #1193 includes
“Joe” for Job and “father of delights”; #1192 renders “Croconell” and cites “John
19, verse 39” beside “it is finished”. These are text-level anomalies, not verified
audio corrections. Check speech before changing quoted words or deciding whether
the speaker misspoke. None undermines the sampled saved summaries, but none
should be silently normalised into purported verbatim speech.

Overall, the sampled semantic outputs are encouraging; full audiovisual accuracy
is not established. The identified repair backlog, identity conflict and false
video rejection mean completion cannot serve as release approval. Four linked
sermons are already `published` (#868, #844, #845, #850 on runs #1379–#1382), while
398 are quarantined. Those four are existing linked records requiring ownership/
provenance checks, not evidence by themselves of four new publication leaks.

Recommended next work: **(1)** finish transcript/analysis freshness recovery and
the repair-resumption defect; **(2)** prevent extraction over absent speech
evidence; **(3)** calibrate frozen detection and recover missing-file assessments;
**(4)** resolve the duplicate/date candidate before release; **(5)** reconcile
demoted state and evaluate the simple song-framing cohort. Keep speaker
identification paused as already decided. This review authorises none of the
proposed repairs or publication changes; it records the evidence for choosing them.

#### Post-pass closeout, 2026-09-07

The bounded pass finished at **2026-09-07 10:49Z** with 405 completed, 11 failed, 2
superseded and every queue empty. This records the work that closed it out, folded in from
the retired `PHASE8-POST-PASS-REANALYSIS-HANDOVER-2026-09-07.md`.

**Two degraded completions and 60 mis-sliced transcripts, and the order mattered.** The
handover set the degraded re-analysis before the transcript repair. That order was wrong:
sermon **972** was itself in the repair set with **18.4%** of its transcript contaminated,
so re-analysing first would have paid for analysis of a transcript that still held a hymn
*and* cleared `is_degraded_completion` on the strength of it. The repair was run first; its
`--reanalyse` re-dispatches the same `ProcessTranscriptWithAI` whose success path clears the
flag, so 972 resolved as a side effect.

`historic-import:repair-sermon-transcript-spans` found **60** repairable (the handover's
`updated_at` proxy guessed ~59). All 60 were repaired and re-analysed; the census now reports
`already repaired 169 / unaffected 235 / repairable 0` with no `unresolved` entries. The two
two "known individual defects" the handover named separately turned out to be two of the 11
failures, not separate items.

Sermons **972**, **1087** and **1005** all carry real analysis, verified by reading content
rather than the flag: *The day that changed Mary's life* (Luke 1:26-56), *Christ-centred
relationships in the home* (Colossians 3:18-4:1), *True greatness through humble service*
(Luke 22:24-27). `is_degraded_completion = true` across the operation is now **0**.

**The two flags must go in one invocation.** `dispatchReanalysis()` is fed the repairable set
computed *before* the write. Running `--execute` alone first makes every run "already
repaired", so a later `--execute --reanalyse` finds nothing to dispatch and silently leaves
the sermons analysed from contaminated text.

##### Operating notes carried forward from the retired handover

- **Workers hold the code *and* the `.env` they booted with.** After any change to a pipeline
  class, restart them or nothing takes effect; `queue:restart` is a request, not a guarantee.
  Check `docker exec <c> ps -o etimes= -p 1` against the commit time. This is not theoretical:
  `579bc4529` sat unused for two hours on 2026-09-07 because the workers predated it.
- **Restart gracefully or pay for it.** `docker stop -t 600 <workers> && docker start
  <workers>`. Docker's default 10 s grace against a multi-minute ffmpeg job SIGKILLs it, and
  `REDIS_QUEUE_RETRY_AFTER=7260` then hides that job for 2 h 1 m. With every queue empty the
  stop completes in under a second.
- **Reading artifacts requires the staging context.** `historic_staging` roots at the batch
  directory only while a context is active. Outside one every artifact reports
  `exists = false`, which looks exactly like total data loss:

  ```php
  $plan = app(HistoricVideoCurationManifest::class)->plan('/mnt/cbc-services', 'storage/app/private/historic-video-curation-manifest-20260901.json');
  $ctx  = app(HistoricStagingGuard::class)->contextForApprovedPlan($plan->manifestHash, $plan->planHash);
  app(HistoricStagingContextRegistry::class)->within($ctx, fn () => /* read here */);
  ```

  Note `HistoricVideoCurationManifest` lives in `App\Services\Media\Video`, not
  `App\Services\HistoricMedia`.
- **Do not use `--force` on any historic dispatch.** It bypasses the existence and in-flight
  checks that keep the lane safe.
- **Do not trust a `--dry-run` of `sermons:import-historic-videos` to predict a resume.**
  `ImportHistoricVideoBatchCommand.php:185` skips the staging context when `--dry-run`, so the
  importer computes no job keys and reports resumable work as fresh dispatch.
- **Do not size remaining work from a corpus-wide mean.** Extraction cost is governed by
  `VIDEO_EXTRACTION_REENCODE_ABOVE_MBPS=6.0`: pre-2024 sources re-encode, 2024+ stream-copy at
  ~4-17x less cost. Both knobs (threshold, and the `veryfast` preset) were assessed and
  declined on 2026-09-05.
- **A staging-volume drop needs no manual recovery.** `HistoricStagingReachability` is
  re-probed on every `Queue::looping`, so workers pause before fetching and release themselves
  when the volume returns.

##### Two process hazards, both found the hard way

**Never run `artisan dusk` while the local queue holds real work.**
`DuskTestCase::setUp()` calls `Cache::store('redis')->flush()`, which Laravel implements as
**`FLUSHDB`** — it ignores `CACHE_PREFIX` and wipes the whole database, **`queues:*` lists
and the reserved/delayed sets included**. `.env.dusk.local` points at the same `redis:6379`
DB 0 as `.env`, so 55 Dusk tests issue 55 flushes. On 2026-09-07 this destroyed 30 of the 60
re-analysis jobs mid-drain. Nothing was corrupted and the repairs were durable, but the
queued work vanished and had to be re-dispatched. `artisan test --parallel` is safe;
`phpunit.xml` sets `CACHE_DRIVER=array`. Only Dusk touches Redis.

**A flushed queue is indistinguishable from a finished one.** `pending=0, reserved=0,
delayed=0` with `failed_jobs` unchanged is exactly what success looks like. Never size
completion from queue depth — count the effect (rows whose `updated_at` moved past dispatch,
or `AI analysis completed` log lines per processing ID) and diff that against the dispatched
set.

**A degraded completion is not always a provider refusal.**
`ProcessTranscriptWithAI::loadTranscriptFromStorage()` supplies
`sermon->asset_disk` to a resolver that also searches generic candidates, while the span
repair explicitly searches the run-context staging and quarantine locations. Sermon **1005**'s promotion moved its audio and video to quarantine
and set `asset_disk` accordingly but left `transcripts/sermon_1005.md` on staging; the
analysis job read nothing, threw "Transcript file is empty or unreadable", and banked a
fallback. A storage-location bug wearing a provider refusal's clothing, and one that fails
identically on every unchanged retry. The empty-input check precedes the analysis API call. Fixed by copying the repaired
transcript to quarantine (sha256 verified) and re-dispatching. **An audit of all 402
completed runs holding a transcript found exactly one such mismatch** — isolated residue, not
a systemic promotion defect. The census's `Disk` column is the tell: 168 rows read
`historic_quarantine`, one read `historic_staging`.

##### The failure backlog: 7 of 9 recovered

**All 11 failed runs still held their source on disk**, so none of the backlog was
unrecoverable.

`579bc4529` widened `detectionWorthRetrying()` from `timestamps_outside_recording` alone to
also cover `non_chronological`, `multiple_sermons` and `incompatible_oos_item`, feeding the
failure back as corrective guidance. **The workers had booted before it landed and were not
running it.** They were restarted gracefully (all queues empty; the stop took 0.7 s) and nine
runs retried:

| run | identity | outcome |
|---|---|---|
| `05bb6682` | 2021-01-31-am | completed — *Can God bring good out of evil?* (Genesis 37:12-36) |
| `99c83934` | 2021-11-14-am | completed — *Responding to wrongs without revenge* (Matthew 5:38-42) |
| `6776c3b8` | 2022-04-10-am | completed — *Why Jesus died* (Isaiah 52:13-53:12) |
| `85f373a7` | 2022-07-31-am | completed — *In the beginning, God* (Genesis 1:1-31) |
| `649ed5e4` | 2022-11-13-am | completed — *Build your life on the rock* (Matthew 7:24-27) |
| `449a440f` | 2024-01-21-am | completed — *Persevering in faith* (Colossians 1:21-23) |
| `5c8335e0` | 2026-06-28-am | completed — *Remembering God's mighty acts at Gilgal* (Joshua 4:1-5:1) |
| `8feca773` | 2024-08-11-am | failed — operator decision, see below |
| `9c078009` | 2026-04-26-pm | failed — content verdict, see below |

None is degraded; every one carries a reference, a 440-529 character summary and 3-5 points.

**`579bc4529` was proven on live data.** `85f373a7` failed validation with `multiple_sermons`,
logged *"Service structure output failed recoverable validation; retrying detection once"*,
and the corrective attempt passed into structure projection. Under the old code that run went
straight to a reviewer.

**`449a440f` was never a data fault.** It failed at 07:22Z on 2026-09-05, seven minutes after
the mains outage, with "The recorded full-service transcript is unavailable". That transcript
is present and **100% covered (1,059 cues)**. Collateral damage from the outage.

**Classify failures by code, never by message text.** `8feca773`'s "Multiple speech blocks met
the 20-minute sermon threshold" reads almost identically to the `multiple_sermons` validation
code, but it is raised by *sermon extraction* (`reason: multiple_qualifying_speech_blocks`,
5 qualifying blocks), which `detectionWorthRetrying()` never sees. It was retried and failed
again; it is an operator decision, not a retryable class.

##### Forcing a re-transcription on retry

`6776c3b8` needed its transcript withheld before retry.
`ProcessingArtifactReuse::serviceTranscriptIsUsable()` tests only that `cues` is non-empty, so
its **34%-coverage** transcript reported `usable = true` and a plain retry would have reused
it and failed a fourth time. The file was **renamed, not deleted**, to
`…normalized.json.truncated-34pct-2026-09-07` — the drive holds the only copy and the file is
evidence.

Archiving the file is necessary but **not sufficient**.
`ProcessingPhaseRegistry::retryPlanFor()` builds the plan from the run's `current_step`: a run
that failed at `detect_service_structure` resumes *at* detection, and `transcribe_full_service`
is an earlier offset treated as already successful — the phase did succeed, it merely wrote a
bad file. Archiving alone just turns "empty transcript" into "missing transcript" at the same
resumed step. To genuinely re-transcribe:

```php
$log->forceFill(['current_step' => 'transcribe_full_service'])->save();
app(ProcessingRunOrchestrator::class)->retry($log);   // job_offset 1, safe_to_rerun
```

`6776c3b8` returning *"Why Jesus died"* on Isaiah 53 for Palm Sunday 2022 is good evidence its
forced re-transcription produced sound text.

##### `2026-04-26-evening`: transcription recovery rejected both attempts

The handover called run `9c078009` "a storage defect, unresolved", on the grounds that Whisper
returned 157 then 144 cues while the stored transcript held none. **That diagnosis is
incorrect.** The re-run reproduced the artifact byte-for-byte:

```json
{"cues":[],"duration":4317.64,"source":"local_whisper",
 "unobservable_windows":[{"start":0,"end":4317.64,"reason":"retranscription_failed"}]}
```

One unobservable window spanning **the entire 72-minute recording**. This is
`ServiceTranscriptRecovery::recover()` behaving exactly as written
(`app/Services/Media/Audio/ServiceTranscriptRecovery.php:48`): the pathology detector judged
the transcription pathological, the targeted no-priming re-transcription was judged
pathological *as well*, and the branch commented *"We did look, and the audio yields nothing
usable. The original cues are known-bad, so drop them"* removed them deliberately. The 157 and
144 cue counts are two attempts that both failed the pathology check, not data lost on write.

Retried twice on 2026-09-07 including a forced re-transcription; failed identically both
times. **More unchanged blind retries are not justified.** This establishes failure under
the current transcription/pathology configuration, not that the source audio is unusable.

**Answered later the same day, and it was the configuration, not the audio or the
model.** The "bounded alternative transcription strategy" this paragraph asked for
turned out not to be needed: the retry was already reading the audio correctly and
`recover()` was discarding the result whole because one region of it looped. See
*The blind windows were never blind* under P8-Q8. This record is kept because the
reasoning — that a reproducible identical failure indicts the configuration rather
than the source — was right, and only the remedy it guessed at was wrong.

**How to distinguish the recorded failure:** read `unobservable_windows`.
`retranscription_failed` means an empty or still-pathological recovery transcript; it is
evidence of an ASR/recovery problem, not an independent listening verdict. Missing or
unrelated metadata still requires investigation of storage and upstream processing.

##### A completed historic run can be superseded by a *failed* non-historic one

**The most consequential finding of the closeout.** Three of the four identities the census
reports as freely dispatchable are not unfinished work at all:

| identity | historic run | status | superseded by | that run |
|---|---|---|---|---|
| 2024-01-21-morning | `449a440f` | completed | id **893** | failed, non-historic |
| 2026-06-28-morning | `5c8335e0` | completed | id **909** | failed, non-historic |
| 2026-06-14-morning | `7f14b5b2` | completed | id **890** | failed, non-historic |

`ProcessingRunSupersessionService::reconcile()` picks one authoritative run per church
service, ranked by transcript-confirmed song-section count, then high-confidence section count, **then**
completed status. Its docblock is explicit that this is deliberate: completed status "can
never veto a failed re-run that carries genuinely better structure" (OD-2). A failed
non-historic run holding better song evidence legitimately wins.

**The problem is the composition, not either rule.**
`HistoricVideoImporter::checkExistence()` filters with `notSuperseded()`, so a superseded run
does not count as imported. Together: a historic import completes, loses the ranking to an
older failed run, and the identity reads as never imported — **permanently**. The manifest
offers it on every future pass, each dispatch completes, is superseded again, and reopens.
`5c8335e0` was superseded at 13:01:57 on 2026-09-07, minutes after completing, by run **909**
— the run deliberately closed as failed on 2026-09-04.

The content is *not* lost: sermons 857 and 1108 exist with real analysis. Only the importer's
view of the identity is wrong. This also explains `2026-06-14-morning`'s supersession on
2026-09-06 — not an operator retirement, the ranking did it.

- [ ] Separate completed consumption of an approved source identity from the choice of
  authoritative service structure. A failed run can retain better structure under OD-2
  without reopening an already completed import. Preserve explicit source replacement and
  retirement as ways to reopen work; do not solve this solely by banning failed winners.
  **Implementation remains outstanding.**
- [ ] Until then, run the census before any bulk dispatch **and check `superseded_by` on
  everything it offers**. Reprocessing these three costs hours and provider spend and changes
  nothing.

##### The manifest re-censused

Reproduced by evaluating `HistoricVideoImporter::checkExistence()`'s three tests across every
`include` entry:

| resolution | before the retries | after | what it needs |
|---|---|---|---|
| `skip-exists` — historic lane complete | 436 | **441** | nothing |
| `skip-pending-review` | 10 | **5** | resolve the run holding the date shut |
| `skip-exists` — **non-historic** run | 14 | **14** | operator content decision |
| would dispatch | 4 | **4** | 3 are supersession artefacts |

**Only ONE identity in that census is genuinely un-imported**: `2026-04-26-evening`,
which needs evidence recovery rather than another blind dispatch. The handover's "64 unstarted manifest
items" was never 64 outstanding: 48 of them were already complete in the historic lane.

##### The flagged-section queue, by actual reason

**`review_flags` is not a column** — it lives inside `service_sections.metadata`. Querying the
model attribute returns nothing and makes the queue look reasonless; read
`$section->metadata['review_flags']`.

260 sections carry `needs_manual_review`, every one with at least one flag:

| review flag | count |
|---|---|
| `structure_oos_cross_type_inversion` | **117** |
| `structure_low_confidence` | **97** |
| `song_title_marker_mismatch` | 29 |
| `unmatched_song_section` | 16 |
| `structure_oos_same_type_inversion` | 8 |
| `childrens_talk_speaker_review` | 7 |
| `structure_sermon_boundary_material_risk` | 6 |
| `structure_micro_section` | 4 |
| `structure_missing_preached_reading` | 3 |
| `structure_oos_type_unresolved` | 2 |
| `structure_sermon_interruption_merged` | 1 |

**Correction:** the earlier “83%” added overlapping flag occurrences and was not a
unique-section rate. Its type breakdown totalled 256 rather than 260, and its purported
208-song match breakdown also totalled 256. Do not use those figures to size manual work.
The later scoped census below replaces them. Cross-type inversion is already informational
under current policy; it must not be bundled with genuine same-type ordering ambiguity.
Catalogue confirmation alone cannot clear marker, boundary or multiple-song concerns.
Reconciliation must preserve those obligations and the corresponding retained sources.

##### Latent defects found, not fixed

- **`ProcessingArtifactReuse::serviceTranscriptIsUsable()` accepts truncated transcripts**
  (`app/Services/Processing/ProcessingArtifactReuse.php:82`). Only 1 of 416 runs sat below 85%
  endpoint reach in that sample. Endpoint reach is not speech coverage: it misses interior
  holes and penalises valid music/silence endings. The later evaluation finds material holes
  in completed outputs. Validate evidence in and around selected sermon spans, including
  unobservable windows, cue validity and source provenance; do not use endpoint ratio alone.
- **Transcript ownership/context remains important:** `ProcessTranscriptWithAI` supplies
  `asset_disk` to `TranscriptStorageService`, which searches that disk plus generic
  candidates. Those candidates do not reliably recover a per-run staging location. Record
  explicit artifact ownership/context; surface missing evidence as recoverable storage work.
- **A `Cache::flush()` against the Redis store wipes the queue.** Nothing guards against
  running Dusk while real work is queued.

##### Open operator decisions

- [ ] **The 14 non-historic-blocked identities** — 2023-05-07-am, 2023-09-03-am,
  2023-11-05-am, 2024-03-10-am, 2024-09-29-am, 2024-11-03-am, 2025-04-13-am, 2025-11-02-am,
  2026-03-01-am, 2026-04-26-am, 2026-05-03-pm, 2026-05-03-am, 2026-06-07-am, 2026-07-05-am.
  All carry `preacher = "Visiting Speaker"`, 11 with placeholder titles. Their identities are
  held by *completed routine livestream* runs, so reprocessing means changing 14
  **already-published** services. Supersede and re-import, repair preacher/titles in place, or
  accept as-is. No command covers it: `--force` is rejected for a definitive manifest run and
  `historic-import:retire-run` refuses non-historic runs, so `superseded_at` is the only lever.
- [~] **Three sermon-block decisions — now one.** Reduced 2026-09-08 by asking each run
  whether its *evidence* had changed, rather than treating all three as the same class.
  - `56231ed5` (2025-12-21-pm, run #1035) — **CLOSED.** Not an operator decision at all:
    it had projected one prayer from a **22-word** transcript, and the replay put 5,877
    words back. Re-detection returned a full carol service, sermon **#1307** at confidence
    0.99. The 20-minute threshold never ran, because
    `ExtractSermon::guardAutoExtractionPolicy()` consults the RMS confidence service only
    when the plan fell back to `processing_log`; a confident sermon section bypasses it.
    The original failure was a void-damaged structure, not a short sermon.
  - `b46bffc0` (2024-08-08-am, run #1145) — the recording is **1,014 s** long with **zero**
    blind windows, so no 20-minute block can exist. Needs a *disposition*
    (`historic-import:exclude-run`), not a viewing.
  - `8feca773` (2024-08-11-am, run #1143) — **the one real decision.** Five qualifying
    blocks, and the replay moved it 5,980 → 5,980 words, so no new evidence is coming.
    A human must say which block is the sermon.
- [ ] **Two review-blocked runs outside the historic lane** — `675d14f3` (2023-02-26-am,
  chronology overlap) and `10e4f30d` (2024-05-05-am, OoS ordering). Both hold a
  `skip-pending-review` identity and both sit in classes `579bc4529` now retries, but they
  carry `historic_import_operation_id = NULL`, so no `historic-import:*` command reaches them.
- [~] **`2026-04-26-evening`** (run #1004) — the investigation is done and the answer is
  negative: the replay found **0 words before and 0 after** across 4,317 blind seconds, so
  both banked retries hold nothing. Its source is still present, so the remaining option is
  a fresh transcription; there is no recovery left to attempt from what the run holds.

#### Post-run evaluation, 2026-09-07 — accuracy and weekly automation

**Current findings and priorities.** This review changes this plan only. Database
reads ran at 14:32–14:41 UTC through Sail; media reads were against the mounted
private drive. No jobs, paid model calls, repairs, configuration or publication
changes were made. Existing uncommitted work was preserved.

##### Scope, sampling and what the results establish

The census is operation **4**, `superseded_at IS NULL`: **413 runs**, comprising
**409 completed, four failed, zero degraded and zero in progress**. There are
**406 linked sermons**, all currently quarantined. Three completed runs have no
sermon: #978, #1144 and #1344. Report those separately rather than counting them
as successful sermon extractions. This scope excludes superseded historic runs
and routine runs that block manifest identities; the earlier 441-identity census
answers a different question.

All **406 saved sermon transcripts and 406 normalised service transcripts** were
found. The machine census compared recorded unobservable windows with extraction
spans; existence and nonempty text were not treated as semantic verification.

Content sampling comprised:

- **37 sermons:** four evenly spaced positions in each year's date-sorted cohort
  for 2020–2026 (28), plus nine distinct earlier findings/repair examples. Saved
  title, reference, summary and points were compared with opening, middle and
  closing transcript excerpts. IDs: **1298, 1289, 1278, 1267, 1266, 1250, 1235,
  1209, 905, 1195, 1181, 1166, 1165, 1147, 1129, 1113, 1110, 1086, 1065, 1043,
  918, 1018, 993, 967, 964, 951, 934, 919, 972, 1005, 1058, 1087, 1167, 1192,
  1193, 1296, 1297**. Six additional window-risk outputs were then inspected:
  **1148, 1252, 1248, 1299, 1135, 942**, bringing the total to **43**.
- **30 song sections:** ten evenly spaced database-order examples from each of
  generated, pending approval and flagged/not-applicable groups. IDs: **1071,
  1276, 1633, 2008, 2495, 2895, 3243, 3666, 4167, 4679; 1074, 1431, 1842, 2274,
  2677, 3221, 3572, 3911, 4274, 4677; 950, 1603, 2208, 2825, 3001, 3169, 3350,
  3497, 4634, 4588**. Titles, excerpts, classifier notes, matching evidence and
  publication reasons were inspected; #3869 was added after the mixed-song census.
- **12 children's-talk sections**, six pending and six flagged: **1101, 1973,
  2915, 3485, 4135, 4674, 946, 990, 2353, 2710, 3540, 4703**. Summaries and
  excerpt boundaries were compared; speaker identities were not adjudicated.
- **30 frames from ten sermon videos:** #1241, #1164, #1115, #924 (frozen),
  #1206, #931 (mostly black), #1059, #977 (missing-file assessment), #1298, #919
  (approved). Frames were at 0.333 × duration, three seconds later, and 0.667 ×
  duration. Four further frames at 20%/80% of two song videos verified the mixed
  content described below. This is **12 output videos**, not full playback.

Local evidence is retained in `storage/scratch/video-evaluation-20260907-rows.json`
(rows, sample IDs, transcript hashes and window-intersection census), its source
TSV, and `storage/scratch/video-evaluation-frames-20260907/`. These are gitignored
audit artifacts; the IDs and findings here remain usable without them.

**Judgement:** the sampled summaries generally represent the available speech
well, and the repaired #972/#1005/#1058/#1087 contain substantive analysis. But
good summarisation of surviving text can mask loss of the sermon itself. This
was a purposive diagnostic sample, not a random accuracy trial; no percentage
accuracy, word-error rate, complete song-boundary accuracy or audio/video sync
claim follows. Audio listening and complete playback remain necessary for the
labelled evaluation proposed below. The cohort includes 253 full, 150 short-partial
and three fragmented recordings; do not generalise its raw review rate directly
to modern weekly full-service uploads.

##### P8-Q8 expanded — incomplete evidence can pass every completion check

**Highest-priority new defect:** sermon **#1148**, 2023-04-30 morning, run **#1229**,
has a **1,857.7-second video but only 397 characters / 81 words** of transcript.
The text is closing prayer, thanks for preservation, and a hymn announcement.
The generated title is *Preserved by God's amazing grace*, the reference is null,
and the summary describes that prayer. This is a completed, non-degraded run.
Its fallback extraction span **2143.07–4000.62** overlaps the recorded
**1899.24–3936.24** unobservable window by **1793.17 seconds**—about 96.5% of the
selected span. A nonempty check succeeds while the intended sermon analysis fails.

Across all 406 outputs, **18** selected spans overlap recorded unobservable
windows at all; **11 are only about 0.1–1.3 seconds**, so do not indiscriminately
hold them. The **seven with more than ten seconds** are #1252 (275s), #1248
(163s), #1299 (710s), #1148 (1793s), #1135 (470s), #1043 (407s) and #942 (102s).
Ten seconds is an audit grouping, not an approved rejection threshold.

Two sampled openings show why overlap *inside* the chosen span is insufficient:

- **#1195, 2022-04-24:** extraction starts at 2855.85, immediately after a long
  unobservable interval and repeated hymn-announcement cues. The transcript opens
  with a hymn fragment, then “or obedience to Jesus is a dead faith”, partway
  through the argument. The sermon section has confidence **0.91** and no review
  flag; an earlier **1558.7-second `other` section** contains the missing-evidence
  region. The current selected span itself does not overlap the main window.
- **#1043, 2024-12-29:** a **1773-second supposed song** covers most of a long
  unobservable interval; the sermon starts with “for sending Jesus. all nations.”
  A further low-confidence `other` tail is absorbed into the extracted sermon.
  Its 0.93 sermon confidence is not evidence that the sermon is complete.

These prove incomplete/contaminated saved text and unsupported completeness, not
the exact correct audiovisual cut points. Recover and listen to source evidence
before changing those boundaries.

- [~] Extend P8-Q8 from **empty** evidence to **materially incomplete** evidence.
  Validate ordered, bounded, nonempty cues and relevant unobservable windows;
  inspect neighbouring gaps and implausible merged song/other intervals as well
  as the selected span. Propagate uncertainty into the output disposition.
  **The selected-span half is done** — see *The evidence gate, 2026-09-07* below.
  Neighbouring gaps and implausible merged song/`other` intervals are **not**
  covered, which is exactly why #1195 still escapes: its span holds dense speech
  and no window, and the loss sits in the `other` section before it.
- [~] Recover #1148 and the seven material-overlap candidates, plus #1195's
  missing opening, through bounded targeted transcription and structure recovery.
  Recut only when recovered evidence changes the media plan; reanalyse only when
  analysis input changes. Do not fabricate missing speech from Scripture or OoS.
  **The mechanism is found and fixed; the recovery itself is not run.** The
  evidence was not missing — `recover()` was discarding it, and #1148's sermon
  came back in full on a re-run of the existing decode. What remains is to re-run
  recovery over the affected runs and re-measure. The gate holds *future* runs;
  like the mixed-song restriction it is not consulted for work already banked, so
  the five runs it would stop or flag keep their current disposition meanwhile.
- [x] Add regression fixtures for prayer-only surviving text, a sermon starting
  after an unobservable window, and a valid sermon followed by long silence/music.
  Preserve the intentional reading/sermon join and valid brief boundary overlaps.
  All five are in `CreateSermonTranscriptFromServiceTest`, each built from the
  real run that motivated it; the pre-existing join and single-span tests still pass.

###### The evidence gate, 2026-09-07

**The census that set the thresholds.** All **406** completed operation-4 runs
holding a sermon were measured — 406 transcripts read, **zero unresolved** — for
the union of their extraction spans against the union of their recorded
unobservable windows. Evidence: `storage/scratch/p8q8-evidence-census.tsv`
(gitignored; the figures below stand without it).

**Rank by fraction, never by seconds.** The two disagree sharply on real data:

| sermon | blind **s** | blind **fraction** | words | reading |
|---|---:|---:|---:|---|
| 1148 | 1793.2 | **96.5%** | 81 | sermon absent; opening and closing are the same prayer |
| 1043 | 406.7 | **49.2%** | 705 | opens mid-sentence, tail blind |
| 1299 | **710.0** | 27.4% | 4,639 | complete at both ends |
| 1135 | 469.7 | 24.5% | 2,973 | reads perfectly; first ~8 min hidden |
| 1252 | 275.0 | 13.5% | 3,418 | full sermon |
| 1248 | 163.0 | 8.3% | 3,647 | full sermon |
| 942 | 102.0 | 5.1% | 3,898 | full sermon |

#1299 holds the second-largest blind interval of any run and is healthy; an
overlap-**seconds** queue would have put it first. Only seven runs exceed 5%.

**Density of surviving speech was evaluated and rejected.** Words per minute of
span isolates #1148 spectacularly (2.62 against a p1 of 98.4, a median of 122.2).
But measured against *observable* seconds alone the corpus is uniform — minimum
69.2, p1 102.1, median 122.5 — and #1148's surviving fragment itself runs at a
normal **75.5**. ASR emits ordinary-rate text wherever it emits any, so raw wpm is
just `observable_wpm × (1 − blind_fraction)`: it re-expresses the fraction less
legibly and adds nothing. Recorded so the metric is not re-proposed.

**Coherent text does not prove completeness.** #1135 is the case that decides the
review tier: it reads correctly at both ends — a commentary illustration opening,
a proper benediction closing — while a window hides the sermon's first eight
minutes. No reviewer reading that output would see the loss; only the window
reveals it.

**The gate.** `CreateSermonTranscriptFromService` measured only `$sermonText === ''`
— a presence check standing in for a sufficiency one, which is how #1148 completed
clean and banked a title taken from its closing prayer. It now also measures
`App\Data\SermonEvidenceCoverage`: at **≥ 40%** blind the run fails at
`creating_sermon_transcript` (before the analysis call, so no provider spend) and
routes to evidence-directed retry; at **≥ 10%** it completes and the sermon
section carries `sermon_evidence_incomplete` with the measured fraction and
seconds. Both lines sit in real gaps in the distribution — 49.2%/27.4% and
13.5%/8.3% — not through a cluster.

The flag is deliberately **not** a `ServiceStructureValidator` constant. Those
describe the detector's confidence in a boundary and are re-derived from banked
structure; this one describes the recording behind the boundary, exists only once
the extraction plan does, and must persist until the evidence is recovered.
`SectionStructureFlagRederiver` re-derives only `REANNOTATED_FLAGS` and retains
everything else, so a later recompute cannot withdraw it — the failure mode that
makes `is_degraded_completion` unreliable is avoided by construction.

**On the disposition choice.** `is_degraded_completion` was considered and
rejected. `MediaProcessingLog::isDegradedCompletion()` has **no callers**; the flag
only feeds a census measure, the promotion-bundle provenance, and
`ReanalyseHistoricDegradedCompletionsCommand` — whose success path *clears* it. It
would therefore have been actively harmful here: re-analysing an incomplete
transcript succeeds and erases the marker, laundering the defect exactly as an
unscoped song-match recompute would have on 2026-09-03. Nothing gates release on
it; "degraded runs do not count as complete" is enforced by the operator reading a
census when building an authorisation, not by code.

Had the gate existed during the pass it would have failed **2** runs (#1148,
#1043) and flagged **3** (#1299, #1135, #1252) out of 406. All five are quarantined
and none is publicly reachable.

###### The blind windows were never blind, 2026-09-07

**The recovery transcribed the missing sermons and then threw them away.** The
premise behind every "recover the evidence" item above — that these windows hold
audio no decode has read — is wrong, and the fix is not a different decode.

`ServiceTranscriptRecovery::recover()` judged the retry with a single test,
`$retry->isEmpty() || $this->detector->detect($retry) !== []`. One pathological
region anywhere in the retry condemned all of it: every recovered cue was
dropped and the **whole** original window banked `retranscription_failed`. But a
window is chosen because the *original* transcript looped, which says nothing
about how much of the window holds speech.

Re-running both fail-level windows against the live `whisper-server`
(`large-v3-turbo`, the production decode, empty prompt — exactly what the retry
sends) measured what was being discarded:

| run / sermon | window | retry returned | pathological region | words discarded |
|---|---:|---:|---|---:|
| 1229 / **#1148** | 2,037 s | 1,405 cues, 3,282 words | 0–182 s, 7 × "Amen." | **3,275** |
| 1118 / **#1043** | 1,739 s | 1,174 cues, 2,849 words | 0–214 s, 9 × "Amen." | **2,840** |

Identical shape in both: whisper hallucinates a 30-second-chunk loop over the
music or quiet at the window's **leading edge**, then transcribes normally.
8.9% and 12.3% of each window is pathological; the rest is sermon. A 3-minute
slice from the middle of #1148's window decodes cleanly with no intervention at
all — 22 distinct segments of hymn lyrics.

So sermon #1148 did not lose its sermon. Its sermon was transcribed, discarded by
this branch, and the 81 words of closing prayer that survived outside the window
became the whole evidence base for its title, its null reference and its summary.

**The decode was never the defect, and the earlier reading of this — "failure
under the current transcription/pathology configuration", inviting "a bounded
alternative transcription strategy" — was measuring the same all-or-nothing
branch and mistaking it for the model.** Temperature, VAD, `no_context` and the
rest were not needed and were not changed.

The recovery now accepts the retry **region by region**: sub-windows that still
loop are recorded unobservable at their own bounds, offset back onto the
recording's clock, and everything else is kept. A retry that loops throughout, or
returns nothing, still marks the whole window — those paths are unchanged.

- [x] Accept a partly-pathological retry by region rather than as one verdict.
  Regression fixtures cover run 1229's shape, a retry looping throughout, an
  empty retry, and residual-window offsetting onto the recording clock.
- [x] Measure what the fix recovers before re-running anything. **Censused over
  130 runs the same day** — 5.01 h of 15.68 h recovered, +27,282 words, and both
  fail-level runs fall to 0% blind. See *What the region-wise fix actually
  recovers* below for the distribution and the 31 runs it does not help.
- [x] **Re-run recovery over the affected corpus. Done 2026-09-08, from the
  banked retries.** `historic-import:replay-transcript-recovery` replayed all
  **146** affected runs and **202** windows with no provider call, no ffmpeg and
  no dependence on the source recording: **17.86 h blind → 12.34 h, 5.52 h
  recovered (30.9%), +35,125 words**, 146 banked, 0 failures. The replayed
  transcript is banked under its own artifact kind so the pre-fix one survives as
  evidence, and each run records what it replaced. See *What the banked retries
  actually recover* below — it supersedes the re-transcription census above
  wherever the two differ.
- [x] Re-measure `SermonEvidenceCoverage` — **twice, and the two disagree.** The
  re-transcription census put the cohort maximum at 11.9%; the runs' own retries
  put it at **25.4%**, because 1043's banked retry loops where the fresh decode
  did not. Fail line fires on 0 (was 2), review line on 2 (was 6). **Thresholds
  left as they are** — see *The gate is not inert, and should not be retuned*.
- [x] Decide what re-analysis the recovered evidence obliges. **13 runs of the
  146**, 6 materially; the other 133 recovered speech outside their sermon spans.
  Re-derived and re-analysed via `--reanalyse`.
- [ ] Re-place boundaries where recovery moved the evidence but not the
  structure. #1195 is the worked case — evidence back, boundary unchanged. Still
  open: this is structure re-detection, which the replay deliberately does not do.
- [ ] Reconsider the 120-second pathology floor *after* the re-run, not before.
  A shorter floor now also decides how much of a retry is kept, so it is no
  longer only a detection question. **The re-run makes this concrete**: the floor
  is why no run reaches zero blind seconds (below), so lowering it would shrink
  every residual window in the corpus at once.

###### What the region-wise fix actually recovers, censused 2026-09-07

The fix was diagnosed on two runs. This is the corpus.

**Method.** For every run still holding both its source recording and its
pre-recovery `.raw.json`, the real `ServiceTranscriptRecovery` was run over that
raw transcript against that source — the same service, extractor and local
`whisper-server` the pipeline uses. Nothing was written to the database: the
synthetic processing id matches no `MediaProcessingLog`, so
`ServiceArtifactStorage::record()` returns early. **No banked transcript changed.**

**Scope.** **144** live runs carry unobservable windows totalling **17.5 h**, and
**every window in the corpus is `retranscription_failed`** — no other reason
occurs anywhere. 130 of those runs retain both artifacts, giving **15.68 h across
178 windows** as measurable. 130 of 130 completed without error.

| | blind time | windows |
|---|---:|---:|
| banked today | **15.68 h** | 178 |
| after the fix | **10.67 h** | 176 |
| **recovered** | **5.01 h (31.9%)** | |

Against the banked `.normalized.json` transcripts that is **+27,282 words** of
real speech (911,277 → 938,559).

**It is not uniform, and the shortfall is the useful half.**

| outcome | runs | blind before → after |
|---|---:|---|
| fully recovered (0 blind) | 5 | 0.79 h → 0 h |
| partly recovered | 84 | 11.20 h → 6.54 h |
| **unchanged** | **31** | 2.96 h → 2.96 h |
| slightly blinder | 10 | 0.73 h → 1.17 h |

The **31 unchanged runs** are those whose retry loops from end to end; there the
audio really does defeat this decode and the window stays blind, correctly. Run
1004's 72-minute total-loss window is unchanged to within 0.1 s, which is the
negative control: the change does not manufacture recovery. **Those 31 runs, ~3.0 h,
are the only population for which an alternative decode is still worth
evaluating** — not the 15.7 h the earlier reading implied. The 10 slightly blinder
runs are precision, not regression: a residual loop is now recorded at its own
bounds instead of being absorbed into one larger window, so one window can become
two that together span a little more.

**The named cohort collapses.**

| sermon | blind fraction of sermon span | words in span | |
|---|---|---:|---|
| **1148** | **96.5% → 0.0%** | **81 → 3,324** | was the false completion |
| **1043** | **49.2% → 0.0%** | 705 → 1,177 | |
| 1299 | 27.4% → 5.8% | 4,639 → 5,848 | |
| 1135 | 24.5% → 2.4% | 2,973 → 3,887 | the hidden first eight minutes |
| 1252 | 13.5% → **11.9%** | 3,418 → 3,423 | the only one still flagged |
| 1248 | 8.3% → 7.7% | 3,647 → 3,647 | window never met the span |
| 942 | 5.1% → 0.5% | 3,898 → 4,035 | |

Sermon **1148 was never absent**. Its span now holds 3,324 words opening *"When I
stand behind the lectern each week on a Sunday, should my legs be shaking…"* — a
sermon's first sentence, not the closing prayer its banked title was taken from.

**This voids the evidence gate's calibration.** `SermonEvidenceCoverage`'s 40% line
was set "in the gap between 1148 at 96.5% and 1043 at 49.2% and the healthy
remainder, whose worst case is 1299 at 27.4%". After recovery the entire cohort's
maximum is **11.9%**: the gate would fail **0** runs where it would have failed 2,
and flag **1** where it would have flagged 3. Both lines were drawn across a
distribution this defect created.

- [ ] **Do not retune the thresholds from these figures.** They are a fresh
  transcription of the same audio, and whisper is not bit-deterministic between
  runs, so they indicate the shape of the corpus, not its final values. Re-run
  recovery for real, then re-derive the census from what gets banked.
- [ ] The 40%/10% lines will need re-deriving against that distribution, or the
  gate becomes inert — a fail line at 40% over a corpus topping out near 12%
  never fires.

**#1195 needs re-detection, not just recovery.** Its 1,558-second `other` section
(3488, confidence 0.20) now holds **2,592 recovered words**, so the evidence is
back. But its sermon boundary is still at 2855.8 and the sermon still opens
mid-argument on *"or obedience to Jesus is a dead faith"*. The boundary was placed
by structure detection reading the blind transcript, and recovery alone does not
move it. **Recovering the evidence and re-placing the boundary are two steps**, and
only the first is addressed here.

###### The discarded retries were archived all along

The re-run does not need transcription. **Every retry the pipeline ever made is
still on disk**, in `service-transcripts/unknown-date/` as
`other-<processing id>-recovery-<n>.raw.json` — 413 artifacts under 213 processing
ids, covering **130 of 130 affected runs and 178 of 178 windows**. They land in
`unknown-date` rather than beside their run because the recovery's synthetic id
(`<processingId>-recovery-<n>`) matches no `MediaProcessingLog`, so
`ServiceArtifactStorage` cannot resolve a date — the same orphaning recorded for
the 911 raw artifacts, here working in our favour.

`putJson()` archives the retry *before* `recover()` decides what to do with it. So
the transcription was banked and the decision to discard it was taken afterwards,
against a copy that has been sitting on the drive since 2 September.

**They corroborate the census from the pipeline's own evidence**, not a fresh
transcription of the same audio:

| run 1229, attempt 3 | segments | words | loop | words outside the loop |
|---|---:|---:|---|---:|
| banked 2026-09-02 | 1,461 | 3,284 | 0–182 s, 7 × "amen" | **3,277** |
| re-transcribed 2026-09-07 | 1,405 | 3,282 | 0–182 s, 7 × "amen" | 3,275 |

Two independent transcriptions five days apart agree to within whisper's own
non-determinism, and both hold the sermon.

This makes the re-run **deterministic, free and auditable**: read the banked
retry, apply the region-wise rule, compare against the banked transcript. No
model call, no ffmpeg, no dependence on the source recording still existing — and
it reproduces exactly what the pipeline saw rather than a fresh approximation of
it. `historic-import:recover-calibration-transcripts` is the right shape for this
and already reads banked artifacts offline.

- [ ] **Map attempt to window by clip length, not by index.** `-recovery-<n>` is
  the *window index within one job attempt*, and a run that was retried overwrites
  in place, so run 1229 has three artifacts against two banked windows. Matching
  is by the artifact's last segment end against the window's duration — and it is
  inexact in the same direction every time: 1229's 2,037-second window matches its
  artifact to 0.01 s, but its 184-second window's artifact ends at 175.5 s because
  the clip's tail is silent. Treat clip end as a **lower bound** on window length
  and resolve ambiguity explicitly; do not assume artifact *n* is window *n*.
- [ ] Re-derive the whole census from the banked retries once that mapping is
  settled, and prefer it to the re-transcribed figures above wherever they differ.

###### What the banked retries actually recover, executed 2026-09-08

The census above re-transcribed the audio. This replayed the retries the runs
themselves made and discarded, which is a different question with a different
answer — and it is the one that was banked.

**Scope.** **146** runs carry unobservable windows, and **every window in the
corpus is `retranscription_failed`**; no other reason occurs anywhere. All 146
runs and all 202 windows still hold their retry artifacts, so the whole affected
set is measurable — 16 more runs and 2.2 h more than the re-transcription census
could reach, because that needed the *source recording* retained and this does
not.

| | blind time | windows |
|---|---:|---:|
| banked before | **17.86 h** | 202 |
| after the replay | **12.34 h** | 206 |
| **recovered** | **5.52 h (30.9%)** | |

**+35,125 words** of real speech, 146 of 146 banked without error.

| outcome | runs |
|---|---:|
| partly recovered | 104 |
| **unchanged** | **36** |
| slightly blinder | 6 |
| fully recovered | **0** |

**No run reaches zero blind seconds, and that is a certainty rather than a
result.** Every window here was banked `retranscription_failed`, which under the
old rule *required* `detect($retry)` to return a window — and the detector's
`min_window_seconds` floor is 120 s. So each of these runs must retain at least
one residual window of ≥120 s; the smallest after the replay is 122 s. The
re-transcription census reported **5 runs fully recovering**, which is only
possible because a fresh decode is free to not loop where the banked one did.
Where the two censuses disagree, prefer this one: it is what the pipeline had.

**The named cohort, measured as `SermonEvidenceCoverage` measures it** — blind
fraction of the delivered sermon span, over the 142 affected runs that have one:

| sermon | run | before | after | |
|---|---|---|---|---|
| **1148** | 1229 | **96.5%** | **0.0%** | 81 → 3,300 words in span |
| **1043** | 1118 | **49.2%** | **25.4%** | *not* 0.0% — the corpus maximum |
| 885 | 949 | 31.8% | 7.9% | 3,538 → 4,806 words |
| 1299 | 1300 | 27.4% | 5.8% | 4,600 → 5,796 words |
| 1135 | 1217 | 24.5% | 2.4% | the hidden first eight minutes |
| 1252 | 1327 | 13.5% | 11.9% | still flagged |
| 1248 | 1323 | 8.3% | 7.7% | window never met the span |
| 942 | 1007 | 5.1% | 0.5% | |

Sermon **1148 was never absent.** Its span held 81 words of closing prayer and
now holds 3,300, and its banked title, its null reference and its summary were
all derived from those 81.

###### The gate is not inert, and should not be retuned

The earlier reading — a 40% fail line over a corpus topping out near 12% "never
fires", so the lines need re-deriving — does not survive the measurement.

| | before | after |
|---|---:|---:|
| fail (≥40%) | 2 | **0** |
| review (≥10%) | 6 | **2** |
| corpus maximum | 96.5% | **25.4%** |

Percentiles after the replay: p50 0.0%, p90 0.0%, p95 0.6%, p99 11.9%, max 25.4%.

**Leave both lines where they are.**

- The fail line's job is the catastrophic case, and the corpus still contains
  one: run 1004 lost a **72-minute** window covering its whole recording, and is
  absent from the table only because it failed before an extraction plan existed.
  A future run of that shape reaches ~100% and is correctly refused. The line
  does not fire on *completed* runs any more because the defect that manufactured
  a 96.5%-blind completion is fixed — which is the line working, not failing.
- The review line still fires on exactly the two runs with the most residual
  blindness. Its justifying case (1135 at 24.5%, reading perfectly at both ends
  while hiding eight minutes) is gone, but 1043 at 25.4% now occupies almost the
  same position, so the line is doing the same work for the same reason.
- Re-tuning down from this distribution would fit thresholds to 142 post-fix
  observations of a corpus whose shape the fix has just changed. The honest move
  is to leave them and re-derive if a *future* pass produces a distribution they
  fail to describe.

###### Where the retries actually were, and how they map to windows

The retries are archived under `service-transcripts/unknown-date/` as
`other-<processing id>-recovery-<n>.raw.json`, because the recovery's synthetic
id (`<processingId>-recovery-<n>`) matches no `MediaProcessingLog` and
`ServiceArtifactStorage` cannot resolve a date. `putJson()` archives the retry
*before* `recover()` decides what to do with it, so the transcription was banked
and the decision to discard it taken afterwards, against a copy still on disk.

**They are under the run's own batch root**, not the bare staging root:
`historic-batches/<plan hash>/service-transcripts/unknown-date/`. Two batch roots
hold them (451 artifacts across `8ecec582…` and `9351fa4e…`), and the 2026-09-07
census's *own* 320 re-transcriptions sit in the unscoped root because that census
ran outside a staging context. Same relative path, different roots — which is
also why that session recorded both "413 files, none of them mine" and "my
measurement wrote nothing to the staging drive", each true of one directory and
false of the other. A run resolves its own root from
`processing_metadata.historic_import.staging_context.batch_root`.

**The artifact index is the *detected* window index, not the banked one.** A
window is banked unobservable only when its retry was rejected, so a run with
four detected windows and one banked window still has four artifacts and the
banked window may be any of them. Matching by clip length — proposed above, and
the obvious approach — is wrong twice over:

- It is **ambiguous**. Run 971 has two detected windows of exactly 152.00 s; the
  banked one is the first, whose artifact carries the *earlier* mtime, so both
  "nearest duration" and "most recent" pick wrongly. 1198 has two 211.00 s
  windows and 1342 has four artifacts against one banked window.
- The clip is **shorter than the window** whenever whisper's cue times overrun
  the real audio — run 1004's window is 4317.64 s against 4297.57 s of clip — so
  the tolerance needed to absorb that is larger than the gaps it must resolve.

Because `ServiceTranscriptPathologyDetector::detect()` is pure and the banked
`.raw.json` is the exact provider response, the detected list is **re-derivable**:
rebuild the pre-recovery transcript from the raw segments, apply the prompt-echo
filter as `TranscribeFullService` does, and run the detector. That maps all 202
windows across all 146 runs with nothing ambiguous and nothing missing.

**The rebuild is verified, not assumed.** Outside the blind windows, the replayed
transcript is cue-for-cue identical to the banked one for all 146 runs — so the
reconstruction reproduces exactly what the pipeline fed to recovery. (Two runs
show a single extra cue at a window's trailing edge: a zero-length cue sitting on
the boundary satisfies "outside" on both sides of a half-open interval test. Both
are genuinely recovered content.)

###### What the replay obliges, and what it does not

A sermon transcript is derived from the service transcript and the run's
extraction spans, so it stales the moment the replay lands. **13 of the 146 runs
gained sermon-span text**; the other 133 recovered speech in a song, a prayer or
the notices, and change nothing a title was drawn from.

| sermon | run | words in span | |
|---|---|---|---|
| 1148 | 1229 | 81 → 3,300 | the false completion |
| 885 | 949 | 3,538 → 4,806 | |
| 1299 | 1300 | 4,600 → 5,796 | |
| 1135 | 1217 | 2,951 → 3,848 | |
| 1043 | 1118 | 705 → 956 | |
| 942 | 1007 | 3,874 → 4,019 | |
| *seven more* | | ≤ +21 words each | |

`historic-import:repair-sermon-transcript-spans` is the wrong instrument for
these: it declines a single-span plan, correctly, because *its* defect (slicing
the outer bounds across a gap) cannot occur with one span. Ours can. The
re-slicing mechanic is now shared rather than copied — the caller says which
population it is asking about, and the derivation, including the disk subtlety
for a promoted historic sermon, stays in one place.

**19 runs were re-analysed, not 13.** The selection asks whether the sermon
transcript is *stale*, not whether the replay staled it, so it also swept in six
two-span runs still carrying the **P8-Q1 span defect** — 939, 946, 948, 954, 956
and 962, whose transcripts *shrank* by 210–4,504 characters as the intervening
hymn or notices came out. That is work `repair-sermon-transcript-spans` was
already owed and had never been run; it is correct, but it was not predicted.

All 19 completed, 0 failed, all still `completed`. Summaries, points and
references were rewritten from the recovered evidence — sermon 1148's summary is
now Jude's warnings, its reference `Jude 1:5-7`.

###### The title survived the correction, and should not have

**`titleMayBeReplacedByAnalysis()` treats `AiAnalysis` provenance as
authoritative**, so a title derived from evidence later proved to be 2.4% of the
sermon outranks one derived from the whole of it. Sermon 1148 therefore came out
of re-analysis titled *"Preserved by God's amazing grace"* — a fair summary of
the 81-word closing prayer — sitting above a summary about judgement on unbelief,
rebellion and sexual immorality. The fresh analysis had produced *"Warnings from
Jude about false teachers"* and the code discarded it.

That is the same shape as the defect this whole thread began with: a correct
answer computed, then thrown away by a rule that could not tell it was better.
1148's title and slug were corrected by hand from the analysis already paid for
(`warnings-from-jude-about-false-teachers`; the old values are in
`storage/scratch/sermon-1148-title-before.json`). Nine other titles also differ
from the fresh analysis and were **deliberately left**: they are ordinary
paraphrase variance (*"Let your yes be yes"* vs *"A people who keep their
word"*), not corrections. Every affected sermon is quarantined, so none of this
was public.

- [ ] Decide whether an AI title should be replaceable when the evidence behind
  it is later shown to have been incomplete. A provenance of `AiAnalysis` records
  *who* wrote the title, not *what it saw*, and nothing in the record
  distinguishes a title drawn from 81 words from one drawn from 3,300.

###### The evidence flag had no second writer

`SermonEvidenceCoverage` was measured only where `CreateSermonTranscriptFromService`
first derived a transcript, and **nothing re-asked it afterwards**. Zero sections
in the corpus carried `sermon_evidence_incomplete` — the gate has never fired,
because these runs predate it. Re-deriving a transcript outside that job would
have left the question unasked a second time.

The flag and its rule now live in `FlagIncompleteSermonEvidence`, called by both
the job and the re-derivation, and it **withdraws** as well as raises: recovered
evidence is exactly the event that makes a standing flag wrong. Two runs qualify
after the replay and are now flagged — **1118 at 25.4%** and **1327 at 11.9%** —
which is the review line doing the work the 2026-09-07 reading expected it to
have stopped doing.

###### Structure re-detection: sized, instrumented, piloted 2026-09-08

**The set is 21 runs, in two shapes that do not overlap.** Detection reads the
full-service transcript, so where that transcript was blind the structure was
drawn over a void — and it happened two different ways:

- **Void-covered (10 runs).** A low-confidence placeholder spanning the blind
  region. Run 1278 is the type: `other`, confidence **0.20**, 1,558.7 s, its
  bounds sitting almost exactly on its 1,439-second window.
- **Void-skipped (11 runs).** **No section at all.** Run 1127's structure simply
  has a 2,068-second hole holding 4,718 recovered words; run 1035's is 2,816 s
  and 5,855 words. This shape is invisible to any check that looks for a
  suspicious section, because there is no section to find — the first sizing pass
  missed all 11 for exactly that reason.

The largest single case was not the named one: **run 1229's own `sermon` section**
is confidence 0.42 over 2,070 s that was **98% blind** when the detector drew it.

**The other 83 replayed runs need nothing**: median 8 recovered words, and no
unsectioned stretch holds more than 64. Their recovered speech is in songs.

**Feasibility.** 18 of 21 still hold source (50.4 GiB, mean 2.80 GiB). Runs 939
and 1377 have lost theirs and are trivial (186 and 65 words); **run 947 has lost
its source and is not trivial** — 2,025 words into an `other`@0.20 section, and it
can never be re-derived. All 20 sermons are quarantined; the only public exposure
is 27 published sections, all songs, across 15 runs.

**It is not "one provider call".** Re-detection re-opens a completed run and the
chain runs on through `ExtractSermon`, `EnhanceAudio`, `CreateSermonRecord`, the
sermon transcript, `ProcessTranscriptWithAI`, video quality, thumbnail,
notification, promotion and cleanup — roughly two provider calls plus a full media
re-cut. Transcription is *not* repeated: it precedes detection, so the corrected
transcript is exactly what detection reads.

**No instrument existed.** `ProcessingRunOrchestrator::retry()` refuses anything
not failed or cancelled and every one of these runs is *completed*, while
`RedetectHistoricServiceStructure` accepts only failed runs held at
`manual_review_required`. `redetectServiceStructure()` is therefore a separate
entry point, for the reason `recoverHistoricTail()` is one — widening `retry()`'s
guard would let every caller, the admin retry button included, re-open a terminal
run. Its load-bearing check is the transcript-replay stamp.

- [ ] **The source check must resolve `temp_disk` from config inside the run's
  staging context.** The guard rewrites that key to the staging disk and re-roots
  it at the batch, so the sources live under
  `staging/historic-batches/<hash>/livestream/temp/` and are genuinely absent from
  `historic_temp` by name. Naming a disk reports all 18 retained sources as lost —
  the same trap as measuring artifact reuse outside `within()`.

###### The pilot: run 1278 (#1195), 2026-09-08

Dispatched 10:28:50Z, completed 10:41:45Z — **~13 minutes** for a 6.31 GiB source
at 13.65 Mbps, which is above the 6 Mbps threshold so every section clip
re-encodes rather than stream-copies. 0 failures. **The tail dominates**: detection
and extraction were quick, and `PrepareSectionPublicationCandidates` re-cut 13
clips at roughly 30 s each. Cost scales with source bitrate, not run count.

The void resolved into real structure:

| | before | after |
|---|---|---|
| the blind region | `other` 1297.1–2855.8, 1558.7 s, **conf 0.20** | *gone* |
| | — | `prayer` 1139.7–1487.0 (was cut off at 1297.1) |
| | — | **`bible_reading` 1502.0–1628.0** — invisible before |
| | — | `song` 1641.0–1873.0 |
| sermon | 2855.8–3640.1, **784 s**, conf 0.91 | **1873.0–3640.1, 1,767 s, conf 0.99** |

**The sermon boundary moved back 982.8 seconds** and its confidence went 0.91 →
0.99. The extraction plan became concatenated — `[1501.99–1628.01,
1873–3640.08]` — joining the preached reading to the sermon and dropping the song
between, which is the concatenation feature working as designed.

The symptom is gone. The banked transcript opened mid-argument on *"or obedience
to Jesus is a dead faith"*; it now opens **"The reading is from Matthew chapter 7.
We're going to read the whole conclusion to the Sermon on the Mount…"** — Matthew
7:13 leading into the wise and foolish builders, which is exactly the sermon's
reference (`Matthew 7:24-27`) and its title (*Build your life on the rock*, which
the re-analysis independently reproduced).

Structural flags fell from 5 to 3: `structure_missing_preached_reading` cleared
because there now *is* a reading, `structure_micro_section` cleared with the 6.2 s
sliver, and `structure_low_confidence` went 3 → 1. Sections stayed at 13 and
published sections at 1, so public exposure did not move. `needs_manual_review`
rose 1 → 3, both additions being `song_title_marker_mismatch` on re-published
songs — a different class, and the queue is the point.

###### The full pass, 2026-09-08: 19 of 21, all completed

The batch of 16 ran 10:58:33Z → 13:01:17Z (**~2h03m**), serialised behind the single
ffmpeg worker. Two more followed after their sources were restored, and the one
failure was retried successfully. **19 runs, 0 failed.**

| | before | after |
|---|---:|---:|
| sections | 231 | **253** |
| total sermon time | 28,637 s | **32,255 s** (+60.3 min) |
| sermon sections below 0.9 confidence | 2 | **0** |
| `needs_manual_review` | 20 | **13** |
| `structure_low_confidence` | 25 | **8** |
| `structure_missing_preached_reading` | 4 | **1** |
| `sermon_evidence_incomplete` | 1 | **0** |

**Six sermons moved materially, and two of them got *shorter*.**

| run | sermon | before | after | |
|---|---|---|---|---|
| 1118 | 1043 | 420 s @0.93 | **1,935 s @1.00** | +1,515 s |
| 1127 | 1052 | 720 s @0.98 | **1,949 s @0.99** | +1,229 s, sections 9→15 |
| 1278 | 1195 | 784 s @0.91 | 1,767 s @0.99 | +983 s |
| 1324 | 1249 | 1,170 s @0.98 | 1,596 s @0.99 | +426 s |
| **1229** | **1148** | 2,070 s **@0.42** | 1,799 s **@1.00** | **−272 s** |
| **1300** | **1299** | 1,913 s **@0.84** | 1,652 s **@0.99** | **−261 s** |

The two that shortened are the fix working, not failing: both had *over-long*
sermon sections smeared across ground the detector could not see, at 0.42 and
0.84 confidence. With the evidence restored both tightened and went to ~0.99. A
pass that only ever lengthened sermons would have been the suspicious result.

**Run 947 is the strongest section-level case and it did not move its sermon at
all.** Its blind region had swallowed five sections into one 790-second "song"
plus a 562-second `other`@0.20; it now reads song / prayer / **children's talk** /
other / song / prayer, and its sermon boundary was already correct. Structural
damage from a void is not confined to the sermon.

**The one failure was real and recoverable.** Run 1196's re-detected structure was
*rejected by the validator* — a sermon section double-claiming an OoS item that is
a prayer — leaving a completed run failed at `manual_review_required`. That is the
non-determinism this instrument warns about. A plain `retry()` (the run being
genuinely failed by then, so no guard bypass) re-ran detection and the second pass
validated. **Retry before rolling back**; the snapshot is the fallback, not the
first move.

###### Restoring a source, and why byte-identity is the bar

Runs 939 and 947 had lost their sources to cleanup, and the Sonnics archive held
both under their original filenames. **Both hash exactly to the `file_hash` the
runs recorded**, and their durations match to the microsecond, so this was a
restore rather than a substitution.

That distinction is the whole point. The banked transcript is timed against the
original recording; detection produces section times from it and extraction cuts
the media at those times. A same-service capture starting even a few seconds
differently would misplace every section with nothing in the output revealing it.
`historic-import:restage-source` therefore refuses anything but a byte-identical
file and re-hashes after writing, and refuses outright when a run recorded no hash
(1377 and 1035 are in that position — for those the banked RMS log is the evidence
to reach for). The archive was already mounted read-only at `/mnt/cbc-services`,
so no infrastructure change was needed.

- [x] Re-derive every structure the recovery replay staled. **19 of 21 done.**
- [ ] **Run 1035** — a *failed* run holding the corpus's largest void (5,855 words
  across 2,816 s), and its source is present. It needs a disposition decision, not
  a source: this instrument re-opens *completed* runs only.
- [ ] **Run 1377** is retired, so there is no structure worth re-deriving. Closed.

**Recovering evidence and re-placing a boundary are two steps** — the replay does
only the first, and this is the second.

###### The neighbouring-section half: a blind region typed as a song

Chasing #1195's class — evidence lost *outside* the sermon span — found the
mechanism, and it is not confined to the sermon's neighbours.

**133 non-sermon sections across 102 runs hold 60 s or more of blind time**, but
almost all are unremarkable: a sung hymn produces no speech cues, so a genuine
four-minute song is also ~95% unobserved. Blindness alone therefore cannot
separate a real song from a hole. **Length can**, because a sung item has a
ceiling — six minutes, per the operator, and the corpus agrees: **1,049 of 1,078
song sections (97.3%) sit inside it**, with the whole tail being 20 to ten
minutes, six to fifteen, two to twenty and one at 29.5.

Reading the transcript of every song over six minutes found **three distinct
defects**, not one:

| section | length | words | blind | what the text is |
|---|---:|---:|---:|---|
| **1977** (#1043) | 29.5 min | 96 | 98% | the spoken announcement — *"let's stand and sing"* — then **nothing for 29 minutes**. Confidence **0.94**, **no flag at all** |
| **3437** (#1191) | 7.1 min | 28 | 97% | announcement, then silence. Confidence **0.97**; its only flag is cross-type inversion, which the policy always demotes |
| **1511** (#974) | 19 min | 1,834 | **0%** | **sermon material** — a preaching illustration on Christ crucified — mistyped as a song |
| 4525 (#1283) | 12.8 min | 5,149 | 0% | genuine: a hymn practice, *"let's have one more go at that"* (ASR looping at 401 wpm) |
| 4354 (#1268) | 12 min | 606 | 0% | genuine: a carol sung through with repeats |

**Confidence is decoupled from evidence.** Section 1977 claimed 29.5 minutes as
one song on a 30-second announcement and scored itself 0.94, because confidence
judges how a section was *typed*, not what underwrites the span it claimed. That
is the same defect as #1148 one layer up, and #1977 sits immediately before
#1043's sermon — the sermon that opens mid-sentence — so that sermon's true start
is very likely inside the hole.

`ServiceStructureValidator` already raises `structure_micro_section` for sections
too *short*. `structure_macro_section` is its twin, with a per-type ceiling map
holding only `song => 360.0`. It is deliberately not config-backed: this is domain
knowledge about what a sung item is, not an operator tunable. `other` is the
obvious next candidate — 3,021 s against a 145 s mean — but it is a catch-all with
no established ceiling, and inventing one would manufacture review rather than
measure it.

Because the flag is a pure fact about banked structure it joins `REANNOTATED_FLAGS`,
so `services:rederive-structure-review-flags` applies it retroactively with **no
reprocessing and no provider calls**. The dry run raises it on **38 sections across
33 services and removes nothing**.

**This is not a historic-lane defect.** Of those 38, **29 are operation 4, seven
come off the routine weekly pipeline and two from earlier historic operations** —
the same shared-pipeline shape as the mixed-song clips.

- [x] Flag song sections longer than a sung item can plausibly run, at detection
  and retroactively over banked structure. Regression fixtures cover 1977's and
  3437's shapes, a confident over-long song, an ordinary song inside the ceiling,
  and a type with no ceiling.
- [x] Run the rederivation. **Executed** — 38 sections across 33 services carry
  the flag and all 38 require review, raising the queue to 332 sections across
  173 runs. Raising it by 38 was an operator decision, not a side effect of the
  code landing.
- [ ] Decide what a flagged over-long song means for each of the three classes.
  A hole (1977, 3437) needs the region re-examined and probably re-typed; mistyped
  sermon material (1511) is a content-loss case and the more serious of the two;
  a genuine long item (4354, 4525) should be confirmable and dismissed. The flag
  identifies the population, it does not adjudicate it.
- [x] 1511 is a **new class not previously recorded**: sermon material living
  outside the sermon section, invisible to the span-coverage gate because it is
  fully observed. Census it properly before generalising from one case.
  **Censused — and it is not one class.** See below.

###### What a "song" section actually contains, censused 2026-09-07

**Word count cannot separate speech from song** — a sung item's transcript can
carry *more* words than a sermon's when ASR loops on a refrain (§4525: 5,149 words
in 770 s, 401 wpm). Prose and lyrics differ in **repetition**, so the measure used
was the share of **distinct 5-grams**, computed over every section of every
completed operation-4 run with 60+ words. It separates cleanly:

| type | n | p10 | median |
|---|---:|---:|---:|
| **song** | 935 | **0.205** | **0.742** |
| sermon | 406 | 0.861 | 0.970 |
| prayer | 615 | 0.941 | 0.992 |
| bible_reading | 517 | 0.954 | 0.996 |
| childrens_talk | 165 | 0.940 | 0.985 |
| notices / welcome / other | 644 | 0.948–0.982 | 0.995–1.000 |

Eleven song sections hold 300+ words at a ratio ≥ 0.95 — long, unrepetitive prose
inside a "song". **Reading all eleven found five different things, not one class:**

| what it is | sections |
|---|---|
| **Sermon material absorbed** | **§1511** (a preaching illustration on Christ crucified), **§2578** (*"he declares them to be righteous in his sight"*, then the song announcement) |
| **A prayer typed as a song** | **§988** — *"show us the guilt and pollution of sin"*. **Published, no flags, no review** |
| Bible reading tail absorbed | §1929 |
| Extended spoken introduction absorbed | §2490 (the carol author's story), §3308, §1457, §1728, §2486, §2487 |
| **False positive** — genuine lyrics, simply not repetitive | §4335 (*"I cannot tell why he who angels worship"*) |

So 1511's class has **two** members, not one, and the dominant pattern is the
already-named spoken-framing category of P8-Q9 — reached by a sharper instrument.
The distinct-5-gram ratio is therefore a candidate measure for P8-Q9's 162
sole-reason framing cases: it distinguishes a brief announcement from an absorbed
prayer, reading or sermon, which the single "spoken framing" reason cannot.

**What the macro flag caught, and what it did not.** The four over-six-minute
members (§1511, §2486, §2487, §2490) are now flagged. The other seven run
218–286 s, sit inside the ceiling, and remain unflagged — **including §988, a
prayer with a generated 218-second song clip and no review of any kind**.

**Exposure.** All six generated clips in this set are `quarantined` on
`historic_quarantine`, so none is publicly reachable. But **four publicly released
song clips on the routine weekly pipeline exceed the ceiling** — §306 (510 s),
§309 (500 s), §322 (458 s), §437 (369 s) — each `publication_state = published`
with a null asset disk, the same `publiclyReleased()` posture as #335. Their
content could **not** be verified locally: routine runs no longer retain their
service transcripts and the assets are the known null-`asset_disk` case. All four
are now flagged and awaiting review, as is §398, whose only previous flag was the
always-demoted cross-type inversion — it was invisible to the review queue before.

- [ ] Judge the four public over-length clips from the assets themselves, which
  needs production access rather than a local read.
- [ ] Decide whether a prayer or reading typed as a song should be caught by type
  rather than by length. §988 shows the length ceiling alone does not reach it.

**ASR also needs calibration below its current pathology floor.** #1181 contains
21 consecutive “He couldn't have learned the same” cues over about 21 seconds,
with no unobservable-window flag. `ServiceTranscriptPathologyDetector` defaults
to a **120-second** minimum window. #1267 has extended word-by-word punctuation;
#993 has garbled reading names; #972 has an obvious suspect word in the Elizabeth
illustration. These are transcription anomalies, not authorised corrections.
Evaluate short-loop detection on speech, while protecting deliberate rhetorical
repetition and sung refrains. A low threshold applied indiscriminately to songs
would create more false work.

##### P8-Q10 — one-song publication must reject unresolved multi-song intervals

**Confirmed in actual output frames, not merely classifier notes.** Section
**#1276**, 2026-04-05, contains *Come, behold the wondrous mystery* and *Where,
O grave, is your victory?* Its notes say no reliable separating boundary exists,
and `additional_song_matches` records the second OCR-confirmed song. Nevertheless,
the boundary decision is `release_eligible`, no review flag remains, and
**SongVideo #172** is a **458.71-second** clip assigned to the first song.

Section **#3869**, 2021-10-10, repeats the failure with *Jesus shall take the
highest honour* and *Lord, I lift your name on high*: **SongVideo #371**, **276.387s**.
Both generated files visibly contain the two different songs' lyrics. Both
SongVideo records are **quarantined on `historic_quarantine`**; section status
`published` is not proof of public exposure.

`SongPublicationReviewPolicy` checks inferred matches, short duration, adjacent
same-song sections, partial-source corroboration and outer boundary risks. It
does not inspect `additional_song_matches`. Confident OCR confirms membership of
two songs; it cannot justify presenting their combined interval as one song.

###### The census was understated, and this is not a historic-lane defect

Enumerating `metadata->additional_song_matches` directly on 2026-09-07 finds
**eight** active sections, not six, and **three** reached generated status, not
two. The third is **section #335**, service **2026-03-01**, published
**2026-07-09**: *When I fear my faith will fail* (song 349) sits inside a
397.29-second clip issued for song 1024.

Its processing log carries `historic_import_operation_id = NULL`. #335 came off
the **routine weekly pipeline**, months before the bulk pass, and its **SongVideo
#26 satisfies `publiclyReleased()`** — `publication_state = published`, asset
disk null rather than `historic_quarantine`. The quarantine that contains #172
and #371 is a property of the historic lane, not of the fix. The shared gate is
therefore the only correct place for the restriction, and public exposure of a
mixed-song clip is already demonstrated rather than hypothetical.

The five sections that stopped at `not_applicable` — #213, #1258, #1894, #2163,
#3627 — were held by other reasons, not by this one. Do not read them as evidence
that the pipeline recognised the second song.

- [x] Add a shared publication restriction for unresolved multiple performed
  songs in one interval. Cover both #1276 and #3869 in regression tests, including
  successful OCR and an otherwise clear outer-boundary assessment.
  `SongPublicationReviewPolicy` now names `unresolved_multiple_songs` from
  `additional_song_matches`, so `SongPublicationHandler::requiresApproval()` holds
  the clip and every review surface shows the reason. A further match resolving to
  the section's own song is corroboration from a later frame and does not hold;
  one naming no catalogue song does, because failing to place a title is not
  evidence that a single song was sung.
- [~] Reconcile the **four** existing mixed-song outputs (three when written; #306
  was found on 2026-09-07). Keep both song identities
  as service evidence; generate separate clips only after the internal boundary is
  supported. Do not fix this by dropping the second match. The new reason holds
  future clips but changes nothing already published: `requiresApproval()` is not
  consulted for a section whose status is already `published`.

  **#335 withdrawn locally on 2026-09-07** via `SongVideoService::deleteVideo()`,
  the supported path: the file and row go, and the section resets `published` →
  `not_applicable`. Re-preparing it then returns `requiresApproval = true` with
  reason `unresolved_multiple_songs`, so the clip cannot silently republish — the
  gate is what makes the withdrawal durable, and withdrawing before it existed
  would have been undone by the next run over log 910.

  **Prod is not reconciled and was never read.** All of the above is the local
  working copy, which is not a mirror: `sermon_disk` points at `historic_staging`
  here and neither file exists locally. `production-audit.yml` is still missing
  its environment secrets, so confirming and repeating this needs a command on the
  server.

  **Check `SongVideo #9` before repeating it there.** Withdrawing #26 makes #9 the
  display video for song 1024, and #9 is an orphan: `service_section_id` is NULL,
  its path names section **264**, and no section in that range and no second
  processing log for service 730 survive — the run it came from was deleted
  outright, not superseded. `SongVideoService::getVideoUrl()` does not test
  existence, so if that file is gone from the prod sermon disk the song page
  swaps a wrong-content video for a broken player. Verify the file, not just
  the row.

  #1276 and #3869 remain published-but-quarantined and are not publicly reachable,
  so neither is urgent.

  **A fourth mixed clip, #306, found 2026-09-07 by a different route and also
  publicly released.** Chasing the four over-length public song clips turned up a
  510-second clip issued for *All creatures of our God and King* on 2026-07-05.
  Three sources agree it holds two songs: its notes say *"Introduced as two songs
  together; this is the first"*, its transcript opens *"we're going to sing two
  songs together"*, and the OpenLP order prints **five** songs where the run made
  **four** sections — the missing one, ***King Of The Ages***, printed immediately
  after it, has no section anywhere in the service.

  **`unresolved_multiple_songs` could not see it**: `additional_song_matches` is
  empty, because the second song was never OCR-matched. A gate keyed to one
  evidence shape misses a defect that presents in several. So
  `SongPublicationReviewPolicy` gained a second route,
  **`unlocated_adjacent_song`** — the next *printed* song has no section, and this
  clip runs over six minutes. Both conditions are required: an unlocated printed
  song is ordinary alone (only **19 of 326** services have none), so the length is
  what makes it evidence.

  The model's own notes were considered as the signal and rejected: of the nine
  sections corpus-wide whose notes mention multiple songs, **three are negations**
  — one says only one of the pair was "evident in the transcript" — so a gate on
  free-form prose would hold correct sections.

  The new route corroborates itself: run over the corpus it **independently
  rediscovers #335 and names *When I Fear My Faith Will Fail***, exactly the song
  already recorded as buried in it, and #335 now carries both reasons at once.

  **#306 withdrawn locally 2026-09-07**, gate first as with #335. `SongVideo 58`
  and its file are gone, the section reset `published` → `not_applicable`, and
  `requiresApproval()` now returns **true** with the new reason, so it cannot
  silently republish. Song 1153 had **no other video**, so unlike #335 there is no
  successor to verify — the song page simply has none. **Prod is not reconciled**;
  #306 joins #335 in needing a command on the server.

  Of the other three public over-length clips, none is held and none should be:
  **#322**'s apparently missing fifth song is a **duplicate order-of-service row**
  (*According To Your Gracious Word*, listed twice at position 12), **#437**'s
  unlocated songs sit at printed positions 7–8 against its own position 4, and
  **#309**'s song matches cleanly with nothing unlocated beside it. All three
  remain published and are flagged `structure_macro_section` for review.
- [ ] Evaluate timed slide changes plus audio evidence to propose internal
  boundaries. Sparse title OCR alone is insufficient to establish sung onset/end.

###### Separating the interval is not something the application can do

Proposing an internal boundary is only half the work, and the plan had recorded
only that half. **Nothing in the codebase splits a section.** `app/Actions/
ServiceReview/` offers `MergeAdjacentServiceSections`, which joins two sections
into one, and has no inverse. `SaveServiceSection` is the only action that moves
a boundary at all, and it refuses this case three times over: it rejects any
`end_time` change on a section that is not a children's talk
(`SaveServiceSection.php:117`), requires the inclusive children's-talk candidate
to have been prepared first (`:123`), permits only shortening (`:129`), and
rejects a published section outright (`:133`).

So the two songs cannot be given their own clips today by any supported path,
manual review included. A reviewer looking at #335 can approve it, reject it, or
withdraw the video; they cannot cut it in two.

- [ ] Establish what separating an interval actually entails before estimating it.
  A split is not one write: it creates a second `ServiceSection` with its own
  `section_order`, needs a `ChurchServiceItem` for the second song (which may
  already exist from Email or OpenLP evidence and must be matched rather than
  duplicated), re-derives `review_flags` and OoS alignment for both halves, and
  leaves each half needing its own extraction. Reuse the existing merge action's
  handling of ordering and item ownership as the model for the inverse; do not
  design the split as a bare pair of timestamp writes.
- [ ] Decide whether the split is an operator action, a pipeline decision, or
  both. The evidence that locates the seam is the same evidence either way, but an
  automatic split commits to a cut point on OCR-frame spacing alone, which for
  #335 bounds the seam only to a **139-second window** (664.5–803.5 s, between the
  45% and 80% frames). An operator action can accept a proposed boundary it can
  see; the pipeline cannot. Prefer proposing to a reviewer over cutting
  unattended, and keep `unresolved_multiple_songs` as the hold until a split has
  actually happened.
- [ ] Treat the **four** known intervals as the acceptance set, and keep the
  second song's identity through the split rather than re-deriving it: it is
  already recorded, with its confidence and source, in `additional_song_matches`.

##### P8-Q2/Q9 refreshed — automate stale state, then evaluate framing

The current **254 flagged sections across 131 runs** comprise **206 songs, 21
children's talks, 14 sermons, ten readings and three other sections**. Song match
types within those 206 are **163 confirmed, 28 inferred, 15 unmatched**, with no
nulls. There are **117 cross-type inversion occurrences**, **91 low-confidence**,
29 marker mismatches and eight same-type inversions. Cross-type or low-confidence
affects **194 distinct sections (76.4%)**, not the earlier overlapping “83%”.

**98 sections have cross-type inversion as their sole flag**: **38.6%** of the
flagged-section queue. They remain candidates for policy reconciliation, as P8-Q2
originally said; the closeout's suggestion to treat all inversions as genuine
detector ambiguity is withdrawn. Demoting a flag is not approval of a clip.

There are separately **431 songs and 141 children's talks pending publication
approval**. Song reason occurrences overlap: spoken framing 192; framing exceeds
limit 187; trailing content 99; short clip 41; partial-source corroboration nine;
adjacent same song four; inferred match one. **162 songs have ordinary spoken
framing as their sole reason**, replacing the earlier 72-case snapshot.

The samples explain both sides of this workload. #1074 genuinely contains a
spoken introduction; #3221 is labelled “framing exceeds limit” although the
section excerpt proceeds almost immediately from thanks into lyrics. That is a
candidate false framing diagnosis, not a demonstrated safe recut. #950's low
confidence concerns title inference; #1276's concern is an internal boundary.
One confidence number and one “confirmed” match cannot settle both questions.

- [ ] Preview/reconcile the 98 policy-only cases without rerunning the matcher
  or changing song-match authority. Re-evaluate service review state and preserve
  publication restrictions, mixed-song evidence and independent flags.
- [ ] Evaluate the 162 simple-framing cases with timed audio labels. Distinguish
  a short spoken introduction, an instrumental introduction, inter-verse gaps,
  actual singing and absent ASR evidence. Measure false trims and lost lyrics,
  not merely the reduction in pending approvals.
- [ ] Preserve children's-talk approval while automating preparation: boundary
  excerpts, mixed-speaker warnings and uncertain tail evidence. #4135 includes a
  subsequent prayer with personal pastoral details; #2915 includes transition to
  the next hymn. A correctly labelled talk is not automatically a suitable clip.
  Speaker identification remains paused under P8-Q4.

##### P8-Q3/Q6 refreshed — the frozen-video decision is miscalibrated

Final linked-sermon assessments: **289 approved, 26 frozen rejections, eight
mostly-black rejections, 83 missing-file/unassessed**. Four of four deliberately
sampled frozen rejections (#1241, #1164, #1115, #924) show posture/head/arm changes
between the sampled frames, including the three-second pair. Together with the
earlier #1167 this warrants calibration across camera layouts, not individual
override clicking. It does not establish that every rejected video is usable.

The two sampled mostly-black outputs behave differently: #1206 shows an EOS
webcam disconnected screen; #931 is black at all three positions. Keep these
negative examples. Missing-file cases #1059 and #977 both yielded ordinary
speaker frames from their saved quarantine videos. Approved examples #1298 and
#919 yielded plausible speaker frames too. These checks validate file access and
counterexamples, not whole-recording playback quality.

- [~] Prioritise owner-disk assessment recovery and frozen-metric calibration
  together. Evaluate local/region motion and noise tolerance with genuine freezes,
  still slides, lectern shots, home recordings and dark services. Do not just
  disable the gate or approve all 26 rejected files.
  **Owner-disk recovery is DONE 2026-09-08; frozen-metric calibration is not.**
  Splitting them was the right call in the end: recovery was a disk-resolution
  bug with a decisive reproduction, whereas calibration needs a fixture set, and
  recovery has now enlarged that set rather than settling any of it.
- [x] Reassess affected saved assets after the shared fix; retain previous
  evidence and compare decisions. Unsupported quality verdicts should not create
  a permanent manual backlog when evidence can be fetched automatically.
  **DONE 2026-09-08.** No previous verdict was overwritten -- only the 81
  missing-file rows changed -- and a settled verdict is now protected from being
  replaced by an unreadable file.

##### P8-Q3 closed 2026-09-08 — the disk was ambient state, not the file

**The defect was a single line.** `AssessSermonVideoQuality` resolved
`config('media-processing.storage.sermon_disk')` instead of the sermon's own
`asset_disk`. A historic batch *rebinds* that config to the staging volume for
the duration of the run, so the job asked whether the video was on
`historic_staging` for assets whose bytes were on `historic_quarantine`.
`Storage::exists()` answered false, and false was written down as a verdict.
Reproduced directly before any change: for sermon #977,
`config('…sermon_disk')` resolves to `historic_staging`, `exists()` there is
false, and `exists()` on `historic_quarantine` is true.

`Sermon::assetDisk(?string $fallback)` now owns that rule, mirroring
{@see ServiceSection::extractedAssetDisk()}. `SermonAssetController` and
`SermonStorageService` -- which already resolved correctly, each with its own
copy -- delegate to it with their existing fallbacks, so three copies became one
with no behaviour change.

**All 81 missing-file rows are recovered.** The bounded replay
(`sermons:assess-video-quality --reason=missing_video_file`) reassessed every
one against its owning disk: **67 approved, 11 mostly black, two frozen, one
`analysis_failed`**. The linked-sermon corpus is now **403 approved, 29 frozen
rejections, 21 mostly-black rejections, two unassessed** -- no missing-file rows
at all. The 13 new rejections belong to P8-Q6's calibration, not to this item;
nothing about missing-file repair says a video is good.

**The staging exception no longer exists in the data.** The single
`historic_staging` missing-file row from the original census is gone; all 81
were `historic_quarantine`. Separately, no thumbnail regeneration was warranted:
all 81 are `publication_state = quarantined`, and `shouldGenerateVideoThumbnail`
requires *published*, so the withheld thumbnails were a publication consequence
rather than a quality one. A correct verdict unblocks that path at promotion
time; nothing needed backfilling now.

**#1005 is a genuine evidence failure, correctly labelled.** Its
`quarantine/sermons/1005/video.mp4` is 1,114,112 bytes with no `moov` atom --
ffprobe rejects it outright. It was written at 06:14 on 2026-09-06, inside the
staging drive's flapping window, so it is a casualty of the faulty USB link
rather than of disk resolution. `analysis_failed` is the truthful verdict and it
is distinguishable from both `missing_video_file` and an editorial rejection.

**Two guards now separate access failure from judgement.**

1. *Unreachable disk.* `MediaDiskReachability` probes the owning disk's root
   before assessment. When a local disk is not mounted the job writes nothing at
   all and logs why, so a detached drive can no longer convert a batch of good
   assets into missing-file verdicts -- the failure mode that produced this item.
   The probe is deliberately read-only, unlike `HistoricStagingReachability`,
   which must prove writes: a read-only archive mount serves its files perfectly
   well and should not be refused.
2. *Settled verdict outranks an unreadable file.* `missing_video_file` may now
   only be recorded where nothing was ever assessed. Where an earlier run did
   read the file and reached a verdict, its later absence is a custody problem
   and the verdict it produced is still the best evidence anyone has; overwriting
   it destroys evidence that re-running cannot recover, because the file is
   exactly what is missing. This is not hypothetical -- see the thirteen below.

The backfill command gained `--reason=` so recovery can be aimed at one class of
failure, and now reports deferrals separately so a run over a detached volume
cannot read as *n* successful assessments.

###### What a full read of the corpus then showed

Every linked sermon's stored video was probed with `ffprobe` (455 rows, read-only).

- **441 probe cleanly. One is unreadable (#1005, above). Thirteen have no file
  at all**: #842, #851, #858–#867 and #870, every one recorded on
  `historic_staging`, whose `sermons/` directory does not exist -- and their
  audio is gone too. All thirteen are **published** weekly sermons dated 2023 to
  2026, eleven `approved` and two `rejected`, and none of their assets is present
  on any local disk. Per *This machine is separate from production*, that is a
  fact about this workstation, not about the recordings. **They were deliberately
  left alone**, and guard 2 above is what stops a corpus-wide `--all` pass from
  demoting those eleven approvals to a missing-file state.

###### Two new defects this exposed — P8-Q13

Both are span/duration questions rather than quality-gate questions, so they are
recorded here and **not** repaired under P8-Q3. Both were measured across the
whole corpus rather than inferred from samples.

- **A delivered video need not cover its own sermon.** Comparing each sermon's
  video against its own extracted audio (439 pairs, both probed), **69 differ by
  more than two seconds**. Most of that is container rounding -- 26 sit between
  2.0 s and 3.0 s. The tails are real: nineteen videos are *shorter* than their
  audio, worst **#1135 at −367 s** (six minutes of sermon absent from the
  delivered video), then #1076 −258 s and #1197 −132 s; and several are *longer*,
  **#1100 at +210 s**, #1070 +104 s, #1122 +52 s. Every one of the large cases is
  currently `approved`: the quality gate samples frames and has no opinion about
  whether the span is the sermon. #1135's audio is 1916.6 s, matching its
  recorded duration exactly, while its video is 1549.3 s.
- **`sermons.duration` can disagree with both assets.** Forty-six rows differ
  from their own audio by more than two seconds; seven of those are large and all
  seven fall in **#871–#891**, up to **889 s** (#881: recorded 3298.7 s, audio
  2409.8 s, video 2411.8 s -- the column is the outlier, not the media). That
  range predates the 2026-09-04 FFmpeg output-seek change, which is a lead rather
  than a demonstrated cause.

- [x] P8-Q13a: decide what a video that does not span its sermon means for
  publication, and whether the check belongs at extraction, at assessment or at
  promotion. Measure against the sermon's own audio, not against
  `sermons.duration`, which the second finding shows is not reliable for this.
  **ROOT CAUSE FOUND 2026-09-08; prevention landed, repair not run.** The answer
  is none of the three: see below.

###### P8-Q13a resolved — the stored video is stale, not mis-cut

The span comparison was measuring a symptom. **The sermon video on disk is not
the video its own run extracted.** For #1135 the run's `trim.observed_duration`
is **1916.83 s** and the file is **1549.27 s** — and 1549.27 s is exactly what
the *first* extraction of that run produced, on 2026-09-05, before the run was
retried on 2026-09-07 with corrected bounds. The stored file is byte-for-byte the
first cut: 211,514,694 bytes, the `source_size_bytes` logged on 09-05.

The sequence, from #1135's own log:

1. **09-05 06:32** — extracted `concat_spans`, 1840.0→1968.97, 1549.08 s. Video
   stored as `sermons/1135/video.mp4`; audio 1549.08 s.
2. **09-07 01:19** — re-extracted `single_span`, 2177.34→4093.87, **1916.53 s**.
   New audio written over the same permanent path. New video cut to temp.
3. **09-07 01:23** — `Historic sermon video storage already completed, skipping
   duplicate dispatch`. **The new video was never stored.**
4. **09-07 07:30** — quality assessment approves the *stale* video.
5. **09-07 10:35** — promotion copies the stale video and the new audio to
   quarantine, in the same operation, one minute apart from nothing.
6. **09-08 09:43** — the transcript is repaired to the *new* spans.

So the sermon's audio, transcript, duration and structure all describe the second
cut, and only the video describes the first.

**The guard is `SubmitToProcessing::dispatchSermonVideo()`:**
`$nestedJob->state === 'completed' && ! $this->processingLog->isReExtraction()`.
`StoreSermonVideo::prepareHistoricNestedJob()` repeats it. Both read *the store
job ran once* as though it meant *the stored video is current*. Only an operator
calling `ProcessingRunOrchestrator::reExtract()` or structure re-detection ever
raised `isReExtraction()`; an ordinary retry that resumes at the extraction phase
re-cuts the sermon just as decisively and raised nothing.

**The correlation is exact.** Comparing every stored video against its own run's
`trim.observed_duration`: **44 of 425 disagree by more than a second, and all 44
are sermons that hit the skip guard.** No stale video failed to hit it and no
other cause produced one. 85 sermons hit the guard in total; the other 41 re-cut
to the same span, so their stale copy is still the right one. 24 of the 44 hold a
video **shorter** than their sermon (#1135 −367.6 s, #1076 −257.5 s, #1197
−132.8 s, #1102 −73.0 s, #1107 −70.3 s) and 20 hold one that runs **past** it
(#1100 +210.1 s, #1070 +103.0 s, #1122 +50.9 s).

The full 44: 1060, 1063, 1065, 1066, 1068, 1070, 1073, 1075, 1076, 1082, 1083,
1086, 1087, 1088, 1091, 1093, 1097, 1099, 1100, 1101, 1102, 1104, 1107, 1108,
1113, 1114, 1116, 1117, 1118, 1119, 1120, 1121, 1122, 1123, 1128, 1129, 1130,
1133, 1135, 1136, 1138, 1140, 1141, 1197.

**Prevention landed 2026-09-08.** The check belongs at **extraction**, because
extraction is what invalidates the stored video, and the whole re-cut path
already exists and works — `isReExtraction()` carries a run past both store
guards, past `organizeVideoFile()`'s overwrite refusal and into promotion's own
authority. What was missing was only that a retry never raised it. So
`StoreSermonVideo` now records `stored_video.observed_duration` — which cut is
actually on disk — and `ExtractSermon` compares its fresh probe against that and
raises the flag itself when they differ by more than 0.5 s. Nothing else changed:
no new guard, no new refusal, no new operator step.

Assessment was the wrong home: it samples frames and would have to be told the
expected span from outside. Promotion was the wrong home too — by then the stale
video has already been approved, and refusing there strands a finished run.

- [ ] **Repair the 44 — not run, needs authorisation.** `sermons:re-extract`
  already sets the flag explicitly, so each is a supported re-cut rather than new
  machinery. **30 of the 44 still have their source recording; 14 do not**
  (1065, 1066, 1073, 1075, 1076, 1088, 1097, 1100, 1102, 1107, 1108, 1120, 1121,
  1123) and would need restaging from the Sonnics archive first, byte-identical.
  Note the legacy fallback in `storedSermonVideoDuration()` cannot help these
  44: their `trim` block already describes the *new* cut, so an ordinary retry
  would compare new against new and leave the stale video in place. The explicit
  re-extract path is the instrument for them.
- [ ] P8-Q13b: establish which writer sets `sermons.duration` for the #871–#891
  range and whether the stale value has downstream readers. Do not "correct" the
  column from the media until that is known; the divergence is itself evidence.

##### P8-Q11 — metadata reconciliation needs provenance and freshness

Sample #919's saved reference is **Joshua 13:1–33**, while banked analysis says
**Joshua 13:1–14:5**, consistent with the opening's announced reading into chapter
14. #1129 says **Luke 13:6–9**, yet most sampled exposition concerns verses 1–5;
its opening also explicitly announces 6–9. This is conflicting evidence, not an
automatic substitution opportunity. By contrast #1193 legitimately focuses on
Job 1:13–22 after reading the full chapter. #964 deliberately visits several
Genesis passages. A single “reading equals sermon reference” rule would regress
these valid cases.

- [ ] Track reading passages, preached focus and supporting references distinctly
  in evaluation. Surface cross-stage disagreement with its evidence and field
  provenance. Preserve curated authority; accept automatic changes only where a
  tested rule establishes the field is derived and the supporting evidence fits.
- [ ] Keep P8-Q7's duplicate/date candidate (#1296/#1297) open: the sampled texts
  again closely match. Use source/audio identity evidence to propose duplicate
  groups; let the operator settle conflicting dates. Similar titles or shared
  Bible passages alone are insufficient to deduplicate sermons.

##### P8-Q12 — replace repeated operational interventions with targeted recovery

The **60 transcript-span repairs and #972/#1087/#1005 reanalyses are operationally
closed**, as the closeout records. The software recovery gaps remain:

- [ ] Persist analysis input hash/version and desired/completed state. Reconcile
  missing effects after queue loss or interrupted dispatch; text-only repair must
  still permit subsequent analysis. Empty queues and a cleared degraded flag
  cannot prove that the intended version was analysed.
- [ ] Isolate Dusk's Redis database/connection or instance from application queues.
  Prove a queue sentinel survives test cache clearing. Replace “remember not to
  run Dusk” with isolation; do not execute that experiment against live queues.
- [ ] Provide supported evidence-directed retry: invalid transcript → regenerate
  transcript and affected dependants; unavailable asset → reacquire/reassess;
  unchanged rejected evidence → bounded investigation rather than blind retry.
  This should eliminate operational file renames and manual `current_step` edits.
- [ ] Separate import/source consumption from structure supersession. The current
  rank uses confirmed-song and high-confidence **section counts**, not duration
  coverage. Do not reward fragmentation or reopen consumed sources just because
  a failed run wins projection. Test replacement/retirement separately.
- [ ] Before escalating the three 20-minute-threshold holds, evaluate transcript-
  grounded sermon candidates. “No block above 20 minutes” is not “no sermon”; five
  qualifying RMS blocks are not necessarily five sermons. Recover evidence first,
  then reserve human decisions for remaining genuine ambiguity.

For #1005, correct the earlier mechanism: the analysis loader supplies `asset_disk`
but the storage service also searches generic candidates; run-specific staging
context/ownership is the gap. An empty transcript throws **before** the analysis
API call, so unchanged missing-file retries do not themselves incur analysis spend.

##### Delivery order and proof of improvement for weekly processing

1. **Prevent silent bad outputs — DONE 2026-09-08.** P8-Q8 incomplete-evidence
   handling and P8-Q10 mixed-song publication. **Gates landed 2026-09-07**; the
   recovery they could not perform was executed on 2026-09-08. The replay
   re-applied region-wise recovery to all 146 affected runs from banked retries
   (5.52 h of blind time recovered, +35,125 words, no provider call), structure
   was re-derived for 19 of the 21 runs the voids had damaged (+60 min of sermon,
   sections 231→253), and **run #1035 closed** — 1 section to 16, a whole carol
   service with its sermon. P8-Q10's remaining work is a **capability, not a
   recovery** (see item 4); no production exposure was ever demonstrated.
2. **Remove deterministic operational work:** P8-Q12 freshness/retry/queue isolation,
   P8-Q2 scoped policy reconciliation, ~~P8-Q3 owning-disk recovery~~ (**DONE
   2026-09-08**) and P8-Q6 calibration.
   **This is now the leading edge.** P8-Q2's demotion backlog is **DONE
   2026-09-08** (`7c55d99e4`): 99 sections / 46 services applied, taking the queue
   from **329 sections across 173 runs to 232 across 153**, idempotent on re-run.
   96 were the `structure_oos_cross_type_inversion` sole-flag demotions the policy
   has always made. The other three were found only by enumerating the pass **by
   write shape** rather than trusting its count — a residue where the spoken-
   announcement retype stripped one song-alignment flag and left the rest on a row
   that was no longer a song. Note the shape of the two reconcilers before relying
   on either: `services:rederive-structure-review-flags` can re-weigh a flag but
   never withdraw one, and `services:recompute-section-review-flags` could withdraw
   but never notice residue on a row it had already quietened (now fixed).
   **P8-Q12 and P8-Q6 remain; P8-Q3 closed 2026-09-08**, and P8-Q13 opened from
   what its corpus-wide read exposed.
3. **Learn boundaries and metadata:** P8-Q9 framing, P8-Q11 passage reconciliation,
   P8-Q7 duplicates and P8-Q4 speaker bucketing. Preserve uncertain cases for review.
4. **New capabilities, separately scoped and not blocking.** Splitting a service
   section so two songs in one interval can each hold a clip (P8-Q10's residue —
   design recorded under *Separating the interval is not something the application
   can do*), and **production reconciliation**, which is its own body of work: this
   machine is completely separate from production, the weekly pipeline has never run
   consistently there, and what production holds has not been read. Neither is a
   precondition for anything in items 1–3, and neither should be planned as
   remediation of a historic output.

- [ ] Establish a reusable labelled set from this corpus, including apparently
  clean outputs as well as each failure class. Label actual source/output audio
  around starts, endings and joins; listen in full where completeness is disputed.
  Record section identity/count, sung boundaries, sermon completeness, reference
  support, summary support, video usability and speaker ambiguity separately.
- [ ] Keep calibration and held-out services separate, grouping duplicates and
  related recordings together to prevent leakage. Include all eras but report
  modern full-service performance separately; then validate prospectively on
  successive routine uploads with existing review safeguards still active.
- [ ] Compare each proposed change with current behaviour: false automatic
  accepts, clipped/missing content, missed sections, review precision, correct
  unattended outputs, operator minutes per service, targeted retry success and
  unnecessary provider calls. Report numerator, denominator and remaining unknowns.
  A smaller review queue is a win only if content accuracy is maintained.
- [ ] Retain original reasons and human before/after corrections when review is
  completed (P8-Q5), so the corpus teaches which flags were useful. One maintainer
  can label and adjudicate it; use held-out machine checks rather than a second
  human approval requirement. Do not treat this investigation's unlistened examples
  as gold-standard audiovisual labels.

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

## 4. Current go/no-go summary

**2026-09-08: bulk processing and evidence recovery are complete; deterministic
state work and Phase 9 remain.** Delivery-order item 1 closed on 2026-09-08 with
the recovery replay, the structure re-detection pass and run #1035. Item 2 —
removing deterministic operational work — is now the leading edge.
The old pre-bulk checklist has been replaced because it contradicted completed
work documented above. Its underlying implementation evidence remains in the
dated phase sections. It is not a current instruction to dispatch another pass.

- [x] The bulk queue has drained: 409 completed / four failed active operation-4
  runs; zero degraded completions. This is processing disposition, not accuracy.
- [x] The 60 identified transcript-span repairs and their reanalyses are complete
  according to the closeout; sampled repaired outputs contain substantive analysis.
- [~] Recover incomplete sermon evidence and refresh affected output/analysis
  (P8-Q8), including the newly identified false completion #1148. The **gate is
  in place** — a sermon span ≥ 40% blind now fails before the analysis call and
  ≥ 10% carries `sermon_evidence_incomplete` — measured across all 406 completed
  runs and ranked by blind *fraction*, not seconds. The **recovery is not**: the
  five runs it would stop or flag keep their present disposition, and neighbouring
  gaps (#1195's missing opening) are outside what the gate can see.
  **The reason those spans were blind is now known and fixed**: `recover()`
  discarded an entire retry whenever any region of it looped, throwing away 3,275
  and 2,840 genuinely transcribed words on the two fail-level runs. It now keeps
  the retry region by region. Censused over 130 runs on 2026-09-07: **5.01 h of
  15.68 h of blind time recovered (31.9%), +27,282 words**, both fail-level runs
  to **0%** blind, and sermon #1148 restored from 81 words to 3,324.
  **EXECUTED 2026-09-08** through `historic-import:replay-transcript-recovery`
  across all 146 runs / 202 windows, from the retries the pipeline had already
  banked — no provider call, no ffmpeg, no dependence on the source surviving:
  17.86 h blind → 12.34 h, **5.52 h recovered (30.9%), +35,125 words**, 0 failures.
  Structure was then re-derived for the runs whose boundaries had been drawn over
  the voids, and **run #1035 closed** — a *failed* run whose single prayer section
  became sixteen, yielding sermon #1307 (*Herod's antagonism towards Jesus*,
  Matthew 2:1-12, confidence 0.99). Op-4 failures 4 → 3.
  The 40%/10% thresholds were **deliberately not re-derived**: the fail line's job
  is the catastrophic case and run #1004 still shows it at ~100% blind, so "the
  gate is now inert" is the wrong reading — it stops firing on completed runs
  because the defect that manufactured a 96.5%-blind completion is fixed. Prefer
  the replay's figures to the 2026-09-07 re-transcription census, which measured a
  different question and whose headline numbers are wrong.
- [~] Resolve the **four** demonstrated mixed-song generated clips (P8-Q10).
  **Two shared-pipeline gates are now in place**: `unresolved_multiple_songs`
  (OCR route) and `unlocated_adjacent_song` (printed-order route, added
  2026-09-07 because #306's OCR evidence was empty). That is the part which
  protects weekly processing, and it is done. **#335 and #306 are both withdrawn
  locally**; #1276 and #3869 are untouched but quarantined.
  **Corrected 2026-09-08 — no production exposure was ever demonstrated.** This
  machine is completely separate from production and the weekly pipeline has
  never run consistently there; the database here was built from 2026-05-04
  (every table's oldest row lands in the same instant, all 523 `song_videos`
  postdate 2026-07-06, and total sermon downloads are zero), so these four rows
  are local artifacts. Nor is a song video anonymously reachable at all:
  `PublicSongListController` sits behind `['auth', 'verified']`
  (`routes/web.php:256`), and `publication_state`/`publiclyReleased()` describe a
  column rather than the open internet. Earlier text here read "not reconciled in
  production" — that was **unverified**, not confirmed exposure. Production
  reconciliation is real but is **its own piece of work**, not a tail on this item.
  What remains of P8-Q10 is therefore a **capability, not a repair**: nothing
  splits a section, and `SaveServiceSection` only shortens children's talks. See
  *Separating the interval is not something the application can do* above; that
  work is not blocking and does not belong in delivery-order item 1.
- [~] Reconcile stale review state (P8-Q2). `structure_macro_section` landed
  2026-09-07 and **the rederivation has been executed**: 38 sections across 33
  services now carry it, all 38 requiring review, raising the queue to 332
  sections across 173 runs. The 98 cross-type-inversion-only demotions from the
  earlier census are **not** yet reconciled.
- [~] Reconcile stale review state, recover truthful video assessments, and
  evaluate safe reductions in boundary review (P8-Q2/Q3/Q6/Q9). **P8-Q3 is done**
  (2026-09-08): 81 missing-file verdicts recovered against their owning disks,
  and access failures can no longer masquerade as judgements. P8-Q2, P8-Q6 and
  P8-Q9 remain, and P8-Q13 is new.
- [ ] Resolve source identity, remaining evidence failures and editorial metadata;
  speaker identification remains paused while its corpus/rebuild work proceeds.
- [ ] Complete Phase 9's exact membership, asset, visibility and acceptance checks.
  Public release still requires its separate operator authorisation.

Prioritise shared weekly-processing improvements and targeted historical repair.
Do not declare release readiness from completion counts or reduce review by
silently accepting unknown content.
