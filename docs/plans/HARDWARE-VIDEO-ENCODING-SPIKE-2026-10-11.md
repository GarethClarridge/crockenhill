# Hardware video encoding for historic cutting — spike, 2026-10-11

**Question:** can VideoToolbox (the M1 Max's hardware H.264 encoder) cut the full pass's ~320 sermon
cuts overnight instead of in ~2 days?

**Answer:** no, not on its own. VideoToolbox at constant quality 60 is correct (every cut and sync test
passes on the real hardware), matches libx264's picture quality and gives smaller files overall. But a
whole cut only gets **~1.34× faster**, or **~1.7× with two ffmpeg workers**. The video encode was only
part of each cut. What remains is audio loudness passes, the source-aware checker reading the whole
recording from the Staging drive, EnhanceAudio and Assess. The hardware encoder also runs at a fixed
~390 fps whether you start one encode or two.

Branch `spike/videotoolbox-encoding` in `../crockenhill-hwenc` (from `fix/webm-duration-fallback`
5f9a9c76d), uncommitted. Nothing live was touched. Spike files are in `/Volumes/Staging/hwenc-spike/`
(sources are APFS clones; the internal disk had 18 GiB free).

## How it was measured (operator chose "option 3")

The pass's own ffmpeg worker was running at 570–650% CPU, so libx264 was **not** re-timed. The libx264
baseline is the pass's own work since 10-10 19:20 UTC:

- **Times:** 104 ExtractSermon jobs from `docker logs crockenhill-queue.worker-historic-ffmpeg-1`. 86 of
  them were matched to their runs through the "passed source-aware timing checks" log line. Mean job
  is 508 s for a 1,705 s sermon. A fit gives job ≈ **0.259 s per sermon second + 42 s per source GB**
  (R² 0.80); the median source is 0.7 GB.
- **Size and quality:** the actual pass outputs (`quarantine/sermons/<id>/video.mp4`), copied read-only.

VideoToolbox was measured by running `VideoExtractionService::extractMedia` (the ExtractSermon cut:
loudness measures, paired encode, timing check, loudness verify) in a throwaway container off the
clone, with no database. The encode was sent to the host service. All of these timings ran
**alongside the live pass**, which slows the container-side steps in the same way production would be
slowed during a pass.

Quality is VMAF (libvmaf, subsampled 1/5) and SSIM against the source over a 120 s window, 5 min into
the main span. A same-window libx264 veryfast CRF 23 reference scored the same as the pass output
(91.2/91.1, 90.6/90.6, 87.3/87.2), so the pass-output scores are aligned and fair.

**Trap found:** `/usr/local/bin/ffmpeg` (first on PATH) is an **x86_64 build running under Rosetta**.
It refuses VideoToolbox's `-q:v` and HEVC (`Invalid argument`). Use `/opt/homebrew/bin/ffmpeg`
(arm64, 7.1.1, VideoToolbox + libvmaf).

## Measurements

Five sermons, each cut in this pass, covering different eras and sources:

| Run | Source | Sermon | libx264 job (pass) | VT cut + post-cut¹ | Speed-up | VT host encode | Size x264 → VT | VMAF x264 / VT q60 | Checks |
|---|---|---|---|---|---|---|---|---|---|
| 936 | 2024 MKV, 1.3 GB | 1,254 s, 2 spans | 402 s | 280 + 25 s | 1.32× | 102 s | 246 → 117 MB | 91.1 / 91.8 | pass, 0 loudness misses |
| 949 | 2021 MP4, 10.9 GB | 2,151 s, 2 spans | 1,241 s | 758 + 48 s | 1.54× | 188 s | 339 → 312 MB | 90.6 / 91.4 | pass, 0 misses |
| 952 | 2020 MP4, 5.1 GB | 1,445 s, 1 span | 607 s | 454 + 29 s | 1.26× | 175 s | 331 → 375 MB | 87.2 / 87.6 | pass, 0 misses |
| 993 | 2026 MKV, 2.6 GB | 2,423 s, 2 spans | 729 s | 561 + 45 s | 1.20× | 233 s | 817 → 790 MB | 88.8 / 91.6 | pass, 0 misses |
| 1117 | 2025 WebM (VP9), 0.4 GB | 1,909 s, 2 spans | 539 s | 359 + 33 s | 1.37× | 159 s | 354 → 220 MB | 76.0 / 76.7 | pass, 0 misses |
| **Total** | | | **3,518 s** | **2,592 s** | **1.36×** | | **2.09 → 1.81 GB (0.87×)** | VT ≥ x264 on all five | |

¹ The post-cut part (hashing, moving, recording) is the pass's own measured gap between the timing
check and DONE. VT does not change it.

Where a VideoToolbox cut's time goes (seconds):

| Run | Loudness measure (container) | Host encode | Loudness verify (container) | Timing-check probes + PHP |
|---|---|---|---|---|
| 936 | 77 | 102 | 40 | 61 |
| 952 | 99 | 175 | 30 | 149 |
| 949 | 150 | 188 | 59 | 362 |

The checker's full-source packet scan (`SourceAwareMediaTimingChecker::probe`) of the 10.9 GB 949
source took **322 s natively with 5.7 s of CPU**: it is I/O-bound at ~34 MB/s on the Staging drive.

Splitting run 936's real encode on the host:

| Variant | Wall | CPU |
|---|---|---|
| As the pipeline runs it | 104 s | 225 s |
| Hardware decode (`-hwaccel videotoolbox`) | 103 s | 112 s |
| Picture only, VT encode | 97 s | 145 s |
| Picture only, decode, no encode | 19 s | 140 s |
| Sound only, full treatment | 37 s | 57 s |

**The VideoToolbox encoder itself is the limit: ~390 fps at 1080p.** Neither `-prio_speed` nor
hardware decode moves it (`-realtime` makes it 5× slower).

**Parallelism:**
- Two 120 s VT encodes at once: 17.9 s against 9.8 s for one (**1.1×**); three: 27.1 s. The second
  media engine is not used for these sessions.
- Two **whole cuts** at once (936 + 1117): 504 s against 639 s one after the other (**1.27×**), because
  one cut's container-side audio and probe work overlaps the other's encode.
- For comparison, libx264 at width 2 was measured on 09-30 at 1.06–1.16× (standing decision 6).

### Quality: which setting

VideoToolbox has no CRF. A fixed bitrate starves busy footage:

| VMAF at | 936 | 949 | 952 | 993 | 1117 |
|---|---|---|---|---|---|
| libx264 pass output (kbps) | 91.1 (1,493) | 90.6 (1,020) | 87.2 (1,639) | 88.8 (2,798) | 76.0 (1,361) |
| VT `-b:v 1500k` | 93.2 | 92.0 | **73.2** | **74.6** | 78.2 |
| VT `-b:v 2500k` | 94.8 | 94.7 | 83.2 | 83.2 | 79.3 |
| VT `-q:v 55` (kbps) | 90.1 (725) | 89.5 (1,101) | 84.3 (2,225) | — | — |
| **VT `-q:v 60` (kbps)** | **91.8 (945)** | **91.4 (1,392)** | **87.6 (2,852)** | **91.6 (3,179)** | **76.7 (937)** |
| VT `-q:v 70` (kbps) | 94.6 (2,074) | 94.7 (2,380) | 92.9 (5,033) | 95.2 (5,745) | 78.7 (1,813) |
| HEVC VT `-b:v 1000k` | 91.9 | 90.3 | 73.2 | 74.5 | 77.4 |

`-q:v` is the constant-quality mode (`kVTCompressionPropertyKey_Quality`). **60 is the lowest value
that matches or beats libx264 on every source**; 55 falls below it on all three runs tried. Easy
footage gets smaller (0.6×) and busy 2020 footage larger (1.7×); over the five full cuts it comes to
0.87× of the libx264 bytes.

**HEVC: not recommended.** Its quality per bit is no better here, and `<video src>` playback is not
universal: Safari plays it, Chrome and Edge only with hardware support, and Firefox mostly does not.
Sermon video must play everywhere.

Output properties (949, 1117): H.264 High@4.0, yuv420p, no B-frames, keyframe at 0.000, a keyframe
every ≤8.33 s (`-g 250`, as libx264's default), durations within 0.03 s of the libx264 cuts (the
same spans, but ms timebase against frame timebase).

### Correctness (on the real hardware)

With `VIDEO_EXTRACTION_ENCODER=h264_videotoolbox` and the host service, **`VideoExtractionSmartCutTest`
+ `SectionAudioTreatmentTest` + the new tests: 51 passed, 199 assertions, 31 real VT encodes (all exit 0).**
These cover:

- frame-numbered content at cut starts, including the ms-timebase MKV and nonzero-start sources
- one-frame steps across every join of a two-span cut, on a source without B-frames
- simultaneous sound and picture events through the last seconds of a two-span treated cut
- the loudnorm tail flush
- open-GOP and VP9/WebM sources, and an unindexed WebM without container duration
- the source-aware checker's negative controls

On libx264 the same 44 suite tests also pass (no regression).

All five real cuts passed the source-aware timing check and the loudness verify with 0 misses. Note
that the host ffmpeg is 7.1, so the **whole** paired encode (audio treatment and AAC included) runs on
7.1 rather than the container's 6.1. The verify passes, which still run in the container, accepted
every output.

Integration (`ExtractSermonTest`, `PrepareSectionPublicationCandidatesTest`,
`WordTimedOutputEdgesTest`, `SongPublicationHandlerTest`): 133 passed and 3 failed in **both**
encoder modes. The same 3 (talk-speaker assertions in `PrepareSectionPublicationCandidatesTest`) also
fail on the unchanged base in this harness, so they are pre-existing and not caused by the patch.
These tests stub the encode, so they made no host calls.

## Architecture

**Recommended: (a) a host-side ffmpeg HTTP service**, like whisper (:2022) and the classifier (:2023).

- `scripts/ffmpeg_host_server.py`:
  - `POST /run {"arguments": [...]}` → `{"exit_code", "output"}`; `GET /` for health and the
    encoder list.
  - Maps container paths to host paths. Run it with `--map /mnt/historic-work=/Volumes/Staging/HistoricWork`
    and `--map /var/www/html/storage=<checkout>/storage` (not the whole checkout).
  - Refuses: paths outside the mapped roots (after `realpath`, so `..` cannot escape); protocols
    (`concat:`, `pipe:`, `http://`); file-opening filters (`movie=`, `sendcmd=`, …).
  - Allows `--jobs` concurrent encodes (default 2) and kills an encode after `--timeout`.
  - It binds 0.0.0.0 because `host.docker.internal` cannot reach loopback, so it is reachable from
    the LAN. The path allowlist is the guard; the macOS firewall should block :2024 from outside.
- `App\Services\Media\Video\HostFfmpeg` posts the paired encode's arguments, unescaped.
  `VideoExtractionService::runFfmpeg` sends **only the paired encode** there when
  `media-processing.video_extraction.encoder` is `h264_videotoolbox`. The loudness passes, probes,
  EnhanceAudio and Assess stay in the container. `FFMPEG_PATH` is unchanged, so
  `HistoricProcessingFingerprint` (which records that binary) is unchanged. It does not record the
  host binary either, which is a provenance gap worth noting.
- **Failure handling: fail the job, never fall back.**
  - An unreachable service, a refused request or a non-zero exit throws `VideoProcessingException`,
    so the job fails, retries per its tries, and a failed job stops the watcher, as any failed cut
    does.
  - A silent libx264 fallback would write outputs stamped `h264_videotoolbox` that are not. The
    classifier does the same (it throws when its URL is unreachable).
  - The HTTP timeout is 3,300 s, inside ExtractSermon's 3,600 s job timeout.
- Run it as a LaunchAgent `com.crockenhill.ffmpeg-host` with KeepAlive, like
  `com.crockenhill.audio-classifier`, using `/opt/homebrew/bin/python3` and `--ffmpeg /opt/homebrew/bin/ffmpeg`.

**Rejected: (b) a native macOS queue worker.**
- There is no PHP on the host; it would need Homebrew PHP 8.4 and its extensions.
- It would need a second `.env` with host paths for `historic_temp`, `historic_staging`,
  `historic_quarantine` and `local`.
- MySQL and Redis would have to be reachable from the host.
- It would need a separate worker boot/restart discipline outside `docker start`.
- It would move all ffmpeg work, not just the encode, onto unmeasured ground mid-pass.

It would save the VM's file-sharing overhead, but that is not where the time goes.

### The patch (spike branch, uncommitted)

- `config/media-processing.php`: `video_extraction.encoder` (`VIDEO_EXTRACTION_ENCODER`, default
  `libx264`), `videotoolbox_quality` (60), `videotoolbox_keyframe_interval` (250),
  `host_ffmpeg.url` (`HOST_FFMPEG_URL`), `host_ffmpeg.timeout_seconds` (3300).
- `VideoExtractionService`: builds the paired encode as an unescaped argument list. It escapes it
  for local `exec` as before, or sends it to `HostFfmpeg`. The VT arguments are
  `-c:v h264_videotoolbox -profile:v high -allow_sw 0 -q:v 60 -g 250`.
- `MediaProcessingVersion`: the libx264 `video` entry is **byte-identical to before**, so staying on
  libx264 invalidates nothing. VT stamps `codec: h264_videotoolbox, quality, keyframe_interval`.
- `HostFfmpeg` + `scripts/ffmpeg_host_server.py`.
- Tests:
  - `HostFfmpegTest` (5)
  - `MediaProcessingVersionTest` (2)
  - `VideoExtractionSmartCutTest::a_host_encode_sends_runnable_arguments_and_passes_the_same_checks`:
    the fake host runs the received arguments with the container's ffmpeg (VT swapped for
    libx264), so CI without a Mac proves the arguments run as sent.
  - `tests/TestCase.php` allows real requests to `HOST_FFMPEG_URL/run` only when it is set, so the
    suites can run on the hardware.
