# Silent-Source Exclusion and Historic Alert Visibility

**Date:** 2026-09-01
**Status:** Not started — approved for a separate session
**Scope:** Make a recording with no usable audio an explicit, self-explaining exclusion instead of an opaque late failure, and give the historic lane's alert channel a reader
**Related plan:** `HISTORIC-VIDEO-PILOT-TO-BULK-PLAN-2026-08-29.md` — this is Phase 8 robustness work, not a step 10 blocker
**Related evidence:** `storage/scratch/historic-video-operator-sequence-20260901.md`

## 1. Decision

Handle "the recording has no usable audio" as a first-class outcome. Such an
identity is **excluded** — terminal, not revisited — but the operator must be
able to see *why* without hand-diagnosing it.

Two things make this worth doing now rather than absorbing during bulk:

1. A silent recording currently fails at `detect_service_structure`, which
   terminates the chain. `CleanupTemporaryFiles` is the **last** link, so it
   never runs and the staged source is retained indefinitely. `2026-04-02-evening`
   still held 183,569,962 bytes of staging two days after it failed. Staging
   capacity is the constraint the bulk pass is bottlenecked on.
2. The failure surfaces as `Stored full-service transcript contains no cues`,
   which describes a symptom three steps downstream of the cause. Diagnosing one
   instance by hand took a substantial part of the 2026-09-01 session.

## 2. Measured frequency

**1 of 38 processed historic runs (2.6%)** has an entirely silent RMS log:
`2026-04-02-evening` (`ec270061-a2d8-459e-8e57-93ae98666cf4`). Counted by reading
the `.rms.json` logs the pipeline already produces, excluding the 200 macOS
`._` AppleDouble files that the exFAT drive scatters through the staging tree.

It is one observation, so the projection across ~450 unprocessed identities is
weak — plausibly a handful, plausibly thirty. It is clearly neither a one-off nor
common.

**Do not try to measure this by sampling the archive.** Two attempts on
2026-09-01 both produced false positives and were discarded: the webm sources
report `Duration: N/A`, so a midpoint seek collapses to 0 s and samples silent
lead-in, and `-sseof` degrades the same way without a container duration. Four
files flagged as silent were proven by whole-file `volumedetect` to have entirely
normal audio (mean −15.1 to −29.1 dB). The RMS logs are the reliable source
because the pipeline computes them over the whole file.

## 3. Root cause — a fabricated "quiet but not silent" segment

This is **not** a missing feature. The pipeline already measures the signal and
then discards it.

`GenerateRmsLog` runs in the livestream pipeline's *parallel* phase — before
everything. For `2026-04-02-evening` its output is
`lavfi.astats.Overall.RMS_level=-inf` on **every frame**. The log was written at
14:49; transcription did not start until 15:02.

`RmsAnalysisService::parseRmsLevel()` handles `-inf` correctly, mapping it to
`-999.0`. The loss happens one step later, in
`RmsAnalysisService::calculateSegmentRms()`:

```php
foreach ($rmsData as $data) {
    if ($data['time'] >= $startTime && $data['time'] <= $endTime && $data['rms'] > -999.0) {
        $segmentRms[] = $data['rms'];
    }
}

if (empty($segmentRms)) {
    // Fallback values representing a quiet but not silent segment.
    return ['avg' => -50.0, 'peak' => -40.0];
}
```

Every `-999.0` frame is filtered out, `$segmentRms` is empty, and the method
returns **synthetic levels of −50/−40 dB**. The comment states the intent
plainly: it assumes "quiet but not silent". It cannot distinguish *no RMS samples
in this window* from *every RMS sample in this window is digital silence*, and it
resolves that ambiguity by inventing audio.

Downstream consequences, all observed on the real run:

- adaptive threshold calculation fails, falling back to −45 dB
  (`threshold_method: fallback`, `adaptive_threshold: -45`);
