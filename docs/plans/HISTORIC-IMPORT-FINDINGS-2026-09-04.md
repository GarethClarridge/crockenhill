# Historic import — findings, 4 September 2026

**Status:** Phase 8 is unblocked and its governing constraint has been removed.
§10–§12 (added later the same day) settle the boundary-gate question, record the
pre-run baseline, and correct the worker state. **Read §10 and §12 before dispatch.** The
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

> **Resolved by §10.** The 13 held clips have since been read against their
> transcripts: 11 are genuine, 2 are spurious (84.6% precision). This section's
> expectation that they would prove largely false positives was wrong, and its
> framing of 34% as a cost to engineer down does not survive the measurement.
> Read §10 before acting on anything below.

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

1. ~~**Measure the boundary gate (§5).**~~ **Done — see §10.** 11 of 13 holds are
   genuine (84.6% precision); the gate stays as it is. Optional one-constant
   improvement: a 3 s minimum framing floor, which removes both false positives and
   loses no genuine hold.
2. **Decide D6** with §3's capacity and §10's measured review cost in hand — roughly
   118 genuine recuts and 21 spurious holds across the 414, none of which gate
   dispatch.
3. **Run Phase 8**, budgeting the ~6.8-hour staging floor from §4.
4. **Tick or supersede the go/no-go list (§7)** so the plan of record stops
   misreporting.
5. Keep the Sonnics copy until a pass has run clean on the new drive; only then
   reclaim its 150 GiB.

---

## 10. The song boundary gate is right, and must not be relaxed

§5 recommended classifying the 13 held clips before D6 sizes pass 2, and predicted
they would prove largely spurious. **They do not.** All 13 were read against their
service transcripts. Eleven are genuine; two are not.

### Method

Each held section's `song_publication_boundary` evidence was replayed against the
banked `*.normalized.json` service transcript, printing every cue from before the
candidate start through the flagged gap. All 13 transcripts were readable. The
question asked of each was simply: *between the candidate start and the first sung
line, is someone talking?*

### Result

| § | song | speech inside the clip | singing starts | verdict |
|---|---|---|---|---|
| 930 | I Hear The Words Of Love | 26.3 s | 2396.5 | **genuine** |
| 935 | All glory be to Christ | 1.0 s (`"Amen"`) | ~309 | *spurious* |
| 938 | When I Fear My Faith Will Fail | 18.3 s | 915.6 | **genuine** ¹ |
| 957 | I Will Glory In My Redeemer | 37.9 s | 411.8 | **genuine** |
| 960 | Lord I Come Before Your Throne Of Grace | 11.0 s | 1005.3 | **genuine** |
| 965 | The Gospel Of Your Grace | 13.0 s | 3833.4 | **genuine** |
| 1074 | Yesterday, Today, Forever | 10.4 s | 1053.7 | **genuine** |
| 1076 | Facing a task unfinished | 25.3 s | 1215.3 | **genuine** |
| 1080 | Your Word | 18.0 s | 2127.7 | **genuine** ¹ |
| 1082 | To God Be The Glory | 1.2 s | 4393.3 | *spurious* |
| 1087 | Oh How Good It Is | 16.2 s | 636.0 | **genuine** |
| 1089 | How Deep The Father's Love For Us | 33.9 s | 1030.0 | **genuine** |
| 1094 | I Love You O Lord You Alone | 36.2 s | 3875.3 | **genuine** |

¹ Genuine defect, misleading evidence — see "the exceeds-limit variant" below.

**Precision 11/13 = 84.6%.** The gate is holding real defects, not manufacturing
work. Releasing these clips as-is would publish between 10 and 38 seconds of a
preacher talking at the head of a song recording.

§935 — the case §5 cited as "precisely the false positive the plan predicted" — is
indeed spurious, but it is one of only two. It was reasoned from before the
corpus-wide rate was known, and the corpus-wide rate turns out to point the other
way.

### Why the framing is so long: it is a house style, not noise

The transcripts show a consistent Crockenhill pattern. The service leader announces
the song, often names its hymn-book number, and frequently **reads the first verse
aloud** before anyone sings:

