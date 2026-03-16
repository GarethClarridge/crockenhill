# Plan: Automatically Add Bible Text to Sermons (api.bible)

## Objective
Automatically attach Bible passage text to sermons based on each sermon's `reference` field, using [api.bible](https://docs.api.bible/), without blocking existing sermon processing completion when external lookups fail.

## Scope
- In scope:
  - Resolve sermon references (for example, `John 3:16-21`) to canonical passages via api.bible.
  - Fetch, sanitize, and store passage HTML plus attribution metadata.
  - Display passage text on public sermon detail pages.
  - Add retryable queued enrichment for both automated processing and manual reference edits.
  - Scheduled refresh of cached passages within api.bible's 30-day cache-refresh requirement.
  - Retire the legacy controller-based sermon edit flow in favour of the Livewire admin editor.
- Out of scope:
  - Replacing existing AI extraction logic for title/summary/points.
  - Rewriting the current processing pipeline architecture.
  - Multiple references per sermon (single reference only).
  - Exposing scripture HTML via `/api/sermons`.

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
| 5 | Bible enrichment ordering | Dispatch bible enrichment asynchronously after the sermon reference is persisted; the main processing chain does not wait for api.bible |
| 6 | Cache/refresh strategy | Fetch on sermon creation/reference edit; scheduled daily refresh for passages older than the configured threshold (default 28 days, never above 30) |
| 7 | API exposure | No scripture HTML in `/api/sermons` for v1 |
| 8 | HTML safety | Sanitize api.bible HTML before persisting or rendering it |
| 9 | Admin edit surface | Retire the legacy controller-based edit/update routes and keep the Livewire editor as the canonical path |
| 10 | FUMS reporting | Use API.Bible FUMS v3: request scripture with `fums-version=3`, persist the returned `meta.fumsToken`, and report `trackView` in the browser when scripture is shown |

## Architecture Plan

### 1. Configuration and Secrets
Add api.bible configuration in `config/services.php` and environment variables:
- `API_BIBLE_ENABLED=true|false`
- `API_BIBLE_KEY=...`
- `API_BIBLE_BASE_URL=https://api.scripture.api.bible/v1`
- `API_BIBLE_DEFAULT_BIBLE_ID=<NIVUK bible id>`
- `API_BIBLE_FUMS_VERSION=3`
- `API_BIBLE_TIMEOUT_SECONDS=10`
- `API_BIBLE_MAX_RETRIES=3`
- `API_BIBLE_REFRESH_AFTER_DAYS=28`

Design rules:
- Feature-flag the integration so it can be turned off safely.
- Keep all keys in env/config only (no hard-coded credentials).
- Treat `30` days as the hard compliance ceiling; keep the configured refresh threshold at or below that value.

### 2. Data Model
Create a dedicated persistence model for passage content instead of overloading `sermons` text fields.

Table: `scripture_passages`
- `id`
- `bible_id` — the api.bible translation identifier
- `normalized_reference` — canonical form produced by local parser (e.g., "1 John 3:16-18")
- `api_passage_id` — the OSIS-style passage ID returned by api.bible search
- `display_reference` — human-readable label returned by api.bible
- `html_content` — sanitized passage text with verse number markup
- `copyright` — attribution text required by api.bible terms
- `fums_token` (nullable) — latest Fair Use Management System token returned by api.bible for this cached passage
- `fetched_at` — when the passage was last fetched from the API
- timestamps
- Unique constraint on `bible_id + normalized_reference`

Add linkage on `sermons`:
- `scripture_passage_id` (nullable FK to `scripture_passages`)

No additional status columns on `sermons` — presence/absence of `scripture_passage_id` is sufficient. If lookup fails, the sermon simply has no linked passage (same as current behavior).