- Pint clean. PHPStan on the changed files reports only the two `max()` findings already present on
  the base branch.

## Throughput for ~320 cuts

Per cut on the ffmpeg queue today: 15.47 h of ffmpeg work over 104 cuts = **8.9 min**.
That is ExtractSermon 6.9, EnhanceAudio 1.7, Assess 0.8 and the small jobs.

| Setting | Per cut | ~320 cuts |
|---|---|---|
| libx264, width 1 (today) | 8.9 min | **~48 h** |
| VT, width 1 (ExtractSermon ÷1.34, rest unchanged) | ~7.7 min | **~41 h** |
| VT, width 2 (×1.27 measured on two whole cuts; assumes EnhanceAudio/Assess overlap as well, not measured) | ~6.1 min | **~32 h** |

Overnight (~10 h) would need ~5× less time per cut. After VT, the large remaining costs are, per
typical cut:

1. **EnhanceAudio**: ~100 s.
2. **Loudness measure + verify passes**: ~120–210 s. They are separate ffmpeg decodes per part and
   per output.
3. **The checker's whole-source packet scan**: I/O at ~34 MB/s on Staging. ~20 s for a median
   0.7 GB source, ~320 s for 10.9 GB. It could read only the selected spans (`-read_intervals`).
4. **Assess**: ~47 s.