> §930 — *"We are going to sing our final hymn now. I am not sure that I have sung
> this hymn before, but I know the tune… and the words seem so appropriate. The
> first verse reads like this: I hear the words of love…"* — 26 s, then a 3.7 s
> gap, then the congregation sings the same words.

> §957 — *"We're going to stand and sing two songs back to back. But before we do
> that, let me read to you from Ephesians chapter 1 and verse 7…"* — 38 s.

This matters for sizing: the framing is not incidental transcription noise that a
threshold tweak will absorb. It is a recurring liturgical habit, so the gate will
keep firing at roughly this rate across the remaining 414 identities, and it will
keep being right.

### The actionable fix is a floor, not a higher ceiling

Ranked by the gap offset the gate already records:

| offset | sections | verdict |
|---|---|---|
| 1.0 s, 1.7 s | 935, 1082 | both *spurious* |
| 11.3 s – 38.8 s | eight sections | all **genuine** |
| 140.8 s, 145.9 s | 1080, 938 | genuine defect, wrong evidence |

Both false positives sit at **≤ 1.7 s**; the smallest genuine framing is **11.3 s**.
The separation is a clean order of magnitude with nothing in between. A **minimum
framing floor of ~3 s** removes both false positives and loses no genuine hold.

The existing 30 s *maximum* does no useful separating work: genuine framing measured
1.0–37.9 s, and three genuine cases (957, 1089, 1094) sit above 30 s. The limit only
relabels long genuine framing under a scarier risk kind. Raising it would be
cosmetic; lowering it would be harmful.

### The exceeds-limit variant hides a second defect

§938 and §1080 are flagged on gaps **140.8 s and 145.9 s** into the candidate —
deep inside the song, at an inter-verse break, not at any framing boundary. Both
clips nevertheless *do* carry genuine spoken introductions (18.3 s and 18.0 s).

The cause is structural: the gate locates the end of framing by looking for the
first wordless gap. When the leader's speech runs continuously into the singing with
no pause between them, there is no gap to find, so the search runs on until it hits
the first inter-verse break. The clip is still held — fail-closed works — but the
recorded evidence points at the wrong moment and would mislead a reviewer.

A framing floor does not fix this; it needs a speech-versus-lyric distinction the
gate does not currently have. **Not worth building before Phase 8** — the outcome is
already correct, only the explanation is wrong.

### A caution on reading "wordless gap" as "nobody sang"

Transcript granularity varies enormously across the corpus. §1074's is word-level
(0.2–0.8 s cues); §935's is whole-second and drops the singing entirely, which is
why 29 s of sung worship reads as an empty gap with `active_ratio 0.965`. The RMS
check correctly reports audio present, but "audio present with no cues" means *the
transcriber emitted nothing*, which is not the same as *no one was singing*. The
framing floor sidesteps this, because it keys on the speech before the gap rather
than on the gap itself.

### Consequence for D6

Extrapolating 34% across 414 identities gives roughly 140 held clips, of which — on
this sample — about **118 are genuine recuts and about 21 are spurious**. Adding the
3 s floor removes nearly all of the 21 and leaves the 118 held, as they should be.

This is real editorial work, but it is *correct* work, and it does not gate
processing: every held clip sits at `publication_status = pending_approval` with the
inclusive candidate retained, and none has ever leaked to `published` (verified: 13
of 13 `review` sections are `pending_approval`, 0 `published`). **The queue is a
release backlog, not a dispatch constraint.**

**Recommendation: leave the gate exactly as it is for Phase 8.** Optionally add the
3 s floor first — it is a one-constant change with a clean evidential basis. Do not
relax the 30 s limit, and do not treat the 34% hold rate as a cost to be engineered
down; it is the gate doing its job against a genuine house style.

---

## 11. Pre-run baseline, recorded 2026-09-04

Recorded so post-run state is distinguishable from pre-existing state.

| watermark | value |
|---|---|
| `failed_jobs` rows | **65** (max id **68**) |
| `failed_jobs` span | 2026-05-07 → 2026-09-02 (all pre-date this session) |
| `media_processing_logs` max id | **982** |
| `service_sections` | **981** rows, **54** `needs_manual_review` |
| historic identities completed | 50 of 464 (**414 remaining**) |