- the fabricated −50/−40 dB values yield **one `speech` segment spanning the whole
  file**, 0 → 487.688 s, with `sermon_start_time: 0` and
  `sermon_end_time: 487.688`;
- `AnalyzeSegments` therefore does *not* throw its `No segments found in analysis`
  guard — it has a segment, just a fictional one;
- Whisper is paid for and hallucinates `" Thank you."` 17 times;
- the transcript normaliser correctly rejects every cue and records
  `unobservable_windows: [{start: 0, end: 482, reason: "retranscription_failed"}]`;
- `DetectServiceStructure` fails on zero cues, three steps from the cause.

**A digitally silent recording is currently classified as 487 seconds of
continuous speech.** That is the defect to fix.

## 4. Deliverables

### D1 — Distinguish absent RMS data from silent RMS data

`calculateSegmentRms()` must stop fabricating levels for a window whose samples
are all `-999.0`. Silence and "no data" are different answers and the caller
needs to tell them apart.

- Return silence honestly for a window whose samples all read `-999.0`.
- Preserve today's fallback **only** for a genuinely empty window (no samples at
  all in range), which is a different condition and has other callers.
- Do not widen this into a dB threshold. Restrict it to exact digital silence
  (`-inf` / `-999.0`). The 2026-09-01 sampling failures are a live demonstration
  of how a threshold invites false positives here, and a false positive would
  skip a real service.

Proof: a unit test over an all-`-inf` log asserting the segment is not reported
as speech, and a test that an empty-window call still gets the existing fallback.

### D2 — Detect and exclude in `AnalyzeSegments`

`AnalyzeSegments` already reads the RMS log via `VideoSegmentationService` and is
the authority on whether there is speech to segment. It runs **before**
`TranscribeFullService`, so acting here also avoids paying Whisper.

- When the RMS log is entirely digital silence, do not throw and do not fail.
- Record the exclusion with its evidence: frames analysed, all `-inf`, source path
  and SHA-256 from `historic_import.sources`, and the RMS log path.
- **The chain must continue to `CleanupTemporaryFiles`.** This is the constraint
  that matters most. Commit `94f7507aa` already learned it for the sermon boundary
  gate: a condition that routes for review must not also halt production, because
  cleanup is the last link and a halted chain strands its staging bytes. Failing
  the run here would recreate the exact leak this plan exists to close.
- Skip `TranscribeFullService` and `DetectServiceStructure` for the excluded run;
  there is nothing for them to read.

**Open sub-decision for the implementing session — resolve by testing, not by
preference.** `ProcessingStatus` has a `Skipped` case that fits semantically, but
`HistoricVideoPassStatus::disposition()` currently falls through to `in_progress`
for it (`default => 'in_progress'`), and it is unverified whether the chain still
reaches cleanup under it. The alternative is `Completed` plus an `excluded`
metadata flag — defensible, since the run *did* correctly determine there is
nothing to extract. **Choose whichever demonstrably reaches cleanup**, and prove
it with a test asserting staging is released.

### D3 — Record the reason through the existing alert channel

No new notification mechanism is needed, and an email is not an option: historic
operations carry `notification_mode = 'external_disabled'` and
`ProcessingNotificationRouter::suppressIfHistoric()` throws if it is anything
else. That is deliberate — a 470-identity backfill must not email per identity.

- Add kind `excluded_source_audio_silent`, severity `warning`, via the existing
  router. It writes an immutable `HistoricImportAlert` (the model blocks
  `updating` and `deleting`), deduplicated by a canonical content hash so retries
  cannot spam it, bound to both operation and run, and appends to the import
  journal.
- Carry the D2 evidence in the payload.

### D4 — Give alerts a reader

**64 alerts have been recorded and nothing has ever read them.** A grep across
`app/`, `resources/` and `routes/` finds no reference outside the model itself and
`ProcessingNotificationRouter`; the only other mention is a `hasMany` relation on
`HistoricImportOperation`. `historic-import:video-pass-status` — the command an
operator actually runs — does not touch them.