Design rules:
- Deduplicate by `bible_id + normalized_reference` unique constraint.
- Preserve attribution metadata required by api.bible licensing/fair-use expectations.
- Clear `sermons.scripture_passage_id` immediately whenever the sermon reference changes, so stale text is never shown while re-enrichment is pending.

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
- Include `fums-version=3` on scripture content requests.
- Search for a passage by normalized reference using the search endpoint (`GET /v1/bibles/{bibleId}/search`).
- Refresh an existing cached passage by stored `api_passage_id` when possible, instead of re-searching by free-text reference.
- Support configurable timeout/retry/backoff.
- Normalize and classify errors (4xx vs 5xx/network).
- Capture `meta.fumsToken` from the response and return it in the DTO so it can be persisted with the cached passage.

Design rules:
- Return typed DTOs/value objects rather than raw arrays in service boundaries.
- Keep controller/job code free from HTTP details.
- Prefer a single search call if the api.bible response already includes full passage HTML for the chosen bible/version.
- If the search response does **not** include the required HTML, fall back to fetching by `api_passage_id` and update the call-rate maths before rollout.

### 5. Queue Integration
Create a new job `FetchBibleTextForSermon` that:
- Skips when feature is disabled (`API_BIBLE_ENABLED=false`) or no reference exists.
- Uses `ScriptureReferenceResolver` to normalize and resolve.
- Reuses cached passage when `fetched_at` is within the refresh window.
- Links sermon to `scripture_passage_id` on success.
- Logs and exits gracefully on `not_found`, validation failure, rate-limit exhaustion, or network/API failure.

Dispatch strategy:
- Do **not** add the api.bible HTTP call directly into the main processing chain.
- Add a small shared action/service (for example `QueueScriptureEnrichment`) that dispatches `FetchBibleTextForSermon` asynchronously after any code path persists `sermons.reference`.
- Call that shared dispatcher from:
  - `app/Jobs/ProcessTranscriptWithAI.php` after the sermon record is updated
  - the Livewire admin editor when the reference changes
  - any remaining legacy/edge paths that still mutate `sermons.reference` during rollout

This keeps scripture enrichment out-of-band:
- notification timing is unaffected
- `CleanupTemporaryFiles` can still mark processing complete without waiting on api.bible
- reclassification is covered automatically, because it already re-runs `ProcessTranscriptWithAI`

FUMS persistence rule:
- Every successful scripture fetch/refresh should update the cached passage's `fums_token` with the latest value returned by api.bible.
- That stored token is the token used when reporting page views for the cached passage until the next refresh replaces it.

### 6. Scheduled Passage Refresh
Add a scheduled command (for example `scripture:refresh-passages`) that:
- Runs daily via the scheduler in `bootstrap/app.php`.
- Queries `scripture_passages WHERE fetched_at < now() - refresh_after_days`.
- Re-fetches each stale passage from api.bible and updates `html_content`, `copyright`, `fums_token`, `fetched_at`.
- Throttles requests to avoid API rate limit exhaustion.
- Uses the stored `api_passage_id` for refresh when available.
- Logs refresh results (updated, failed, unchanged, skipped-rate-limit).

This satisfies api.bible's requirement that cached content is refreshed within 30 days, without ever putting an API call in the page load path. Use `28` days as the default threshold to leave buffer for scheduler misses. With ~2,500 sermons on mostly unique passages, expiry dates will be naturally staggered — expect roughly ~90 passages per day to refresh at steady state.

### 7. Manual Edit Trigger
When admin updates a sermon reference in `app/Livewire/Admin/Sermons/EditSermon.php`:
- Detect reference changes (compare old vs new value).
- If the reference changed, immediately set `scripture_passage_id` to null before saving/dispatching.
- Dispatch `FetchBibleTextForSermon` for the sermon after the new reference is persisted.
- If reference is cleared, leave `scripture_passage_id` null and do not dispatch enrichment.

