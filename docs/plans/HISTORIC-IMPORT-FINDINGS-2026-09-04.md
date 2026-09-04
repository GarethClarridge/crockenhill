# Historic import — findings, 4 September 2026

**Status:** Phase 8 is unblocked and its governing constraint has been removed. The
working mount now lives on a dedicated staging drive; the review-obligation ceiling
that D6 was deferred on no longer exists. Two measurements settle open questions —
one closes the VirtioFS option, one opens a cost nobody has priced.

Plan of record remains
[`HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md`](HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md);
decisions remain
[`HISTORIC-IMPORT-DECISIONS-2026-09-03.md`](HISTORIC-IMPORT-DECISIONS-2026-09-03.md).
This record supersedes both where they disagree on disk capacity.

---

## 1. Where the work stands

Everything before Phase 8 is closed. M1–M12, the eleven-step operator sequence,
pass-1 remediation (P1-1…P1-4) and the 3 September decisions (D1, D2/D3, D4, D5, D7)
are all implemented and merged — `historic-import-decisions-2026-09-03` is **0 commits
ahead of master**. #978 is completed and its occasion confirmed.

| | |
|---|---|
| manifest entries | 474 (464 `include`, 10 `exclude`) |
| identities completed | **50** |
| **remaining** | **414** |
| historic runs | 55 — 50 completed, 5 failed (all 5 retired) |
| degraded completions | **0** |
| runs by operation | op2 6✓/3✗ · op3 28✓/2✗ · op4 16✓/0✗ |

Phase 8 (bulk pass loop) and Phase 9 (convergence and public release) are both
**not started**. D6 — pass 2 size and timing — was the only undecided item, deferred
on a constraint that §3 below removes.

---

## 2. The staging drive cutover — done and verified

### What changed

Two lines in `.env`, backed up to `.env.backup-before-staging-drive-cutover-20260904`:

```diff
- CBC_HISTORIC_WORK_PATH=/Volumes/Sonnics/HistoricWork
+ CBC_HISTORIC_WORK_PATH=/Volumes/Staging/HistoricWork
- MEDIA_PROCESSING_TEMP_DISK_UNMEASURABLE=true
+ MEDIA_PROCESSING_TEMP_DISK_UNMEASURABLE=false
```

`historic_staging`, `historic_temp` and `historic_quarantine` all resolve under
`/mnt/historic-work`, so all three moved together. `/Volumes/Staging` is 1.8 TiB
APFS; `/Volumes/Sonnics` is 1.8 TiB exFAT and now holds only the read-only source
corpus.

### The cutover is host-side only

The **container** path `/mnt/historic-work` does not change — only the host side of
the bind mount does. Every path column in the database is disk-relative:
`source_file_path`, `video_file_path`, `audio_file_path`, `transcript_file_path`,
`rms_log_path` and `service_sections.extracted_video_path` all returned **zero** rows
matching `/Volumes/%` or `/mnt/%`. The graph never sees the move.

It requires `up -d --force-recreate`, not a restart: Docker only re-reads bind-mount
sources at container creation.

### Copy verified before cutover

All six subtrees matched on file count **and** total bytes:

| subtree | files | bytes |
|---|---|---|
| calibration-corpus | 19 = 19 | 11,697,199,825 |
| quarantine | 466 = 466 | 37,019,347,376 |
| reclaimed-20260826 | 962 = 962 | 56,937,490,590 |
| staging | 433 = 433 | 52,941,637,047 |
| temp | 3 = 3 | 36,619,812 |
| p | 1 = 1 | 2 |

Path-and-size is the right bar: it is the same standard decision 9 adopted for custody
when it removed routine hashing. A concurrent `rsync -rcn` in another session
independently checksum-verified the same trees.

### Verified after

| check | before | after |
|---|---|---|
| container `df /mnt/historic-work` | 384G avail | **1.7T avail**, 151G used |
| `disk_free_space()` in app | 383.3 GiB | **1,712.6 GiB** |
| `TempDiskSpace::checksDisabled()` | `true` | **`false`** |
| asset graph (`sermons` + `song_videos`) | — | **258 files, 0 missing, 31.7 GiB readable** |

A live bounded dry run is the real proof:

```
Pass needs: 32.2 GiB free (20.0 floor + 4.3 selected inputs + 7.9 concurrent FFmpeg transient)
Staging free space: 1,712.9 GiB
Historic-video curation preflight passed.
Manifest hash: d25d2085…   Plan hash: 9351fa4e…   Errors: 0
```

Manifest and plan hashes unchanged, so the frozen binding survived the move.

