# Plan: Automatically Add Bible Text to Sermons (api.bible)

## Objective
Automatically attach Bible passage text to sermons based on each sermon's `reference` field, using [api.bible](https://docs.api.bible/), without blocking existing sermon processing when external lookups fail.

## Scope
- In scope:
  - Resolve sermon references (for example, `John 3:16-21`) to canonical passages via api.bible.
  - Fetch and store passage HTML and attribution metadata.
  - Display passage text on sermon pages and expose via sermon API responses.
  - Add retryable queued enrichment for both automated processing and manual reference edits.
  - Scheduled refresh of cached passages to comply with api.bible's 30-day retention limit.
- Out of scope:
  - Replacing existing AI extraction logic for title/summary/points.
  - Rewriting the current processing pipeline architecture.
  - Multiple references per sermon (single reference only).

## Current-State Notes (from this codebase)
- `reference` is already extracted and stored during AI transcript processing in `app/Jobs/ProcessTranscriptWithAI.php`.
- Sermon rendering already shows the reference in `resources/views/sermons/sermon.blade.php`.
- Admin editing already supports changing reference in `app/Livewire/Admin/Sermons/EditSermon.php`.
- Pipeline sequencing is centralized in `app/Services/ProcessingPipelineBuilder.php` with existing tests in `tests/Unit/Services/ProcessingPipelineBuilderTest.php`.

## Decisions

| # | Decision | Answer |
|---|----------|--------|
| 1 | Default translation | NIVUK — `bibleId` stored in `API_BIBLE_DEFAULT_BIBLE_ID` env var |
| 2 | Storage format | HTML (handles superscript verse numbers, formatting) |
| 3 | Single vs multiple references | Single reference per sermon |
| 4 | Fallback UI for `not_found`/`failed` | Show the raw reference text only (current behavior) — no error shown to public |
| 5 | Bible job vs notification ordering | Bible job runs **after** `SendCompletionNotification` — notification doesn't need scripture text |
| 6 | Cache/refresh strategy | Fetch at sermon creation; scheduled daily refresh for passages older than 30 days |
| 7 | Reclassification pipeline | Include bible text job in `buildReclassificationChain()` too |

## Architecture Plan

### 1. Configuration and Secrets
Add api.bible configuration in `config/services.php` and environment variables:
- `API_BIBLE_ENABLED=true|false`
- `API_BIBLE_KEY=...`
- `API_BIBLE_BASE_URL=https://api.scripture.api.bible/v1`
- `API_BIBLE_DEFAULT_BIBLE_ID=<NIVUK bible id>`
- `API_BIBLE_TIMEOUT_SECONDS=10`
- `API_BIBLE_MAX_RETRIES=3`
- `API_BIBLE_REFRESH_AFTER_DAYS=30`

Design rules:
- Feature-flag the integration so it can be turned off safely.
- Keep all keys in env/config only (no hard-coded credentials).

### 2. Data Model
Create a dedicated persistence model for passage content instead of overloading `sermons` text fields.

Table: `scripture_passages`
- `id`
- `bible_id` — the api.bible translation identifier
- `normalized_reference` — canonical form produced by local parser (e.g., "1 John 3:16-18")
- `api_passage_id` — the OSIS-style passage ID returned by api.bible search
- `display_reference` — human-readable label returned by api.bible
- `html_content` — passage text with verse number markup
- `copyright` — attribution text required by api.bible terms
- `fums_token` (nullable) — Fair Use Management System token if required
- `fetched_at` — when the passage was last fetched from the API
- timestamps
- Unique constraint on `bible_id + normalized_reference`

Add linkage on `sermons`:
- `scripture_passage_id` (nullable FK to `scripture_passages`)

No additional status columns on `sermons` — presence/absence of `scripture_passage_id` is sufficient. If lookup fails, the sermon simply has no linked passage (same as current behavior).

Design rules:
- Deduplicate by `bible_id + normalized_reference` unique constraint.
- Preserve attribution metadata required by api.bible licensing/fair-use expectations.

### 3. Reference Normalization (techwilk/bible-verse-parser)