Failed jobs by queue: `livestream-processing` 13, `historic-llm` 12,
`historic-ffmpeg` 12, `audio-processing` 10, `historic-whisper` 9,
`video-processing` 6, `historic-orchestration` 3.

Leading exception classes: `UnableToCreateDirectory` 16, `RuntimeException` 12,
`Exception` 10, `MaxAttemptsExceededException` 8, `RateLimitException` 6,
`ProcessFailedException` 4. The 16 directory failures are consistent with the
VirtioFS/exFAT write fault and the stale `/host_mnt` bind, both since fixed; the 6
rate-limit failures are the flex-tier issue closed by P1-3. **Nothing here was
cleared** — the backlog is left intact so the fixes can be judged against it.

## 12. Worker state corrected before dispatch

The workers were running code and configuration that both pre-dated the speaker
merge, which `git status` alone would not have revealed.

Containers were created 00:06:18 and their `queue:work` processes had an ELAPSED of
1 h 57 m — they booted from `.env.backup-before-staging-drive-cutover-20260904`,
which carries `SPEAKER_IDENTIFICATION_ENABLED=true`. Commit `619634926` (which
replaced the "Visiting Speaker" fallback) landed at 01:57:03, and the flag was set to
`false` after that. **Both the fix and the disable were invisible to the running
workers**; a dispatch at that moment would have stamped a fabricated preacher onto
all 414 identities.