> **Probe trap, recorded so it is not repeated.** A first asset-resolution probe
> reported 47 missing files. It was treating `sermons.filetype` — values like `mkv`,
> `webm` — as a path column. It is not one. The true count is 0 missing.

### The "unmeasurable volume" declaration was stale

`HistoricStagingHeadroom`'s docblock records why the flag was ever set: under
**VirtioFS**, `df` inside the container reported the host boot volume instead of the
bind mount, so every capacity gate stood down silently and a wrong number reached a
plan. Under **gRPC FUSE** the mount reports truthfully — `disk_free_space()` returned
383.3 GiB against the host's `df` of 383 GiB while still on Sonnics.

The switch to gRPC FUSE was made on 2026-09-02 for an unrelated reason (the exFAT
write fault) and **incidentally restored measurability**. Consequence:
`--host-capacity-evidence` JSON is no longer needed on dispatch, and the gate does
real arithmetic instead of computing a requirement it was never allowed to act on.

---

## 3. The Phase 8 ceiling is void

The `~42 unreviewed runs in flight` limit, and the `~19 pass-2 slots` derived from it,
both came from Sonnics headroom. On the staging drive:

```
414 remaining identities, none ever reviewed:
  pinned staged source   414 × 2.20 GiB  ≈   911 GiB
  durable quarantine     414 × 0.70 GiB  ≈   290 GiB
                                            ─────────
                                          ≈ 1,201 GiB   against 1,740 GiB free
```

**The entire remainder can be processed without settling a single review**, with
~540 GiB spare. Review throughput becomes an editorial backlog with no dispatch
consequence, and `HistoricReviewSourceReclaimer` holding source is now free rather
than a bottleneck. This is what unblocks D6.

Sonnics is relieved too: moving `HistoricWork` off takes it from 383 → ~533 GiB free.

**Not done, deliberately:** the Sonnics copy is untouched and is the rollback. Delete
nothing — including the 65 GiB of dead weight that came across (`reclaimed-20260826`
54 GiB, `calibration-corpus` 11 GiB) — until a pass has run clean on the new drive.

---

## 4. VirtioFS — measured, rejected

The premise was that VirtioFS is faster on the FFmpeg stages and might be safe again
now the writable mount is APFS. Measured on an **idle** drive, 1500 MiB per sample:

| path | host direct | container (gRPC FUSE) | overhead |
|---|---|---|---|
| exFAT cold read | 38.2 MiB/s | 36.8 MiB/s | 3.7% |
| APFS write | 158.2 MiB/s | 160.4 MiB/s | none |
| exFAT → APFS cold copy | — | 32.7 MiB/s | read-bound |

**gRPC FUSE is essentially free. The bottleneck is the exFAT USB drive at ~38 MiB/s,
not the file-sharing layer.** VirtioFS could win at most ~4% on the read path,
against re-introducing the write-then-reopen fault, losing the `df` measurability
restored in §2, and invalidating every pass-1 stage timing behind the width-one
decision. **Decision: stay on gRPC FUSE. No Docker restart was performed.**

gRPC FUSE also keeps a separate operational win: the VM no longer pins the volume, so
`diskutil unmount` works without quitting Docker Desktop.

### The contention trap that nearly reversed this

The first measurement showed the container at **9.0 MiB/s** against the host's
**38.5** — an apparent 4.2× tax that argued hard for switching. It was contention. A
`rsync -rcn --exclude=._* /Volumes/Sonnics/HistoricWork/ /Volumes/Staging/HistoricWork/`
in another session was checksumming both drives; load average was 10.48. On an idle
drive the same probe gives 36.8.

**An empty Laravel queue is not an idle machine.** The queue tables were genuinely
empty — they cannot see host processes. Check `uptime` and `ps -Ao pcpu,etime,pid,comm -r`
before trusting any throughput number.

> A second trap in the same probe: `dd bs=1m` (lowercase) is invalid in GNU dd, and
> with stderr silenced the failure surfaced as empty variables that shifted awk's
> positional fields, so it computed on epoch timestamps and printed a plausible
> `1706 MiB`. Never silence stderr in a measurement script.

### The floor this sets

~911 GiB of source to stage at ~38 MiB/s = **~6.8 hours** of unavoidable copying for
Phase 8, whatever else is tuned. The lever, if it is ever needed, is the drive's USB
interface — not Docker, not worker width. Staging is read-bound: the APFS destination
writes 4.3× faster than the exFAT source reads.

---

## 5. Finding: the song boundary gate holds 34% of assessed clips