Use the [`techwilk/bible-verse-parser`](https://packagist.org/packages/techwilk/bible-verse-parser) package for local reference normalization instead of building a custom parser.

The package handles:
- Abbreviation variants: `1Jn`, `I John`, `1st Jn`, `First John`, `1 Joh`, `1 Jhn` → `1 John`
- Format variants: `John 3v16`, `John chapter 3 verse 16` → `John 3:16`
- Compound references: `John 3:16-21`
- Protestant and Catholic canon

Add a thin `ScriptureReferenceResolver` service that:
1. Passes the raw reference (from AI extraction or admin edit) through `BiblePassageParser::parse()`.
2. If parsing fails → the reference is unparseable; skip API call entirely.
3. If parsing succeeds → reconstruct a normalized string (e.g., `"1 John 3:16-18"`) for use as cache key and API query.
4. Check `scripture_passages` for existing match on `bible_id + normalized_reference`.
5. On cache miss → call api.bible search endpoint with the normalized reference.
6. api.bible validates the reference is real (invalid references return empty results) and returns passage content.

This hybrid approach gives us:
- **Fast local normalization** of varied formats (no API call needed to detect unparseable input).
- **Consistent cache keys** so the same passage written differently (`1Jn 3:16` vs `1 John 3:16`) resolves to one normalized form.
- **API-side validation** of references (catches `John 99:1` that the parser accepts).
- **Passage text retrieval** in the same API call as validation.

### 4. API Client Service Layer
Add `app/Services/ApiBibleClient.php` to encapsulate all external calls.

Responsibilities:
- Send authenticated requests with `api-key` header.
- Search for a passage by normalized reference using the search endpoint (`GET /v1/bibles/{bibleId}/search`).
- Support configurable timeout/retry/backoff.
- Normalize and classify errors (4xx vs 5xx/network).

Design rules:
- Return typed DTOs/value objects rather than raw arrays in service boundaries.
- Keep controller/job code free from HTTP details.
- The search endpoint accepts human-readable references and returns passage content in a single call (the `passages` array in the response includes full HTML content). No second call to `/passages/{passageId}` is needed — critical for staying within the 5,000 calls/month free tier.

### 5. Queue Integration
Create a new job `FetchBibleTextForSermon` that:
- Runs after `SendCompletionNotification` in all pipelines (audio, video, livestream, reclassification).
- Skips when feature is disabled (`API_BIBLE_ENABLED=false`) or no reference exists.
- Uses `ScriptureReferenceResolver` to normalize and resolve.
- Reuses cached passage if `fetched_at` is within the refresh window.
- Links sermon to `scripture_passage_id` on success.
- On failure: logs the error but does not fail the pipeline — sermon remains without scripture text.

Pipeline updates in `app/Services/ProcessingPipelineBuilder.php`:
- Insert `FetchBibleTextForSermon` after `SendCompletionNotification` in:
  - `buildAudioPipeline()`
  - `buildDirectVideoPipeline()`
  - Livestream sequential chain
  - `buildReclassificationChain()`
- Position after notification means the notification fires promptly regardless of api.bible latency.
- **Cross-backlog note**: [Church service Phase 3.5](church-service-backlog.md) also reworks the pipeline chain (adding transcription, classification, and alignment steps). If that work lands first, verify the insertion point — the bible text job should still run after `ProcessTranscriptWithAI` and `SendCompletionNotification`, which remain in the pipeline.

### 6. Scheduled Passage Refresh
Add a scheduled command (for example `scripture:refresh-passages`) that:
- Runs daily via the scheduler in `bootstrap/app.php`.
- Queries `scripture_passages WHERE fetched_at < now() - 30 days`.
- Re-fetches each stale passage from api.bible and updates `html_content`, `copyright`, `fetched_at`.
- Throttles requests to avoid API rate limit exhaustion.
- Logs refresh results (updated, failed, unchanged).

This satisfies api.bible's requirement that cached content is refreshed at least every 30 days, without ever putting an API call in the page load path. With ~2,500 sermons on mostly unique passages, expiry dates will be naturally staggered — expect roughly ~83 passages per day to refresh.

### 7. Manual Edit Trigger
When admin updates a sermon reference in `app/Livewire/Admin/Sermons/EditSermon.php`:
- Detect reference changes (compare old vs new value).
- Dispatch `FetchBibleTextForSermon` for the sermon.
- If reference is cleared, set `scripture_passage_id` to null.

### 8. Presentation and API Exposure
Display layer:
- Render fetched passage HTML in `resources/views/sermons/sermon.blade.php` below the reference.
- Include copyright attribution text beneath the passage.
- If no scripture passage is linked, show only the raw reference (current behavior — no change for visitors).

API layer:
- Extend `app/Http/Resources/SermonResource.php` with:
  - `scripture_html` — passage HTML content
  - `scripture_reference` — normalized/display reference
  - `scripture_attribution` — copyright text
- All nullable; absent when no passage is linked.

### 9. Compliance, Rate Limits, and Caching

**API budget**: 5,000 calls/month on the free plan. Each lookup uses exactly one call (the search endpoint returns passage content directly — no second call needed).

Estimated monthly usage at steady state:
- Daily refresh: ~83 passages/day × 30 days = ~2,500 calls
- New sermons: ~8 calls
- Manual edits: ~10 calls
- **Total: ~2,518 calls/month (~50% of budget)**

Backfill note: A full backfill of ~2,500 existing sermons would consume the remaining monthly budget. Run the initial backfill in a dedicated month before enabling the refresh schedule, or spread across months using `--limit`.

Guardrails:
- Track API calls via a simple counter (e.g., cache key with monthly expiry) and stop making calls when approaching the limit.
- Throttle backfill and refresh commands with a delay between requests.
- Refresh cached passages at least every 30 days via scheduled command.
- Include FUMS-related handling as required by api.bible fair-use guidance.
- Ensure copyright attribution is persisted and always displayed alongside passage text.

### 10. Backfill and Operations
Add an artisan command (for example `sermons:enrich-scripture`) that:
- Backfills existing sermons with references that have no linked scripture passage.
- Supports `--limit` and `--dry-run` options.
- Throttles API calls between sermons.
- Emits summary metrics: processed, resolved, not found, failed.

Operational telemetry:
- Structured logs for lookup attempts and result categories.

## Testing Plan

### Unit Tests
- `ScriptureReferenceResolver`: normalization edge cases (abbreviations, numbered books, invalid input).
- `ApiBibleClient`: retry/error classification, response parsing.

### Job Tests
- `FetchBibleTextForSermon`:
  - Resolves and links passage on success.
  - Skips when feature disabled.
  - Skips with no reference.
  - Reuses cached passage when available and not stale.
  - Handles API failure gracefully (no pipeline crash).

### Pipeline Tests
- Update `tests/Unit/Services/ProcessingPipelineBuilderTest.php` for expected job order including `FetchBibleTextForSermon` after `SendCompletionNotification` in all pipeline types.

### Feature Tests
- Sermon page shows passage HTML + copyright when passage is linked.
- Sermon page shows only raw reference when no passage is linked (current behavior preserved).
- Sermon API returns new scripture fields when passage is linked.
- Admin reference edit dispatches enrichment job.
- Admin reference clear removes scripture passage link.

### Refresh Tests
- Scheduled command re-fetches passages older than 30 days.
- Scheduled command handles API failures without crashing.

## Rollout Plan
1. Ship schema + package + client + resolver + job behind `API_BIBLE_ENABLED=false`.
2. Enable in staging with NIVUK translation and low queue concurrency.
3. Run targeted backfill and verify attribution/display.
4. Enable in production.
5. Monitor error rates, API usage, and queue lag; tune retry/throttle/cache.
6. Verify scheduled refresh runs correctly after 30 days.

## Risks and Mitigations
- Risk: AI-extracted reference is noisy or unparseable.
  - Mitigation: `techwilk/bible-verse-parser` normalizes common variants; unparseable references are skipped without API call.
- Risk: API daily limits exceeded during backfill.
  - Mitigation: throttled backfill command with `--limit` option to control batch size.
- Risk: Attribution/compliance drift.
  - Mitigation: copyright field is required on `scripture_passages`; always displayed alongside passage text.
- Risk: External API latency affects processing.
  - Mitigation: bible text job runs after notification, never blocking core sermon completion.
- Risk: `techwilk/bible-verse-parser` becomes unmaintained.
  - Mitigation: package is small (single parser class + data file); easy to fork if needed.
- Risk: Cached passages exceed 30-day retention limit.
  - Mitigation: daily scheduled refresh command with `fetched_at` tracking.

## Done Criteria
- New sermons with valid references automatically gain scripture text.
- Manual reference edits trigger refresh reliably.
- Sermon page and API expose scripture HTML with copyright attribution.
- Processing remains resilient when api.bible is down.
- Stale passages are refreshed within 30 days.
- Tests for new behavior are green and pipeline order expectations updated.