These are not encoder questions and were not measured here.

## Switching mid-pass

Changing the encoder moves `CodeRevision` (app/config). It also changes the stamp from
`MediaProcessingVersion::signature()`. What changes or breaks:

1. **Dispatched rounds bind `code_revision`.** Queued cut chains from the current snapshot are refused
   on new code, so they must be pulled (payloads saved, as `hold-jobs.php --remove` does for the hold
   list, but for every run) and **not** re-queued.
2. **`AssessSermonVideoQuality` calls `RecordedVideoOutput::verified()`.** That compares the cut's
   recorded provenance (which holds the signature) with the current signature. A run cut on libx264
   whose Assess runs after the switch fails `recorded_video_provenance_mismatch`. **Drain every cut
   chain past Assess before switching.**

   Promotion and publication use `prepareRelocation()` (hash and size only), so the ~100 libx264 cuts
   already made still promote and publish.
3. **`OutputEdgeWordTimings` keys its cached edge transcriptions on the signature.** Every run's
   edges are re-transcribed once (TranscribeOutputEdges on whisper, seconds per run; not on the
   ffmpeg queue).
4. **`PrepareSectionPublicationCandidates` reuses a candidate only when its signature matches.** Song
   and talk candidates cut on libx264 are re-cut when that job next runs.
