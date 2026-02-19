# Plan: Automatically Add Bible Text to Sermons (api.bible)

## Objective
Automatically attach Bible passage text to sermons based on each sermon's `reference` field, using [api.bible](https://docs.api.bible/), without blocking existing sermon processing when external lookups fail.

## Scope
- In scope:
  - Resolve sermon references (for example, `John 3:16-21`) to canonical passages via api.bible.
  - Fetch and store passage text and attribution metadata.
  - Display passage text on sermon pages and expose via sermon API responses.
  - Add retryable queued enrichment for both automated processing and manual reference edits.
- Out of scope:
  - Replacing existing AI extraction logic for title/summary/points.
  - Rewriting the current processing pipeline architecture.

## Current-State Notes (from this codebase)
- `reference` is already extracted and stored during AI transcript processing in `app/Jobs/ProcessTranscriptWithAI.php`.
- Sermon rendering already shows the reference in `resources/views/sermons/sermon.blade.php`.
- Admin editing already supports changing reference in `app/Livewire/Admin/Sermons/EditSermon.php`.
- Pipeline sequencing is centralized in `app/Services/ProcessingPipelineBuilder.php` with existing tests in `tests/Unit/Services/ProcessingPipelineBuilderTest.php`.

## Architecture Plan

### 1. Configuration and Secrets
Add api.bible configuration in `config/services.php` and environment variables:
- `API_BIBLE_ENABLED=true|false`
- `API_BIBLE_KEY=...`
- `API_BIBLE_BASE_URL=https://api.scripture.api.bible/v1`
- `API_BIBLE_DEFAULT_BIBLE_ID=<chosen translation id>`
- `API_BIBLE_TIMEOUT_SECONDS=10`
- `API_BIBLE_MAX_RETRIES=3`
- `API_BIBLE_CACHE_TTL_SECONDS=1209600` (14 days max)

Design rules:
- Feature-flag the integration so it can be turned off safely.
- Keep all keys in env/config only (no hard-coded credentials).

### 2. Data Model
Create a dedicated persistence model for passage content instead of overloading `sermons` text fields.

Recommended table: `scripture_passages`
- `id`
- `bible_id`
- `normalized_reference`
- `api_passage_id`
- `display_reference`
- `text_content`
- `html_content` (nullable)
- `copyright`
- `fums_token` (nullable)
- `fetched_at`
- `expires_at`
- timestamps

Add linkage/status fields on `sermons`:
- `scripture_passage_id` (nullable FK)
- `scripture_lookup_status` (nullable enum/string: `pending`, `resolved`, `not_found`, `failed`)
- `scripture_lookup_error` (nullable text)

Design rules:
- Deduplicate by `bible_id + normalized_reference` where possible.
- Preserve attribution metadata required by api.bible licensing/fair-use expectations.

### 3. API Client Service Layer
Add `app/Services/ApiBibleClient.php` to encapsulate all external calls.

Responsibilities:
- Send authenticated requests with `api-key` header.
- Resolve reference to best passage candidate using search endpoint.
- Fetch passage content by passage id.
- Support configurable timeout/retry/backoff.
- Normalize and classify errors (4xx vs 5xx/network).

Design rules:
- Return typed DTOs/value objects rather than raw arrays in service boundaries.
- Keep controller/job code free from HTTP details.

### 4. Reference Normalization and Resolution
Add a resolver layer (for example `ScriptureReferenceResolver`) that:
- Sanitizes AI/ID3-provided references.
- Normalizes spacing/book-name variants.
- Rejects obviously invalid references early.
- Uses api.bible search to map normalized reference to a canonical passage.

Fallback behavior:
- If resolution confidence is low or no clear match exists, mark `not_found` and do not block processing.

### 5. Queue Integration
Create a new job, for example `FetchBibleTextForSermon`, that:
- Runs after `ProcessTranscriptWithAI` in pipelines.
- Skips when no reference exists.
- Reuses cached/previously fetched passage if not expired.
- Updates sermon linkage/status fields.

Pipeline updates:
- Insert new job in audio/video/livestream chains in `app/Services/ProcessingPipelineBuilder.php`.
- Keep `SendCompletionNotification` and completion paths resilient even when this enrichment job fails after retries.

### 6. Manual Edit Trigger
When admin updates a sermon reference in `app/Livewire/Admin/Sermons/EditSermon.php`:
- Detect reference changes.
- Dispatch the same enrichment job.
- Optionally provide a manual “refresh scripture text” action.

### 7. Presentation and API Exposure
Display layer:
- Render fetched passage text in `resources/views/sermons/sermon.blade.php` under the reference section.
- Include translation label and copyright attribution.

API layer:
- Extend `app/Http/Resources/SermonResource.php` with fields such as:
  - `scripture_text`
  - `scripture_reference_normalized`
  - `scripture_translation`
  - `scripture_attribution`

### 8. Compliance, Rate Limits, and Caching
Implement guardrails for api.bible constraints:
- Use caching up to their allowed maximum window.
- Add throttling strategy for queue workers to avoid daily request exhaustion.
- Include FUMS-related handling as required by api.bible fair-use guidance.
- Ensure attribution requirements are persisted and displayed.

### 9. Backfill and Operations
Add an artisan command (for example `sermons:enrich-scripture`) that:
- Backfills existing sermons with references.
- Supports `--missing-only`, `--limit`, and `--dry-run` options.
- Emits summary metrics: processed, resolved, not found, failed.

Operational telemetry:
- Structured logs for lookup attempts and result categories.
- Dashboard counters for status distribution and failure rates.

## Testing Plan

### Unit Tests
- Reference normalization and validation edge cases.
- Passage match selection logic from search responses.
- ApiBible client retry/error classification.

### Job Tests
- `FetchBibleTextForSermon`:
  - Resolves and links passage on success.
  - Skips with no reference.
  - Uses cache when available.
  - Marks `not_found` cleanly.
  - Retries and marks `failed` on transient hard failures.

### Pipeline Tests
- Update `tests/Unit/Services/ProcessingPipelineBuilderTest.php` for expected job order including scripture enrichment.

### Feature Tests
- Sermon page shows passage text + attribution when available.
- Sermon API returns new scripture fields.
- Admin reference edit dispatches enrichment path.

## Rollout Plan
1. Ship schema + client + resolver + job behind `API_BIBLE_ENABLED=false`.
2. Enable in staging with one translation and low queue concurrency.
3. Run targeted backfill and verify attribution/display.
4. Enable in production.
5. Monitor error rates, API usage, and queue lag; tune retry/throttle/cache.

## Key Decisions to Confirm Before Build
1. Default translation (`bibleId`) for all sermons.
2. Whether to store plain text only or plain text + HTML.
3. Whether to support only a single primary passage or multiple references per sermon.
4. Exact fallback UI behavior when lookup status is `not_found` or `failed`.

## Risks and Mitigations
- Risk: AI-extracted reference is noisy.
  - Mitigation: strong normalization and strict confidence checks before fetch.
- Risk: API daily limits exceeded during backfill.
  - Mitigation: throttled queue + chunked backfill + cache reuse.
- Risk: Attribution/compliance drift.
  - Mitigation: enforce attribution fields at persistence and render layers.
- Risk: External API latency affects processing.
  - Mitigation: separate retryable job, never blocking core sermon completion.

## Done Criteria
- New sermons with valid references automatically gain scripture text.
- Manual reference edits trigger refresh reliably.
- Sermon page and API expose scripture text with attribution.
- Processing remains resilient when api.bible is down.
- Tests for new behavior are green and pipeline order expectations updated.
