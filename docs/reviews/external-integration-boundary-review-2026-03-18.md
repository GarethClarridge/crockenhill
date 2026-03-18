# External Integration Boundary Review

Date: 2026-03-18

Scope reviewed:
- Mailgun inbound webhook + OoS parsing/import
- Google Calendar sync and manual categorization
- api.bible client and queued/synchronous consumers
- OpenAI-backed OoS parsing
- Storage, transcript storage, and upload retry helpers
- OpenAI transcription pipeline
- Speaker-identification pipeline

## Findings

### 1. High: Mailgun duplicate suppression prevents upstream redelivery from recovering failed parsing/import work

`app/Http/Controllers/Api/MailgunInboundWebhookController.php:20-39` stores inbound mail with `firstOrCreate()` on `message_id` and returns `{"status":"duplicate"}` for every later delivery, regardless of whether the previous job is still `pending` or already `failed`. `app/Jobs/ProcessInboundOosEmail.php:57-76` explicitly marks the email as `failed`, but nothing in the webhook path will re-dispatch that message if Mailgun retries it.

Why this matters:
- A transient OpenAI outage, queue outage, or import exception can permanently strand an inbound message even though the sender/provider retries successfully.
- The current test suite codifies duplicate suppression (`tests/Feature/Api/MailgunInboundWebhookControllerTest.php:89-107`) but does not cover the failed-then-redelivered case.

### 2. High: Google Calendar sync can delete valid local rows after a partial sync

`app/Services/GoogleCalendarSyncService.php:45-58` only adds an event ID to `$processedEventIds` after `syncSingleEvent()` succeeds. Any exception while mapping or saving a single event is logged and swallowed, and that event is then treated as deleted when `array_diff($existingEventIds, $processedEventIds)` runs.

Why this matters:
- A transient parsing bug, schema issue, or one bad event payload can turn a partial sync into destructive local deletion.
- There is no guard that says "only delete when the run was fully successful".
- `tests/Unit/Services/GoogleCalendarSyncServiceTest.php:26-53` only covers `syncSingleEvent()` classification behavior; it does not exercise full-sync deletion safety or partial-failure behavior.

### 3. High: Transcription retries all OpenAI API errors because non-retryable detection never matches

`app/Services/AudioTranscriptionService.php:238-255` tries to short-circuit 400/401/413 responses via `isNonRetryableError()`, but `app/Services/AudioTranscriptionService.php:436-445` checks `$exception->getCode()`. The existing test suite already documents that OpenAI's `ErrorException` reports code `0`, so the method always returns false (`tests/Unit/Services/AudioTranscriptionServiceValidationTest.php:230-250`).

Why this matters:
- Invalid credentials, bad requests, and oversize uploads are retried as if they were transient failures.
- That increases cost, worker time, and noise during incidents instead of failing fast.

### 4. Medium: OoS parsing retries are immediate and unclassified, so transient OpenAI failures burn through attempts quickly

`app/Jobs/ProcessInboundOosEmail.php:16-47` sets `$tries = 3` but defines no `backoff()` or exception middleware. `app/Services/OpenAiOosEmailItemExtractor.php:20-105` calls OpenAI directly with no retry/backoff, no rate-limit classification, and no transport exception handling. In Laravel, that means queue retries fall back to the worker's default behavior; absent per-job backoff, retries are effectively immediate.

Why this matters:
- OpenAI 429s and short-lived transport failures are likely to consume all three attempts in a burst.
- The webhook path then lands in finding #1: later Mailgun redelivery is treated as a duplicate instead of recovery.
- Tests cover happy path and malformed model output (`tests/Unit/Services/OpenAiOosEmailItemExtractorTest.php:25-213`), but not rate limits, transport failures, or retry timing.

### 5. Medium: Transcription has nested retry loops, multiplying API calls and tying up queue workers

`app/Services/AudioTranscriptionService.php:176-310` performs its own retry loop with blocking `sleep()`, and `app/Jobs/TranscribeAudio.php:23-28,182-185` adds a second queue-level retry schedule on top. Chunked transcription compounds this because each chunk calls `transcribeFile()` independently (`app/Services/AudioTranscriptionService.php:549-563`).