5. **ExtractSermon's `authoriseReplacementIfCutChanged` treats a signature change as a new cut**
   (`markAsReExtraction`). Expected: these runs are being re-cut anyway.
6. The watcher, the readiness service and the re-run diff do not compare the signature (checked).
   Custody rules are encoder-agnostic.
7. `HistoricProcessingFingerprint` does not change, because `FFMPEG_PATH` does not.

Steps, if the operator chooses to switch. The full-pass session executes them.

1. Install and start the LaunchAgent. Run `curl http://host.docker.internal:2024/` from a worker
   container: expect `h264_videotoolbox` among the encoders. Add a firewall rule for :2024.
2. Put the spike on the fix branch (`fix/webm-duration-fallback`) as one commit. Run the full suite,
   PHPStan and Pint in the clone, and the cut suites once against the host
   (`VIDEO_EXTRACTION_ENCODER=h264_videotoolbox HOST_FFMPEG_URL=http://host.docker.internal:2024`).
3. Stop autotick's `rerun-extract` (it re-dispatches every 10 min) and front-loop.
4. Pull every queued cut-chain head (TranscribeOutputEdges, ExtractSermon) with payloads saved.
5. Let the in-flight chains drain through Assess, Thumbnail and PrepareSectionPublicationCandidates:
   historic-ffmpeg empty of those for every cut run.
