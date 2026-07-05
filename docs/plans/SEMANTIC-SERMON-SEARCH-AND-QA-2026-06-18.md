# Semantic Sermon Archive (2026-06-18) - "Ask the Archive" Retrieval & Grounded Q&A

> **Status (2026-07-05): not started — deliberately queued behind the July backlog. Do not start
> until the gates below clear, and re-plan Phase 1 first.**
> Sequencing against
> [JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md):
>
> - **Backlog item 2.3 (storage-service collapse) lands first** — it turns this plan's backfill
>   into a simple loop over `audio_file_path` (sermons review, opportunity 1).
> - **Backlog item 1.7a (one Whisper pass per service) changes Phase 1's shape.** 1.7a slices the
>   timestamped full-service transcript (`ChurchServiceTranscript`, which already exists from the
>   LLM-first work) for the sermon transcript — so for *new* sermons, timestamped transcripts fall
>   out of 1.7a and Phase 1 reduces to the archive re-transcription backfill. Write a fresh Phase 1
>   plan (plan mode) once 1.7a's shape is settled rather than extending
>   `TranscriptionServiceInterface` as drafted below.
> - The Decisions Locked and Phases 0/2–5 remain valid as written.

## Recommendation

Build a **semantic retrieval layer over the existing sermon transcript corpus**, surfaced as three compounding features:

1. **Natural-language search** on the public sermons page ("sermons on forgiveness", "what does the Bible say about anxiety?"). **Public.**
2. **"Ask the Archive"** — a grounded Q&A that answers a question using *only* Crockenhill's own preaching, citing each claim back to a specific sermon and deep-linking to the exact moment it was said. **Members-only** (see Decisions Locked).
3. **"Related sermons"** recommendations on each sermon page. **Public.**

This is the highest-leverage addition available right now because it is **accretive**: it compounds the single largest existing investment in the codebase — a large, growing set of sermons that each already carry a clean transcript, AI summary, key points, an extracted scripture index, a preacher, a series, a date, and section-level timestamps. Today none of that content is searchable by meaning; the public archive (`BrowseSermons`) supports only faceted filtering (book / chapter / preacher / series). The missing keystone is a *text* embedding.

It scores highest on every axis: accretive (reuses the biggest asset), innovative for the domain (almost no church site offers grounded, cited Q&A over its own teaching), useful (serves seekers via public search, members via Q&A, and the preaching team as a research tool), and mission-aligned. The public SEO win is the semantic search surface plus related-sermons internal linking (and, optionally later, editorially-curated public topic pages); live Q&A is a members benefit and is not indexed.

## Decisions Locked (2026-06-18)

These four product/architecture decisions were settled with the maintainer and now drive the plan:

1. **Vector store — JSON + PHP cosine, with facet pre-filtering** *(settled by environment, not preference)*. The database is **MySQL 8.0.45**; native `VECTOR` columns + `VEC_DISTANCE` are a MySQL 9.0 feature. Upgrading the production DB engine purely for a vector index is out of proportion to this feature. We reuse the exact pattern the speaker-ID service already runs (`ResemblyzerSpeakerIdentificationService::cosineSimilarity()`).
2. **Q&A exposure — public search, members ask.** Semantic search and related-sermons are public; Q&A *answer generation* sits behind `auth` + `verified` (members area). Rate-limiting is per-authenticated-user; the per-IP daily budget is replaced by a per-user budget + an app-level cap.
3. **Deep links — exact from the start.** No approximate/proportional timestamps. This requires a **timestamp-capable transcription path** and a **re-transcription pass over the existing archive** (new Phase 1). Chunks become segment-aligned with real `start_time`/`end_time`.
4. **Embedding dimensions — benchmark, then lock.** Ingestion is built dimension-agnostic; we backfill on real data, benchmark candidate dimensions (e.g. 512 vs 1536) for latency + retrieval quality, then lock `embeddings.dimensions` before any user-facing surface ships.
5. **Q&A delivery — non-streamed MVP first** (loading state -> complete cited answer), with SSE streaming as a fast-follow.

### Residual sub-decision (settle in Phase 1, non-blocking)
**Which transcription model provides the timestamps.** Leading recommendation: use the existing **local Whisper path** (`LocalWhisperTranscriptionService`, already config-bound in `AiServiceProvider`) — local Whisper emits native segment/word timestamps and incurs no per-minute API cost for an ~800-sermon backfill. A Phase 1 spike confirms its timestamp output and decides whether it replaces, or runs alongside, `gpt-4o-transcribe` for new sermons (the current model was chosen for transcription quality but does not return `verbose_json` segment timestamps).