Legacy path cleanup:
- Retire the older controller/view-based sermon edit/update flow.
- Keep the Livewire admin editor as the single canonical edit surface for sermon metadata.
- During rollout, either remove the legacy edit/update routes or make authenticated admin requests redirect to the Livewire editor so there is only one code path to maintain.

### 8. Presentation and API Exposure
Display layer:
- Render fetched passage HTML in `resources/views/sermons/sermon.blade.php` below the reference.
- Include copyright attribution text beneath the passage.
- Keep the existing separate `Reading` block untouched; this new passage text belongs to the sermon reference itself, not the order-of-service reading reference.
- If no scripture passage is linked, show only the raw reference (current behavior — no change for visitors).
- Only load the `scripturePassage` relation on sermon detail pages, not on sermon listings.

FUMS browser reporting:
- Use API.Bible's JavaScript tracker on sermon detail pages that display scripture:
  - load `https://pkg.api.bible/fumsV3.min.js`
  - include the recommended `window.fumsData` / `window.fums` shim before any tracking call
  - after the scripture is rendered to the user, call `fums('trackView', $fumsToken)`
- If multiple passage tokens ever need reporting on one page, pass an array to `trackView`, but v1 expects one sermon-passage token.
- If authenticated-user reporting is enabled, call `fums('config', { userId: '<non-PII stable app user id>' })` before `trackView`. Do not send email addresses or other PII.
- Because public navigation in this app uses `wire:navigate`, implement the tracking hook in JavaScript (for example in `resources/js/app.js` or a small dedicated module) so it fires on both initial page load and Livewire navigation events such as `livewire:navigated`.
- Expose the token to the page in a narrow way, for example a `data-fums-token` attribute on the scripture container or a page-scoped script variable, rather than embedding ad hoc inline logic throughout the Blade template.
- Optional later enhancement: add a `<noscript><img ...></noscript>` fallback using API.Bible's manual FUMS URL format if no-JavaScript browsing becomes a compliance concern. This is not required for the primary browser-based v1 path.

API layer:
- No API changes in v1.
- If a future API consumer needs scripture text, add a dedicated detail resource/endpoint rather than extending the shared paginated `SermonResource`.

HTML safety:
- Sanitize api.bible HTML before it is persisted, using an allowlist-based sanitizer.
- Reuse the existing sanitizer pattern in `app/Services/InboundEmailHtmlSanitizer.php` or extract a small shared HTML sanitization service if that keeps responsibilities clearer.
- Ensure the allowlist preserves the tags needed for verse markup (for example `sup`) while stripping scripts, inline event handlers, unsafe URLs, and presentation cruft.

### 9. Compliance, Rate Limits, and Caching

**API budget**: 5,000 calls/day on the free plan. Each new lookup should use one search call, and each refresh should use one passage-refresh call.

Estimated daily usage at steady state:
- Daily refresh: ~90 passages/day
- New sermons: usually negligible relative to the daily cap
- Manual edits/backfill: operator-controlled
- **Total: comfortably below the 5,000/day limit**

Backfill note: A full backfill of ~2,500 existing sermons fits within one day of the current free-tier limit, but still throttle it and support batching so normal refresh traffic and retries retain headroom.

Guardrails:
- Track API calls via a simple counter (for example a cache key with daily expiry) and stop making non-critical calls when approaching the limit.
- Throttle backfill and refresh commands with a delay between requests.
- Refresh cached passages within 30 days via scheduled command.
- Include FUMS-related handling as required by api.bible fair-use guidance.
- Ensure copyright attribution is persisted and always displayed alongside passage text.
- Record structured result categories such as `resolved`, `not_found`, `unparseable`, `rate_limited`, and `failed`.
- Ensure scripture-fetch requests include `fums-version=3`, and ensure scripture-detail page views report the stored `fums_token` with `trackView`.

### 10. Backfill and Operations
Add an artisan command (for example `sermons:enrich-scripture`) that:
- Backfills existing sermons with references that have no linked scripture passage.
- Supports `--limit` and `--dry-run` options.
- Throttles API calls between sermons.
- Supports `--queue` to dispatch asynchronously when desired.
- Emits summary metrics: processed, resolved, not found, failed.