Without this deliverable the requirement "the operator should know why" is not
satisfiable by any existing path, and D3 writes into a channel with no reader.

- Surface alerts in `historic-import:video-pass-status`, grouped by kind with
  counts and per-identity reasons.
- Report the excluded identity's disposition as `excluded`, not `failed`.
- This pays for itself immediately by retrospectively surfacing the alerts already
  on disk from the canary: `success/info` 33, `failure/error` 22,
  `manual_review_structure/warning` 7, `manual_review_extraction/warning` 2.

Target operator experience — visible in the report already in use, with no email
and zero retained bytes:

```
2026-04-02-evening   excluded   source audio is digitally silent (N frames, all -inf)
```

### D5 — Carry the real failure message into historic alerts

`ProcessingRunFailureHandler::suppressHistoricFailureNotification()` is passed the
**sanitised** message from `safeMessage()`, and stores only
`exception_fingerprint = hash('sha256', $exception->getMessage())`. The real cause
is hashed away. Recorded alert payloads therefore read:

```json
{"stage":"notification_skipped",
 "message":"An internal error occurred during livestream processing.",
 "exception_class":"RuntimeException",
 "exception_fingerprint":"f48d4e06…"}
```

`safeMessage()` exists to keep internal detail out of **external** notifications.
A `HistoricImportAlert` is not external — it is a private operator record in a lane
whose external notifications are disabled by construction. Sanitising it discards
the diagnostic value at precisely the point it is needed, and is a direct cause of
the canary's failures needing hand-diagnosis.

- Add the real `$exception->getMessage()` to the alert payload under a distinct
  key (e.g. `internal_message`).
- **Keep** `message` (safe), `exception_class` and `exception_fingerprint` as they
  are — the fingerprint is used for deduplication and existing rows must stay
  comparable.
- Change only the historic alert payload. Do not touch `safeMessage()` itself or
  the external mail path; both are correct for their purpose.
- Verify no alert payload is rendered on a public surface before landing this.

## 5. Sequencing and risk

D1 → D2 → D3 → D4 in order; D5 is independent and may land first or last.

D1 is the only change touching shared segmentation code used by the live
livestream pipeline, so it carries the real blast radius: `calculateSegmentRms()`
serves ordinary weekly services. Its fallback must keep behaving identically for
every non-silent input. Run the full parallel suite on D1 alone before layering
D2 on top, so any regression is attributable.

D2's constraint — reaching cleanup — is the one most likely to be got wrong,
because the obvious implementation (fail the run) looks correct and quietly
reintroduces the staging leak. Assert released staging bytes in the test, not just
the disposition string.

## 6. Acceptance

1. An all-`-inf` RMS log no longer yields a whole-file `speech` segment.
2. A silent-source historic run reaches a terminal `excluded` disposition, makes
   **no** Whisper or LLM call, and **retains zero staging bytes**.
3. A non-silent run is unaffected — same segments, same disposition, same bytes.
4. `historic-import:video-pass-status` names the excluded identity and its reason,
   and surfaces the pre-existing 64 alerts.
5. A historic failure alert carries the real exception message alongside the safe
   one, with the fingerprint unchanged.
6. Re-running `2026-04-02-evening` reproduces the exclusion deterministically.
7. All four gates: Pint, PHPStan, full parallel suite, Dusk.

## 7. Deliberately out of scope

- Any dB-threshold or "mostly quiet" heuristic (see D1).
- Recovering audio for a silent recording — there is nothing to recover; the
  source is a failed capture.
- Changing `safeMessage()` or the external mail path (D5).
- The 200 `._` AppleDouble files in the staging tree. They are real bytes in the
  custody census and should be named in the step 8 residue report, but they are a
  drive artefact and unrelated to this plan.
