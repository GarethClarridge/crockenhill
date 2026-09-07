# Handover: Phase 8 post-pass re-analysis, 2026-09-07

**For a fresh session.** The Phase 8 bounded pass finished at **2026-09-07 10:49Z**.
This document covers the two re-analysis jobs it left behind. Everything here is
read from the database and the run logs, not from expectation.

## 0. Where the pass ended

Operation 4, `historic-c24f1acfc3b4f9986882be35c917b73f`, 400 of 464 approved manifest
items.

| | |
|---|---|
| completed | 405 |
| failed | 11 |
| superseded | 2 |
| non-terminal | 0 (all queues empty) |
| sermons / sections | 1,263 / 4,566 |
| sections flagged `needs_manual_review` | 301 |
| staging residue | batch root **871 GiB**, quarantine **236 GiB**, temp 35 MB, 685 GiB free |

**The Phase 8 exit gate is NOT met.** It requires every approved item to be completed,
explicitly held, or named as unresolved — and a run carrying `is_degraded_completion`
does not count as completed. The two tasks below are what stands between the pass and
that gate; the 11 failures and 64 unstarted manifest items are named, not fixed, and are
out of scope here.

## 1. Task A — re-analyse the two degraded completions

A degraded completion is a run that banked *fallback* analysis after the provider
refused, and reports as `completed` anyway. It must be re-analysed before any release
membership includes it.

```
a3694359-a959-4feb-a051-6339cdb1ba38   2025-12-14-morning   sermon_id=972
b4f26f0f-0002-4ab1-9d97-2d3c2c0d5226   2024-04-21-morning   sermon_id=1087
```

```
vendor/bin/sail artisan historic-import:reanalyse-degraded-completions \
  --operation=historic-c24f1acfc3b4f9986882be35c917b73f \
  --processing-id=a3694359-a959-4feb-a051-6339cdb1ba38 \
  --processing-id=b4f26f0f-0002-4ab1-9d97-2d3c2c0d5226
```

**This spends real provider money** — it re-runs analysis, it does not replay a banked
result.

**Verify by reading the content, not the flag.** A prior round of this work (P1-3,
2026-09-02) cleared `is_degraded_completion` on runs whose analysis had not actually
been replaced, and separately found the flag was never cleared on a genuine success.
Check that sermons 972 and 1087 hold a real title, Scripture reference, summary and
points — then check the flag.

## 2. Task B — repair sermon transcripts cut to the wrong bounds

`ce58f84de` fixed `CreateSermonTranscriptFromService` to slice the transcript to the
**spans the media was actually cut from**, not the outer bounds of the extraction plan.
A concat plan joins (say) the preached reading to the sermon and drops the hymn between
them; the old slice banked that hymn as sermon text and fed it to the AI analysis.

**The fix went live at the 2026-09-06 19:22 worker restart, so runs that finished before
then still carry the wrong transcript.** Of 170 completed multi-span runs, roughly 59
finished before that point — but that figure comes from a crude `updated_at` proxy.
**Do not trust it. The command's dry run inspects each stored transcript against the
correct slice and is authoritative.**

```
# dry run first — this is the default, and it is the census
vendor/bin/sail artisan historic-import:repair-sermon-transcript-spans \
  --operation=historic-c24f1acfc3b4f9986882be35c917b73f

# then write, and re-dispatch analysis for what changed
vendor/bin/sail artisan historic-import:repair-sermon-transcript-spans \
  --operation=historic-c24f1acfc3b4f9986882be35c917b73f --execute --reanalyse
```

`--reanalyse` spends provider money per repaired run. The repair itself needs **no
re-transcription** — every affected run keeps its full-service transcript, so the
correct text is recoverable by re-slicing.

Two known individual defects will not repair and should be left alone:

- `2022-04-10-morning` — its full-service transcript covers only **1011.7 s of a 2972 s
  recording (34%)** and the sermon sits past the truncation. Needs re-transcription, not
  re-slicing.
- `2026-04-26-evening` — its stored transcript has **no cues at all**, though Whisper
  returned 157 then 144. A storage defect, unresolved.

## 3. Environment — read before running anything

**The staging drive is unreliable, and this is the single biggest operational hazard.**