## Background

### What already exists (the asset base)
- **Transcripts** for processed sermons (`sermons.transcript_file_path`), retrievable via `TranscriptStorageService` / the `HandlesTranscriptStorage` trait (`getTranscript(int $sermonId): ?string`). A `SermonBuilder::whereHasTranscript()` scope already exists.
- **AI-derived metadata** per sermon: `title`, `series`, `reference`, `points` (JSON), `summary`, `meta_description` (see `App\Data\SermonAnalysis`).
- **A scripture index**: `sermon_scripture_filters` (bible_book + bible_chapter per sermon) and fetched Bible text in `scripture_passages` (with `copyright` / `fums_token`).
- **Section-level timestamps**: `service_sections` (`start_time`, `end_time`, `extracted_video_path`, `extracted_audio_path`, `published_sermon_id`) and `livestream_segments`.
- **An embedding + cosine-similarity pattern already in production** for *voice* identification: `App\Services\Preacher\ResemblyzerSpeakerIdentificationService` stores vectors as JSON (`speaker_profiles.centroid_embedding`, `speaker_samples.embedding`) and ranks by `cosineSimilarity()`; `computeCentroid()` averages vectors.
- **A clean mock/real AI binding pattern**: `App\Providers\AiServiceProvider` binds `TranscriptionServiceInterface` (mock | local | openai) and `SermonAnalysisInterface` via `config('media-processing.*.service')`.
- **The OpenAI SDK is already installed** (`openai-php/laravel`). `AudioTranscriptionService` uses `OpenAI::audio()`, `SermonAnalysisService` uses `OpenAI::chat()`. The same facade exposes `OpenAI::embeddings()->create()`.
- **A backfill-command pattern**: `App\Console\Commands\BootstrapSpeakerProfilesCommand`.
- **SSE streaming infrastructure** (Laravel 13 Phase 3): the `/api/media/processing/{id}/stream` event stream and `processing-stream` rate limiter.
- **The members area**: `/church/members/*` behind `auth`, `verified` middleware (`routes/web.php`).
- **SEO machinery**: `SermonArchiveSeoPresenter`, JSON-LD presenters, sitemaps.

### Corpus shape (measured 2026-06-18)
- **818 sermons** in the archive.
- Only **4 transcribed in the local dev DB** (the `sermons_prod_import` table is empty locally); transcripts are generated going forward and backfilled. Plan for hundreds -> low-thousands of sermons ≈ tens of thousands of chunks at full coverage. The open-ended Q&A path has no facet to pre-filter on, so PHP must cosine-scan the whole corpus per question — which is why the embedding-dimension benchmark (Decision 4) matters.

### The gap
The substance of the preaching is locked inside transcript files that nothing queries semantically. The archive is browsable by *metadata facets* only — never by *content*.

## Key technical findings that shape the plan

1. **Embeddings require no new dependency.** They ride on the already-installed OpenAI SDK (`OpenAI::embeddings()->create()`), the existing config + interface-binding pattern (`AiServiceProvider`), and the existing JSON-embedding + cosine pattern (`ResemblyzerSpeakerIdentificationService`).
2. **MySQL 8.0.45 fixes the vector store** to JSON + PHP cosine with facet pre-filtering (Decision 1).
3. **Exact deep links require upgrading transcription.** The current path (`AudioTranscriptionService::transcribeFile()`, `gpt-4o-transcribe`, `response_format => 'text'`) discards timing. Decision 3 adds a timestamp-capable transcription path and a re-transcription backfill, and in return lets us chunk along real segment boundaries (more accurate retrieval than character-offset estimation).
4. **The existing facet filters double as a vector pre-filter.** Pre-filtering candidate sermons by book / preacher / series / date (via `SermonRepository` / `SermonBuilder`) before brute-force cosine keeps PHP-side similarity cheap on the *search* path. The members-only Q&A path is unfiltered, so dimension choice governs its latency.

## Goal

Turn the sermon library into an answerable body of teaching:
- A meaning-based, public search box on the sermons page with exact "jump to mm:ss" deep links.
- A grounded, cited, members-only "Ask the Archive" Q&A surface.
- "Related sermons" on each public sermon page.
- All dark-shippable behind a feature flag, all tested with deterministic mock embeddings so CI never calls OpenAI.

## Non-Goals