Operational telemetry:
- Structured logs for lookup attempts and result categories.

## Testing Plan

### Unit Tests
- `ScriptureReferenceResolver`: normalization edge cases (abbreviations, numbered books, invalid input).
- `ApiBibleClient`: retry/error classification, response parsing.
- HTML sanitizer: preserves expected verse markup and strips unsafe content.
- FUMS page helper: emits tracking only when a scripture token is present and handles Livewire navigation safely.

### Job Tests
- `FetchBibleTextForSermon`:
  - Resolves and links passage on success.
  - Skips when feature disabled.
  - Skips with no reference.
  - Reuses cached passage when available and not stale.
  - Clears stale linkage before re-enrichment when the reference changes.
  - Handles API failure gracefully (no pipeline crash, no stale text retained).

### Integration / Dispatch Tests
- `ProcessTranscriptWithAI` dispatches enrichment after persisting a reference.
- Reclassification still dispatches enrichment because it reuses `ProcessTranscriptWithAI`.
- Legacy edit/update routes either no longer exist or redirect to the Livewire editor.

### Feature Tests
- Sermon page shows passage HTML + copyright when passage is linked.
- Sermon page shows only raw reference when no passage is linked (current behavior preserved).
- Admin reference edit dispatches enrichment job.
- Admin reference change clears stale passage link immediately, then dispatches enrichment.
- Admin reference clear removes scripture passage link.
- Sermon detail pages expose the stored `fums_token` to the browser tracker only when scripture text is displayed.
- Browser tracking helper calls `trackView` on initial load and on `wire:navigate` sermon-page transitions.

### Refresh Tests
- Scheduled command re-fetches passages older than the configured refresh threshold.
- Scheduled command handles API failures without crashing.

## Rollout Plan
1. Ship schema + package + client + resolver + job behind `API_BIBLE_ENABLED=false`.
2. Retire or redirect the legacy sermon edit/update routes so the Livewire editor is canonical before enabling enrichment.
3. Enable in staging with NIVUK translation and low queue concurrency.
4. Run targeted backfill and verify attribution/display/sanitization.
5. Enable in production.
6. Monitor error rates, API usage, queue lag, FUMS reporting behaviour, and sanitizer output; tune retry/throttle/cache.
7. Verify scheduled refresh runs correctly before the 30-day ceiling.

## Risks and Mitigations
- Risk: AI-extracted reference is noisy or unparseable.
  - Mitigation: `techwilk/bible-verse-parser` normalizes common variants; unparseable references are skipped without API call.
- Risk: API daily limits exceeded during backfill or refresh spikes.
  - Mitigation: throttled backfill command with `--limit` option to control batch size.
- Risk: Attribution/compliance drift.
  - Mitigation: copyright field is required on `scripture_passages`; always displayed alongside passage text.
- Risk: External API latency affects processing.
  - Mitigation: bible text job is dispatched out-of-band, never blocking core sermon completion.
- Risk: `techwilk/bible-verse-parser` becomes unmaintained.
  - Mitigation: package is small (single parser class + data file); easy to fork if needed.
- Risk: Cached passages exceed the 30-day refresh ceiling.
  - Mitigation: daily scheduled refresh command with `fetched_at` tracking and a default 28-day threshold.
- Risk: Third-party HTML introduces unsafe markup.
  - Mitigation: sanitize before persistence and cover the allowlist with tests.

## Done Criteria
- New sermons with valid references automatically gain scripture text.
- Manual reference edits trigger refresh reliably.
- Sermon page exposes scripture HTML with copyright attribution.
- Processing remains resilient when api.bible is down.
- Stale passages are refreshed within 30 days.
- The Livewire editor is the sole sermon edit surface.
- Tests for new behavior are green.