6. Stop the workers. Merge the fix branch `--ff-only`. Set `.env`:
   - `VIDEO_EXTRACTION_ENCODER=h264_videotoolbox`
   - `HOST_FFMPEG_URL=http://host.docker.internal:2024`
   - optionally `HISTORIC_MEDIA_WORKERS_FFMPEG=2`

   `docker start` the workers. Check `ps` ELAPSED: workers hold boot code and boot `.env`.
7. Take a new snapshot of every not-yet-cut run (the queued ~160 minus held, plus the follow-up
   round's runs if they are ready). Point the watchers and autotick at it, then make one
   `rerun-extract` dispatch with an explicit run list.
8. Watch the first cut through Assess. If the host service fails, the job fails and the watcher
   stops, as any failed cut does. The only fix is restarting the LaunchAgent; there is no automatic
   fallback by design.

## Recommendation

- **Setting:** `h264_videotoolbox -q:v 60 -g 250 -profile:v high`, with the native arm64 ffmpeg.
  Equal or better quality, 0.87× the bytes.
- **Architecture:** the host HTTP service, encode only; fail rather than fall back.
- **When:** VT saves roughly 7–16 h over ~320 cuts, not the ~38 h needed for overnight. A mid-pass
  switch costs a drain (step 5), a new snapshot and a re-transcription of edges. **Ship it with the
  fix-branch deploy already planned for the follow-up round**, where those costs are paid anyway.
  Switch mid-pass only if that deploy is being brought forward for other reasons.
- **For an overnight pass, look next at the non-encode costs above** (EnhanceAudio, the loudness
  passes, the whole-source scan). Each is as large as the encode now is.