- Do **not** replace the existing faceted browse — semantic search augments it.
- Do **not** add a vector-database dependency or upgrade MySQL for this feature (Decision 1).
- Do **not** ship approximate deep links (Decision 3) — exact timestamps only.
- Do **not** expose live Q&A publicly (Decision 2) — members only; any public topic pages are editorially curated, not live generations.
- Do **not** re-publish licensed Bible text in answers — quote sermon transcript (our content) and respect `scripture_passages.copyright` / FUMS.
- Do **not** let the Q&A invent doctrine — answers are strictly extractive from supplied excerpts, with a graceful refusal when evidence is insufficient.

## Architecture Overview

```text
TRANSCRIPTION (Phase 1 - timestamp-capable; re-transcribes archive)
  Sermon audio
        |
        v
  Timestamp-capable transcription (local Whisper -> segment/word times)
        |
        v
  Timestamped transcript structure (segments: [{start, end, text}])

INGESTION (Phase 2 - per sermon, dimension-agnostic)
  Timestamped transcript
        |
        v
  TranscriptChunker -> segment-aligned ~chunk_tokens windows
        |               (real start_time/end_time per chunk)
        v
  EmbeddingServiceInterface (mock | OpenAI text-embedding-3-small @ configured dims)
        |
        v
  sermon_transcript_chunks (content, embedding JSON, start_time, end_time)
        |
        v
  Dimension benchmark on real data -> lock embeddings.dimensions

RETRIEVAL
  Query -> embed(query)
        |
        +--> PUBLIC SEARCH: facet pre-filter -> cosine-rank -> group by sermon
        |                    -> best snippet + exact timestamp -> result cards
        |
        +--> MEMBERS Q&A (auth+verified): top-k chunks (unfiltered scan)
                             -> grounded prompt -> OpenAI::chat()
                             -> answer + citations (sermon + exact timestamp + snippet)
```

## Guiding principles (all observed in this codebase)