All six queue workers were restarted. Verified after: `queue:work` ELAPSED ~21 s,
`media-processing.speaker_identification.enabled` reads `false` inside the worker,
`historic_staging` root still `/mnt/historic-work/staging`, and the bind mount still
reports 1.7 T available. Nothing was in flight (0 queued, 0 reserved; the single
`processing` row is run #909, untouched since 2026-07-20).

> Restart, not recreate: `SPEAKER_IDENTIFICATION_ENABLED` is not injected as a
> container environment variable, so Laravel reads it from `.env` on the bind mount
> and a fresh process picks it up. The bind-mount *source* still requires
> `up -d --force-recreate` to change, as §2 records.

**Consequence worth stating plainly.** With identification disabled, the children's
talk path is disabled too: `ChildrensTalkSpeakerService::predictionPayload()` returns
`outcome: 'skipped'`, and `skipped` is not in `REVIEW_OPENING_OUTCOMES`
(`ambiguous`, `no_match`, `error`), so `detectAndStore()` takes the branch that
*removes* `childrens_talk_speaker_review`. That flag is 12 of the current 54, and the
review-queue survey named children's-talk speakers the biggest recurring cost — so
Phase 8 will generate none of them. The trade is that 414 identities land with no
speaker attribution, to be backfilled once profiles are re-enrolled from
production's ~700 hand-assigned sermons. That is a metadata pass, not reprocessing.

---

## 13. Decisions taken 2026-09-04, and what implementing them turned up

**D6 — sized as a single pass over all 414.** Operator decision, taken with the
throughput uncertainty in §14 stated and accepted.

**The 3 s framing floor — applied.** `min_spoken_framing_seconds` (default 3,
`SERVICE_SECTION_SONG_MIN_SPOKEN_FRAMING_SECONDS`) now short-circuits
`leadingObservation()` before the maximum is considered, returning a `risk => false`
observation with basis `spoken_framing_below_floor`. Three tests cover below-floor,
at-floor and floor-disabled. Setting it to `0` restores the previous behaviour
exactly.

> **It is prospective only.** `services:recompute-section-review-flags` re-derives
> `review_flags` from stored metadata; it does **not** re-assess
> `song_publication_boundary`, which is recomputed only when
> `SongPublicationHandler` runs a publication pass. A scoped dry run against the two
> affected services (394, 990) re-derived **0 sections**, as expected. §935 and §1082
> therefore keep their stored `review` decision until something re-publishes them.
> Two clips are not worth forcing a publication pass for; the floor's value is the
> ~21 spurious holds it avoids across the remaining 414.

### D6's throughput basis, for the record

op4 is the only post-fixes operation. Over 16 identities:

| measure | value |
|---|---|
| span, first start → last completion | 27.1 h |
| **actually busy** | **3.2 h — 12% utilisation** |
| mean concurrency while busy | 3.52 |
| mean / median / p90 run | 42 / 53.1 / 70.8 min |

The *worked* rate was 14.2 identities/day (→ 29 days for 414); the *busy-time* rate
extrapolates to 121/day (→ 3.4 days). **The entire 8.5× gap is whether the machine
was running**, and no pass has ever run continuously, so neither figure is a
prediction. FFmpeg is width one, so at 414 that stage may serialise in a way 16
interleaved runs never revealed. The ~6.8 h staging-copy floor from §4 stands
underneath either outcome.

**Provider spend is not measurable in-app.** The cost ledger was deleted as an empty
table and op4 carries `max_cost_minor_units = 0`. Analysis and structure both run
`gpt-5.6-luna`. A single 414-identity pass therefore spends an unknown amount with no
in-app ceiling; the OpenAI dashboard is the only check, after the fact. **Setting a
real `max_cost_minor_units` on the next operation is the cheapest way to bound this
without changing the pass size.**

---

## 14. Run #909 cannot be finished — and it is not the only one

### Correcting the record

An earlier reading of #909 (14 sections, 4 published, 0 `pending_approval`) was
reported as "10 sections never got publication candidates". **That was wrong.** The
other 10 are `not_applicable` — notices, prayers, bible readings, the children's
talk, the sermon and one `other` — which is a resolved terminal state, not a pending
one. The section work is complete.

### What is actually wrong

The run stalled *after* its sections were settled, leaving `status = processing`,
`current_step = preparing_section_publication_candidates` since 2026-07-20 with no
job behind it. Its sermon (857, `Sunday 28Th June 2026`) still carries the
placeholder title and `preacher = 'Visiting Speaker'`, `preacher_source = default`,
`needs_preacher_review = 1` — **it is one of the 53 sermons stamped by the fallback
that commit `619634926` removed.** So #909 is not an isolated stale run; it is an
instance of the defect the speaker work just fixed, frozen at the moment it happened.

### Why re-dispatching would not fix it

`PrepareSectionPublicationCandidates::dispatchStandalone()` is the right mechanism —
it creates its own lifecycle and `finishStandalonePreparation()` marks the run
completed. `queue.worker-video` covers `livestream-processing`, so it would be picked
up. But:

- the source, `livestream/temp/dda409b6-….mp4`, is **absent from every configured
  disk**; and
- `extractCandidateMediaIfNeeded()` runs *before* the status checks, and the four
  published sections' extracted media is also absent, so `shouldReuseExtractedMedia()`
  returns false and extraction is attempted.

The job would throw `Cannot prepare section candidates: source video not found`,
burn three attempts and land the run in `failed`. That is the honest end state, but
it is reached expensively and it does not recover anything. **No dispatch was made.**

### The larger finding

Checking whether #909's four song videos were unusual showed they are not:

```
published song_videos:  61
resolvable assets:       0
MISSING:                61   (historic 0, non-historic 61)
```

**Every published non-historic song video in this environment is unresolvable, and
every historic one resolves.** The distinguishing feature is a column: historic rows
record `asset_disk`, non-historic rows have `asset_disk = NULL` and so resolve
against whatever the current default disk happens to be — which is now
`historic_staging`, not the disk they were written to. The `0 missing` result in §2's
asset-graph check is consistent: it was historic-scoped, and the historic rows are
genuinely fine.

This is most likely a local artifact of disk reconfiguration rather than data loss,
and **nothing here establishes anything about production** — that needs a read-only
prod check, not an inference from this database.

Two things follow:

1. **`asset_disk` is load-bearing, and recording it is what makes the historic lane
   safe.** Phase 8 writes it. That is a design win worth not regressing.
2. **#909 needs an operator decision, not a retry** — close it out as failed and
   accept the sermon stays unpublished, or restore its assets first. Because the
   four song videos share a fleet-wide condition rather than a run-specific one,
   settling #909 alone would not settle them.