- It detached and needed a physical replug **four times** on 2026-09-05/06, plus roughly
  **17 self-healing five-second blips**. Cost on 2026-09-06 alone: **13 h of downtime
  against ~3 h of processing**.
- **It is not a power fault.** `pmset -g log | grep "Using Batt"` holds exactly one entry
  across both days (the real 2026-09-05 outage), and `/Volumes/Sonnics` — an identical
  enclosure on the same Mac — has never dropped. During a "detach" the light stays on and
  the platter spins: the *data* link goes, not the power.
- **Prime suspect: the USB SuperSpeed link.** `ioreg -r -c IOUSBHostDevice -w0 -l` shows
  Staging at `Device Speed 3` (USB 3.0) and the never-failing Sonnics at `2` (USB 2.0).
  `system_profiler SPUSBDataType` returns EMPTY on this machine — do not use it.
  **Untried remedy: force Staging to USB 2.0** with a USB-2-only cable or an old hub.
  Costs ~40 MB/s; worth it.
- **You do not need to restart anything after a detach.** `HistoricStagingReachability`
  is re-probed on every `Queue::looping`, so workers pause before fetching and release
  themselves when the volume returns. Verified live: a 6 h 11 m outage cost **one**
  re-queued job and zero run failures. Run `diskutil verifyVolume /Volumes/Staging`
  afterwards — it has come back clean three times, which is evidence, not a guarantee.

**Mounts are opt-in.** `COMPOSE_FILE=docker-compose.yml:docker-compose.drive.yml` is set
in `.env`; `vendor/bin/sail up -d` works with the drive unplugged, which is not what you
want here.

**Workers hold the code AND the `.env` they booted with.** After any change to a pipeline
class, restart them or nothing takes effect. Check `docker exec <c> ps -o etimes= -p 1`
against the commit time — `queue:restart` is a request, not a guarantee.

**Restart gracefully or pay for it.** `docker stop -t 600 <workers> && docker start
<workers>`. Docker's default 10 s grace against a multi-minute ffmpeg job SIGKILLs it,
and `REDIS_QUEUE_RETRY_AFTER=7260` then hides that job for **2 h 1 m**. A graceful stop
on 2026-09-06 took 4 m 32 s and left `reserved=0` on every queue.

**Reading artifacts requires the staging context.** `historic_staging` roots at the batch
directory only while a context is active. Outside one, every artifact reports
`exists=false` — which looks exactly like total data loss:

```php
$plan = app(HistoricVideoCurationManifest::class)->plan('/mnt/cbc-services', 'storage/app/private/historic-video-curation-manifest-20260901.json');
$ctx  = app(HistoricStagingGuard::class)->contextForApprovedPlan($plan->manifestHash, $plan->planHash);
app(HistoricStagingContextRegistry::class)->within($ctx, fn () => /* read here */);
```

## 4. Do not

- **Do not reclaim the 871 GiB batch root.** It is retained source pinned by the 301
  unresolved review obligations. Reclaiming before those decisions are made destroys the
  evidence a re-cut would need.
- **Do not use `--force` on any historic dispatch.** It bypasses the existence and
  in-flight checks that keep the lane safe.
- **Do not trust a `--dry-run` of `sermons:import-historic-videos` to predict a resume.**
  `ImportHistoricVideoBatchCommand.php:185` skips the staging context when `--dry-run`, so
  the importer computes no job keys and reports resumable work as fresh dispatch.
- **Do not size remaining work from a corpus-wide mean.** Extraction cost is governed by
  `VIDEO_EXTRACTION_REENCODE_ABOVE_MBPS=6.0`: pre-2024 sources re-encode, 2024+ stream-copy
  at ~4-17x less cost. Both knobs (threshold, and the `veryfast` preset) were assessed and
  **declined** on 2026-09-06 — see the memory note before re-proposing either.

## 5. Definition of done

- Sermons 972 and 1087 carry real analysis, verified by reading title/reference/summary/
  points, and `is_degraded_completion` is false.
- `historic-import:repair-sermon-transcript-spans --operation=…` dry run reports **zero**
  runs needing repair.
- The four gates pass: `pint --dirty`, `composer phpstan`, `artisan test --compact
  --parallel`, `artisan dusk`.
- The 11 failures and the 64 unstarted manifest items remain **named as unresolved** —
  they are not in scope and must not be quietly closed.