The plan states the M5 leading-framing false-positive rate "must be read off the
Phase 7 canary re-run". **That measurement never happened** — the step-10 re-run was a
no-op replay (0 B processed, 7.2 s), which by construction produces no new boundary
evidence. The data exists anyway: 38 song sections carry `song_publication_boundary`
from the op4 runs.

| decision | count |
|---|---|
| `release_eligible` | 25 |
| **`review`** | **13 (34%)** |

Risk kinds among the 13: `song_boundary_spoken_framing` 8,
`song_boundary_spoken_framing_exceeds_limit` 5, `song_boundary_trailing_content` 3.

All 13 sit at `publication_status = pending_approval` with the inclusive clip
retained and `needs_manual_review = 0` — so the fail-closed posture works correctly,
and they are held in the approval queue rather than the review queue.

**Why this matters.** 34% is well above the 16.7% section-level review rate the plan
itself rejected as too costly. §935's evidence reads *"spoken framing followed by an
audio-backed wordless gap **1.0s** into the candidate"* — precisely the false positive
the plan predicted, where a hymn's singing starts at the boundary and an early
inter-verse pause is read as framing. §938 is the opposite shape and probably genuine:
first gap at 145.9 s, beyond the 30.0 s limit.

Extrapolated across 414 identities this is several hundred held clips. **It is
measurable today from completed runs, needs no canary and no provider spend, and
should be read before D6 sizes pass 2** — otherwise the pass is sized against a review
cost nobody has priced.

Song section totals for context: 254 overall — 128 `published`, 107 `not_applicable`,
19 `pending_approval`.

---

## 6. Review queue census

54 sections carry `needs_manual_review`, across 31 distinct runs of which **20 are
historic**.

By section type:

| section type | count |
|---|---|
| song | 19 |
| childrens_talk | 16 |
| bible_reading | 11 |
| sermon | 7 |
| other | 1 |

By flag:

| flag | count |
|---|---|
| `oos_structure_mismatch` | 14 |
| `structure_low_confidence` | 14 |
| `childrens_talk_speaker_review` | 12 |
| `unmatched_song_section` | 8 |
| `song_alignment_inferred` | 5 |
| `structure_micro_section` | 3 |
| `structure_missing_preached_reading` | 2 |
| 5 singletons | 1 each |

The five singletons: `heuristic_demotion`, `song_title_marker_mismatch`,
`structure_oos_cross_type_inversion`, `structure_oos_same_type_inversion`,
`structure_sermon_boundary_material_risk`.

`media:cleanup-temp-files --dry-run` reports **29 files pinned** by unresolved
obligations and **498.93 MB** newly reclaimable.

---

## 7. Record defect: the go/no-go checklist is stale

The go/no-go summary in the plan of record still shows **7 unticked boxes** — M1, M3,
M4, M6, M10, M11 and the performance report. Those findings were all closed on 31
August and 1 September. Nobody went back and ticked the list, so the plan reads as
considerably more blocked than it is. Tick them, or mark the section superseded.

---

## 8. Smaller open items

- **Queue workers** were all `Exited (128)` from the 3 September drive detach. All
  seven containers are now recreated and running, which also clears the stale-code
  problem they would otherwise have had after the recent merges.
- **Staging-guard depth/leak root cause** — D7 instrumented it
  (`HistoricStagingGuard::leakedActivationEvidence()` is live) but the underlying leak
  is unfixed. Survivable; strands nothing.
- **`2026-05-10-morning`** section-17 overlap was listed as needing an operator look.
  `ServiceStructureValidator::checkChronology()` is now narrowed to gross containment,
  so the identity is probably unblocked — re-attempt rather than review it.
- **#959** (`2023-07-16-morning`, 405 s against a 64.8-min morning median) is retired,
  but whether the *identity* is reprocessed or excluded at Phase 8 is still open.
- **Run #909** (`Sunday 28th June 2026.mp4`, non-historic) has sat at
  `preparing_section_publication_candidates` since 2026-07-20. Six weeks stale, not
  moving. 65 rows in `failed_jobs` are a pre-existing backlog.

---

## 9. Recommended order

1. **Measure the boundary gate (§5).** Classify the 13 held clips as genuine or
   spurious against their transcripts. Cheap, local, no provider calls, and it is an
   input to the next step.
2. **Decide D6** with §3's capacity and §5's real review cost in hand.
3. **Run Phase 8**, budgeting the ~6.8-hour staging floor from §4.
4. **Tick or supersede the go/no-go list (§7)** so the plan of record stops
   misreporting.
5. Keep the Sonnics copy until a pass has run clean on the new drive; only then
   reclaim its 150 GiB.