- **Reuse, don't add.** Embeddings via the installed SDK; cosine/centroid mirroring `ResemblyzerSpeakerIdentificationService`; mock/real binding mirroring `AiServiceProvider`; timestamped transcription via the existing local-Whisper binding; jobs + backfill mirroring `BootstrapSpeakerProfilesCommand`; streaming mirroring the Phase 3 media-processing SSE stream.
- **Ship as a stack of small PRs merged in order** (matching the service-UI consolidation #798->#813 pattern).
- **Every phase is independently shippable and flag-gated.** Phases 0-2 add data/pipeline with zero user-facing change.
- **Mock parity is mandatory** — deterministic mock embeddings so the parallel suite never calls OpenAI.
- **British English + sentence case** in every prompt and user-facing string (the analysis system prompt in `SermonAnalysisService::executeAiRequest()` already enforces this; the Q&A prompt must too).
- **Quality gates per PR:** focused tests -> `composer phpstan` (0 errors) -> `pint --dirty` -> parallel suite -> Dusk for UI changes.

---

## Phase 0 - Foundations (infra only, no UX)

**Goal:** the contract, config, storage, and chunker, each provably tested in isolation. Dimension-agnostic.

**Changes**
- `config/media-processing.php`: add an `embeddings` block (`service` = `mock|openai`, `model` = `text-embedding-3-small`, `dimensions` (set after benchmark), `openai_api_key` fallback) and an `archive_search` block (`enabled` feature flag, `top_k`, `min_score`, `chunk_tokens`, `chunk_overlap`, `qa.per_user_daily_budget`).
- `app/Contracts/EmbeddingServiceInterface.php` — `embed(string $text): array`, `embedBatch(array $texts): array`.
- `app/Services/Media/Embeddings/OpenAiEmbeddingService.php` — wraps `OpenAI::embeddings()->create()` (passing `dimensions`); typed retryable/non-retryable exception handling cloned from `AudioTranscriptionService`.
- `app/Services/Media/Embeddings/MockEmbeddingService.php` — deterministic pseudo-vector seeded from `crc32($text)`, L2-normalised, of the configured dimension. Plumbing fidelity only (not semantic).
- Bind both in `AiServiceProvider` using the existing `match`/`config` shape.
- Migration `sermon_transcript_chunks`: `sermon_id` (FK cascade), `chunk_index`, `char_start`, `char_end`, `start_time`, `end_time`, `content` (text), `token_count`, `embedding` (JSON), `embedding_model`, `embedding_dimensions`, `embedded_at`; index `(sermon_id, chunk_index)`. Model + factory.
- `app/Support/TranscriptChunker.php` — groups timestamped transcript segments into overlapping ~chunk_tokens windows, carrying the real `start_time`/`end_time` of the first/last segment in each window.
- **Optional, recommended:** extract `App\Support\VectorMath::cosine()` and switch `ResemblyzerSpeakerIdentificationService` to it so cosine lives in one place.

**Tests:** chunker grouping/overlap/timestamp propagation; mock determinism + dimension; cosine correctness incl. zero-vector guard; interface binding resolves by config.

**Risk:** very low — no wiring into the live pipeline yet.

---

## Phase 1 - Timestamp-capable transcription + re-transcription backfill

**Goal:** every processed sermon has a timestamped transcript; the archive is re-transcribed so exact deep links exist everywhere.

**Changes**
- Spike + decision: confirm `LocalWhisperTranscriptionService` emits segment/word timestamps; decide replace-vs-alongside `gpt-4o-transcribe` for new sermons (residual sub-decision above).
- Extend `TranscriptionServiceInterface` / the chosen service to return a **timestamped structure** (segments), not just markdown text. Persist via `TranscriptStorageService` (e.g. a JSON sidecar alongside the existing markdown), behind config so the current text path remains available.
- `sermons:retranscribe-backfill` command (mirrors `BootstrapSpeakerProfilesCommand`) to re-transcribe sermons with audio but no timestamped transcript; `--limit`, `--sermon=`, dry-run. Designed to churn in the background (independent compute) while later phases are built.

**Tests:** timestamped structure persisted + retrievable; backfill scope/idempotency; config toggles old vs new path; existing transcription tests stay green.

**Risk:** medium-high — this is the one phase that **touches the live media pipeline**. Mitigations: config-gated path switch, the existing mock/local/openai binding seam, full transcription test suite, staged rollout. Honest cost note: re-transcribing ~800 sermons is real compute (local Whisper avoids per-minute API cost; budget machine time).

---

## Phase 2 - Embedding ingestion + backfill + dimension benchmark (no UX yet)

**Goal:** every timestamped sermon gets embedded, segment-aligned chunks; the embedding dimension is benchmarked and locked.

**Changes**
- `app/Jobs/EmbedSermonTranscript.php` — loads the timestamped transcript, chunks via `TranscriptChunker`, **batch-embeds**, upserts chunk rows with real `start_time`/`end_time`. Idempotent (delete-then-insert per sermon). Typed retries mirroring `TranscribeAudio`. Gated by `archive_search.enabled`.
- Pipeline hook: dispatch after timestamped transcription completes (and on regeneration) via the pipeline registry / `SermonObserver`.
- `sermons:embed-backfill` command (mirrors `BootstrapSpeakerProfilesCommand`) over chunk-less sermons.
- `sermons:embed-benchmark` harness — backfills a sample at candidate dimensions, measures unfiltered-scan latency + retrieval quality on held-out queries, reports a recommendation. **Lock `embeddings.dimensions`** before Phase 3.

**Tests:** correct chunk count + persisted embeddings (mock); idempotent re-run; backfill + benchmark scope; flag-off = no dispatch.

**Risk:** low — additive data; flag-gated; idempotent.

---

## Phase 3 - Public semantic search UI (first user-facing win)

**Goal:** a public search box on the sermons page, ranked by meaning, with exact "jump to mm:ss".

**Changes**
- `app/Services/Public/ArchiveSearchService.php` — embed query -> facet pre-filter via `SermonRepository`/`SermonBuilder` -> cosine-rank chunks -> group by sermon -> best snippet + exact timestamp per sermon.
- Extend `BrowseSermons` with `#[Url] public ?string $q` (`wire:model.live.debounce.400ms`). With `q` set, results come from `ArchiveSearchService`; otherwise the current faceted browse is untouched. Result card gains snippet + deep link.
- SEO: search-result state `noindex` (or canonical to base archive) via `SermonArchiveSeoPresenter`.
- Player deep-link: extend the sermon player to honour a `?t=` seek param (Alpine). **Verify/implement during build.**

**Tests:** ranking/grouping with deterministic mock embeddings; Livewire search interaction; SEO noindex on `q`; candidate-filter query shape. Dusk: type query -> results update; click deep link -> player seeks.

**Risk:** medium — first public surface; mitigated by flag + the untouched faceted default path.

---

## Phase 4 - Members-only "Ask the Archive" Q&A (the flagship)

**Goal:** logged-in members ask a question, get a cited answer grounded *only* in Crockenhill's preaching, non-streamed MVP.

**Changes**
- `app/Services/Public/ArchiveAnswerService.php` — retrieve top-k chunks -> strictly grounded prompt (answer only from supplied excerpts; cite `[n]`; British English / sentence case; refuse gracefully if evidence is insufficient) -> `OpenAI::chat()` reusing `analysis.model`/temperature conventions -> answer + citations (sermon + exact timestamp + snippet).
- `app/Livewire/Members/AskArchive.php` (+ Blade view) — class+view format, under the members area (`auth` + `verified`). MVP: loading state -> complete answer. (Not an admin component; no `WithAdminAuthorization`.)
- `archive-qa` rate limiter in `RateLimitServiceProvider`, keyed **per authenticated user**, plus a per-user daily budget (`qa.per_user_daily_budget`) and an app-level cap.
- Copyright guard: answers quote transcript, never re-publish licensed Bible text; respect `scripture_passages.copyright` / FUMS.

**Tests (safety is the deliverable):** with `OpenAI::fake()` — answer cites supplied excerpts; **refuses** when excerpts are irrelevant (anti-hallucination); British-English assertion; per-user rate-limit + daily-budget enforcement; unauthenticated access redirected; Livewire happy/empty/error states.

**Risk:** medium — LLM surface + cost, but bounded by members-only access + per-user limits + flag.

---

## Phase 5 - Related sermons, streaming, SEO recovery (polish)

**Goal:** compound the asset; add the deferred niceties.

**Changes**
- Per-sermon centroid (element-wise mean of chunk embeddings — the `computeCentroid()` idea) -> public "Related sermons" on the show page, cached.
- SSE streaming for Q&A answers (the deferred half of Decision 5), reusing the Phase 3 stream pattern.
- Optional SEO recovery: **editorially-curated public topic pages** (human-reviewed answers + citations) with `QAPage` JSON-LD — recovers public SEO value without exposing live generation.
- Feedback: thumbs up/down on answers to inform tuning.

**Risk:** low — additive, cache-backed.

---

## Cross-cutting concerns

- **Feature flag** `archive_search.enabled` gates dispatch and every surface — dark-ship safe.
- **Cost model:** (1) re-transcription backfill — one-off; prefer local Whisper to avoid per-minute API cost. (2) embedding backfill — one-off, tiny on `text-embedding-3-small`. (3) per-Q&A — one small embed + one token-capped chat completion, bounded by per-user daily budget + app cap. Search embeds the query only.
- **Mock parity:** deterministic mock embeddings keep the parallel suite offline; semantic quality is validated via the benchmark harness on real data and a non-CI check.
- **Accessibility & mobile** per the design workflow (loading / empty / error / success, keyboard + focus) before finishing UI phases; activate the `frontend-design` skill.
- **Observability:** reuse the structured logging patterns (`SermonProcessingLogger` style) for transcription, ingestion, and query paths.

## SEO note (consequence of Decision 2)

Live Q&A is members-only, so its answers are not indexable. Public SEO value comes from: the semantic search capability (raw query pages `noindex`'d), related-sermons internal linking, and — if desired later — curated public topic pages (Phase 5). This is a deliberate trade for lower cost/abuse risk and tighter answer-quality control.

## Suggested PR stack (merge in order)

```text
P0 foundations
  -> P1 timestamped transcription + re-transcribe backfill
  -> P2 embedding ingestion + backfill + dimension benchmark (LOCK dims)
  -> P3 public semantic search
  -> P4 members-only Ask-the-Archive (non-streamed)
  -> P5 related sermons + streaming + curated topic pages
```
Note: the P1 re-transcription *batch run* is background compute and can proceed in parallel with P2/P3 *development*; data just needs to be ready before each surface goes live.

## Quality gates (every PR)

1. Focused tests for the changed behaviour (mock embeddings; `OpenAI::fake()` for the Q&A; existing transcription tests green for P1).
2. `vendor/bin/sail composer phpstan` — 0 errors.
3. `vendor/bin/sail bin pint --dirty`.
4. `vendor/bin/sail artisan test --parallel --compact`.
5. `vendor/bin/sail artisan dusk` for UI phases (P3/P4).

## Risks & mitigations (summary)

- **Live-pipeline regression (P1)** -> config-gated transcription path, mock/local/openai seam, full transcription suite, staged rollout.
- **Hallucination / doctrinal trust (P4)** -> strict extractive prompt + mandatory refusal tests + visible citations.
- **Cost / abuse (P4)** -> members-only + per-user rate limiter + per-user daily budget + app cap + flag.
- **Bible-text copyright** -> quote transcript, not licensed passages; respect `scripture_passages.copyright`/FUMS.
- **Re-transcription cost (P1)** -> local Whisper for the backfill; background batch.
- **Unfiltered Q&A scan latency** -> dimension benchmark + lock (P2); facet pre-filter on the search path.