Why this matters:
- A single job attempt can already make multiple OpenAI calls before Laravel even considers the job failed.
- Long sleeps happen inside the worker process instead of releasing the job back to the queue.
- The boundary between "provider retry policy" and "queue retry policy" is blurred, which makes incident behaviour hard to predict.

### 6. Medium: api.bible budget tracking counts logical operations, not actual outbound attempts

`app/Services/ApiBibleClient.php:93-99` and `app/Services/ApiBibleClient.php:185-193` call `recordCall()` once before `makeRequest()`. But `makeRequest()` itself may perform multiple outbound attempts via `Http::retry()` (`app/Services/ApiBibleClient.php:260-278`).

Why this matters:
- The configured daily budget can undercount real provider traffic whenever retries happen.
- That weakens the app's own rate-limit protection exactly when the provider is already unhappy.
- The job consumer has a sensible non-retryable guard for budget exhaustion (`app/Jobs/FetchBibleTextForSermon.php:129-156`), but it inherits the client-side accounting drift.

### 7. Medium: Speaker identification is permanently best-effort, even for obviously transient failures

`app/Jobs/IdentifySpeaker.php:23-31` sets `$tries = 1`, and `app/Jobs/IdentifySpeaker.php:197-206` catches every throwable without rethrowing so the pipeline always continues. `app/Services/ResemblyzerSpeakerIdentificationService.php:39-81` runs an external Python process with a timeout, but there is no retry/backoff around process startup, temp-file download, or transient storage failures.

Why this matters:
- A short-lived local process failure or remote storage blip is treated the same as a deterministic no-match.
- That may be acceptable if speaker ID is intentionally advisory, but the current design makes recovery dependent on manual reprocessing rather than automatic retry.
- Implementation coverage is thin: only two direct tests exist for the real service (`tests/Unit/ResemblyzerSpeakerIdentificationServiceTest.php:28-59`), and they only exercise JSON parsing.

### 8. Medium: Google infrastructure concerns leak into model/service boundaries and mask drift

`app/Services/CalendarService.php:77-109` updates the local row first, then swallows remote Google update failures with a warning. The admin controller will still report success. `app/Models/Meeting.php:362-365` also resolves `GoogleCalendarSyncService` directly from the container inside the model.

Why this matters:
- Local data can silently diverge from Google after a failed remote write.
- The `Meeting` model now knows about a concrete Google integration service, which makes domain behavior harder to test and harder to replace.
- This is less a single bug than a recurring boundary smell: infrastructure work is escaping adapter/orchestrator layers and leaking into application/domain objects.

## Test seam notes

Good seams:
- `OosEmailItemExtractor`, `TranscriptionServiceInterface`, and `SpeakerIdentificationInterface` give the calling jobs workable seams for fakes and mocks.
- `OpenAI::fake()` and disk fakes are already used consistently in the existing tests.

Weak seams:
- Google Calendar integration is mostly tested by mocking the whole service or by constructing `Spatie\GoogleCalendar\Event` objects directly; there is no contract-style test around full sync semantics, pagination/partial failures, or deletion safety.
- The Python speaker-identification boundary has very limited implementation coverage and no timeout/error-shape contract tests.
- Storage retry helpers are tested with fake disks, but not with provider-like transient failures, permission failures, or retry timing assertions.

## Open Questions

1. Is speaker identification intentionally allowed to fail silently forever, or should transient provider/process failures trigger delayed retry while true no-match outcomes remain best-effort?
2. For Mailgun inbound processing, is the intended recovery path "provider redelivery", "manual reparse", or "queue retry only"? The current code blocks provider redelivery from helping.
3. Should Google Calendar be treated as source-of-truth for deletions, or should local deletion require an all-green sync pass plus a second confirmation signal?

## Suggested next passes

1. Fix the high-severity recovery paths first: Mailgun replay handling, Google partial-sync deletion, and transcription non-retryable detection.
2. Then normalize retry policy ownership per boundary: either queue-level backoff or in-service retries, but not both unless the split is explicit.
3. Add contract-style tests for the currently thin boundaries: full Google sync behaviour, OpenAI OoS rate-limit handling, and the Python speaker-extraction error surface.
