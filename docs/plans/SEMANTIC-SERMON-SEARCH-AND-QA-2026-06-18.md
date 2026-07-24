# Semantic Sermon Archive (2026-06-18, amended 2026-07-20) - Semantic Search, Theme Browsing & Related Sermons

> **Amended (2026-07-20): the Q&A surface is removed by maintainer decision.** This plan is now
> **retrieval-only**: every surface navigates the visitor to a relevant sermon (or a timestamped
> extract of one) and shows only verbatim transcript text and existing sermon metadata. Nothing
> user-facing is AI-generated at request time. The former Phase 4 ("Ask the Archive" grounded
> Q&A) is deleted, and theme-based browsing — previously an optional Phase 5 afterthought — is
> promoted into its place as a first-class navigation surface.

> **Gate update (2026-07-24): both backlog gates have cleared.** Item 2.3 (storage collapse)
> completed 2026-07-13 and item 1.7a landed 2026-07-21 — `CreateSermonTranscriptFromService`
> (`f332427ea`) now slices the full-service transcript for the sermon instead of re-transcribing,
> exactly as anticipated. **The Phase 1 re-plan this header demands is therefore now the next
> action**, and it should be written against `CreateSermonTranscriptFromService` +
> `ChurchServiceTranscript::sliceText()` rather than against `TranscriptionServiceInterface`.
>
> Two new dependencies that did not exist when this was drafted:
>
> - **Phase 0's embedding foundations and the shared `themes` table are now specified in**
>   [SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md).
>   If that plan runs first, this one inherits both; neither exists in the schema yet (verified
>   2026-07-24 — no themes or embeddings migration).
> - [HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md](HISTORIC-ARCHIVE-IMPORT-AND-PROMOTION-2026-07-24.md)
>   WP-A1/WP-A3 decide whether full-service transcripts and word-level timestamps are **retained at
>   all** (today the full-service transcript is swept 24h after each run). The archive backfill this
>   plan needs is materially cheaper if that plan's retention work lands first.
>
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
> - The Decisions Locked and Phases 0/2–5 remain valid as written (as amended 2026-07-20).

## Recommendation

Build a **semantic retrieval layer over the existing sermon transcript corpus**, surfaced as three compounding navigation features — all public, all retrieval-only:

1. **Natural-language search** on the public sermons page ("sermons on forgiveness", "what does the Bible say about anxiety?") — results are sermons, each with a verbatim best-matching transcript snippet and an exact "jump to mm:ss" deep link.
2. **Browse by theme** — a curated theme vocabulary (e.g. prayer, suffering, assurance) with per-theme pages listing the sermons that most strongly teach on that theme. Sermons are assigned to themes by embedding similarity against curated theme descriptors; the pages show only sermon metadata and verbatim extracts.
3. **"Related sermons"** recommendations on each sermon page.

This is the highest-leverage addition available right now because it is **accretive**: it compounds the single largest existing investment in the codebase — a large, growing set of sermons that each already carry a clean transcript, AI summary, key points, an extracted scripture index, a preacher, a series, a date, and section-level timestamps. Today none of that content is searchable by meaning; the public archive (`BrowseSermons`) supports only faceted filtering (book / chapter / preacher / series). The missing keystone is a *text* embedding.

It scores highest on every axis: accretive (reuses the biggest asset), useful (serves seekers and members alike as a way *into* the preaching, and the preaching team as a research tool), and mission-aligned. Because every surface is a public navigation page over our own content, the whole feature set is indexable — theme pages in particular are a straightforward SEO win (topic landing pages with internal links into the archive).

**What this plan deliberately does not build:** any surface where an LLM composes prose shown to a visitor. No question-answering, no generated summaries-of-search-results, no chat. The AI involvement is confined to (a) transcription (already in production), (b) computing embeddings offline, and (c) the existing per-sermon analysis metadata. Visitors only ever read what a preacher actually said, or metadata the site already publishes.

## Decisions Locked (2026-06-18, revised 2026-07-20)

1. **Vector store — JSON + PHP cosine, with facet pre-filtering** *(settled by environment, not preference)*. The database is **MySQL 8.0.45**; native `VECTOR` columns + `VEC_DISTANCE` are a MySQL 9.0 feature. Upgrading the production DB engine purely for a vector index is out of proportion to this feature. We reuse the exact pattern the speaker-ID service already runs (`ResemblyzerSpeakerIdentificationService::cosineSimilarity()`).
2. **Retrieval-only; no generated answers** *(2026-07-20, supersedes the original Decision 2 "public search, members ask" and Decision 5 "non-streamed Q&A MVP")*. There is no Q&A surface, streamed or otherwise, members-only or otherwise. All surfaces are public navigation pages. This removes the members-area gating, the per-user Q&A budgets, the grounded-prompt machinery, and the anti-hallucination test burden from the plan entirely.
3. **Deep links — exact from the start.** No approximate/proportional timestamps. This requires a **timestamp-capable transcription path** and a **re-transcription pass over the existing archive** (Phase 1). Chunks become segment-aligned with real `start_time`/`end_time`.
4. **Embedding dimensions — benchmark, then lock.** Ingestion is built dimension-agnostic; we backfill on real data, benchmark candidate dimensions (e.g. 512 vs 1536) for latency + retrieval quality, then lock `embeddings.dimensions` before any user-facing surface ships.

### Residual sub-decisions (settle during build, non-blocking)

- **Which transcription model provides the timestamps** *(settle in Phase 1)*. Leading recommendation: use the existing **local Whisper path** (`LocalWhisperTranscriptionService`, already config-bound in `AiServiceProvider`) — local Whisper emits native segment/word timestamps and incurs no per-minute API cost for an ~800-sermon backfill. A Phase 1 spike confirms its timestamp output and decides whether it replaces, or runs alongside, `gpt-4o-transcribe` for new sermons (the current model was chosen for transcription quality but does not return `verbose_json` segment timestamps).
- **Theme assignment mechanism** *(settle in Phase 4)*. Leading recommendation: a **curated theme vocabulary** — the maintainer authors each theme's name and a one-to-two-sentence descriptor; the descriptor is embedded once; sermons are assigned by cosine similarity between the theme embedding and the sermon centroid (with a tunable threshold), precomputed into a pivot table by a re-runnable command. This keeps theme membership deterministic, inspectable, and free of generated text. The alternative (unsupervised clustering of sermon centroids with human labelling afterwards) is a fallback if curated descriptors prove too coarse.

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
- **SEO machinery**: `SermonArchiveSeoPresenter`, JSON-LD presenters, sitemaps.

### Corpus shape (measured 2026-06-18)
- **818 sermons** in the archive.
- Only **4 transcribed in the local dev DB** (the `sermons_prod_import` table is empty locally); transcripts are generated going forward and backfilled. Plan for hundreds -> low-thousands of sermons ≈ tens of thousands of chunks at full coverage. The search path can pre-filter on facets, but theme assignment and related-sermons compute over the whole corpus (offline), and an unfiltered search query cosine-scans everything — which is why the embedding-dimension benchmark (Decision 4) matters.

### The gap
The substance of the preaching is locked inside transcript files that nothing queries semantically. The archive is browsable by *metadata facets* only — never by *content* or *theme*.

## Key technical findings that shape the plan

1. **Embeddings require no new dependency.** They ride on the already-installed OpenAI SDK (`OpenAI::embeddings()->create()`), the existing config + interface-binding pattern (`AiServiceProvider`), and the existing JSON-embedding + cosine pattern (`ResemblyzerSpeakerIdentificationService`).
2. **MySQL 8.0.45 fixes the vector store** to JSON + PHP cosine with facet pre-filtering (Decision 1).
3. **Exact deep links require upgrading transcription.** The current path (`AudioTranscriptionService::transcribeFile()`, `gpt-4o-transcribe`, `response_format => 'text'`) discards timing. Decision 3 adds a timestamp-capable transcription path and a re-transcription backfill, and in return lets us chunk along real segment boundaries (more accurate retrieval than character-offset estimation).
4. **The existing facet filters double as a vector pre-filter.** Pre-filtering candidate sermons by book / preacher / series / date (via `SermonRepository` / `SermonBuilder`) before brute-force cosine keeps PHP-side similarity cheap on the search path. Theme pages and related-sermons are precomputed/cached, so their whole-corpus scans happen offline, not per-request.

## Goal

Turn the sermon library into a body of teaching you can navigate by meaning:
- A meaning-based, public search box on the sermons page with exact "jump to mm:ss" deep links to the relevant extract.
- Public, indexable **theme pages** listing the sermons that teach on each curated theme.
- "Related sermons" on each public sermon page.
- All dark-shippable behind a feature flag, all tested with deterministic mock embeddings so CI never calls OpenAI.

## Non-Goals

- Do **not** generate any user-facing prose with an LLM (Decision 2). No Q&A, no chat, no generated answer or summary of search results — surfaces show verbatim transcript extracts and existing sermon metadata only.
- Do **not** replace the existing faceted browse — semantic search and themes augment it.
- Do **not** add a vector-database dependency or upgrade MySQL for this feature (Decision 1).
- Do **not** ship approximate deep links (Decision 3) — exact timestamps only.
- Do **not** re-publish licensed Bible text in snippets — quote sermon transcript (our content) and respect `scripture_passages.copyright` / FUMS.

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
        +--> per-sermon centroid (element-wise mean of chunk embeddings)
        |
        v
  Dimension benchmark on real data -> lock embeddings.dimensions

RETRIEVAL (all public, all navigation-only)
  SEARCH (per request):
    query -> embed(query) -> facet pre-filter -> cosine-rank chunks
          -> group by sermon -> best verbatim snippet + exact timestamp
          -> result cards deep-linking to sermon at mm:ss

  THEMES (precomputed offline):
    curated theme descriptor -> embed once
          -> cosine vs sermon centroids -> threshold -> sermon_theme pivot
          -> public theme index + per-theme pages (indexable)

  RELATED SERMONS (precomputed/cached):
    sermon centroid -> cosine vs other centroids -> top-N, cached
```

## Guiding principles (all observed in this codebase)

- **Reuse, don't add.** Embeddings via the installed SDK; cosine/centroid mirroring `ResemblyzerSpeakerIdentificationService`; mock/real binding mirroring `AiServiceProvider`; timestamped transcription via the existing local-Whisper binding; jobs + backfill mirroring `BootstrapSpeakerProfilesCommand`.
- **Ship as a stack of small PRs merged in order** (matching the service-UI consolidation #798->#813 pattern).
- **Every phase is independently shippable and flag-gated.** Phases 0-2 add data/pipeline with zero user-facing change.
- **Mock parity is mandatory** — deterministic mock embeddings so the parallel suite never calls OpenAI.
- **British English + sentence case** in every user-facing string (theme names, empty states, search UI).
- **Quality gates per PR:** focused tests -> `composer phpstan` (0 errors) -> `pint --dirty` -> parallel suite -> Dusk for UI changes.

---

## Phase 0 - Foundations (infra only, no UX)

**Goal:** the contract, config, storage, and chunker, each provably tested in isolation. Dimension-agnostic.

**Changes**
- `config/media-processing.php`: add an `embeddings` block (`service` = `mock|openai`, `model` = `text-embedding-3-small`, `dimensions` (set after benchmark), `openai_api_key` fallback) and an `archive_search` block (`enabled` feature flag, `top_k`, `min_score`, `chunk_tokens`, `chunk_overlap`, `themes.min_score`).
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
- Extend `TranscriptionServiceInterface` / the chosen service to return a **timestamped structure** (segments), not just markdown text. Persist via `TranscriptStorageService` (e.g. a JSON sidecar alongside the existing markdown), behind config so the current text path remains available. *(Per the status header: re-plan this phase against backlog 1.7a before starting — for new sermons the timestamped transcript falls out of 1.7a's full-service transcript, reducing this phase to the archive backfill.)*
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
- `sermons:embed-benchmark` harness — backfills a sample at candidate dimensions, measures scan latency + retrieval quality on held-out queries, reports a recommendation. **Lock `embeddings.dimensions`** before Phase 3.

**Tests:** correct chunk count + persisted embeddings (mock); idempotent re-run; backfill + benchmark scope; flag-off = no dispatch.

**Risk:** low — additive data; flag-gated; idempotent.

---

## Phase 3 - Public semantic search UI (first user-facing win)

> **Amended 2026-07-20:** [SITE-SEARCH-2026-07-20.md](SITE-SEARCH-2026-07-20.md) front-runs this
> phase's UI slot with a keyword (LIKE) search: `BrowseSermons` will already have
> `#[Url(as: 'q')] public string $q` with a 400 ms debounce, plus the `noindex` +
> canonical-without-`q` SEO handling, before this phase starts. This phase therefore **does not
> add** the search box, the URL param, or the SEO plumbing — it swaps the ranking backend
> (LIKE → `ArchiveSearchService`) behind `archive_search.enabled`, and must **keep the keyword
> LIKE path as the flag-off / embedding-failure fallback** rather than deleting it. The
> `archive-search` rate limiter still lands here (it protects the embedding spend introduced
> here, which the keyword path does not have).

**Goal:** a public search box on the sermons page, ranked by meaning, with exact "jump to mm:ss".

**Changes**
- `app/Services/Public/ArchiveSearchService.php` — embed query -> facet pre-filter via `SermonRepository`/`SermonBuilder` -> cosine-rank chunks -> group by sermon -> best verbatim snippet + exact timestamp per sermon.
- Extend `BrowseSermons` with `#[Url] public ?string $q` (`wire:model.live.debounce.400ms`). With `q` set, results come from `ArchiveSearchService`; otherwise the current faceted browse is untouched. Result card gains snippet + deep link.
- `archive-search` rate limiter in `RateLimitServiceProvider`, keyed per IP — each query costs one embedding API call, so the public box needs a modest budget (this replaces the removed Q&A limiter; search is the only per-request API spend left in the plan).
- SEO: search-result state `noindex` (or canonical to base archive) via `SermonArchiveSeoPresenter`.
- Player deep-link: extend the sermon player to honour a `?t=` seek param (Alpine). **Verify/implement during build.**

**Tests:** ranking/grouping with deterministic mock embeddings; Livewire search interaction; rate-limit enforcement; SEO noindex on `q`; candidate-filter query shape. Dusk: type query -> results update; click deep link -> player seeks.

**Risk:** medium — first public surface; mitigated by flag + the untouched faceted default path.

---

## Phase 4 - Browse by theme (curated vocabulary, precomputed assignment)

**Goal:** public, indexable theme pages — a second way into the archive that requires no query-writing from the visitor and doubles as SEO topic landing pages.

**Changes**
- Theme vocabulary: a `sermon_themes` table (`name`, `slug`, `descriptor` text, `descriptor_embedding` JSON) seeded/managed by the maintainer — start with a small curated list (order of 15–30 themes) rather than anything auto-generated. Admin CRUD can be a simple seeder/command at first; a Livewire admin screen only if the vocabulary churns.
- Assignment: `sermons:assign-themes` command (re-runnable, idempotent) — embeds each descriptor once, computes cosine against per-sermon centroids (see Phase 5 note: centroid computation moves here if Phase 4 ships first), writes `sermon_sermon_theme` pivot rows above `archive_search.themes.min_score`, with per-theme result caps. Re-run after backfills and threshold tuning; dry-run mode reports assignment counts per theme for calibration.
- Public UI: a theme index (chips/cards on or linked from the sermons page) and per-theme pages listing assigned sermons — standard sermon result cards, optionally each with its best-matching verbatim extract + deep link (reusing the Phase 3 snippet machinery). Fully indexable, with breadcrumbs and internal links; add to sitemap.
- SEO: theme pages get titles/meta via the existing presenter patterns; `CollectionPage`-style JSON-LD if it fits the existing JSON-LD presenter conventions.
- Calibration step before flipping the flag: maintainer reviews a dry-run assignment report (theme -> sermon list) and tunes `themes.min_score`/descriptors — this is the quality gate that replaces algorithmic trust with human sign-off, without any generated text.

**Tests:** assignment command scope/idempotency/threshold behaviour with mock embeddings; pivot integrity; theme page rendering + empty state; sitemap inclusion; flag-off = 404/hidden. Dusk: navigate theme index -> theme page -> sermon.

**Risk:** low-medium — additive, precomputed, flag-gated. The real risk is **mis-filed sermons** (a sermon listed under a theme it barely touches); mitigated by the curated descriptors, the similarity threshold, per-theme caps, and the maintainer calibration pass before launch.

---

## Phase 5 - Related sermons + polish

**Goal:** compound the asset on every sermon page.

**Changes**
- Per-sermon centroid (element-wise mean of chunk embeddings — the `computeCentroid()` idea, persisted on the sermon or a sidecar table) -> public "Related sermons" on the show page, cached. If Phase 4 shipped first, centroids already exist; otherwise they land here and Phase 4 consumes them.
- Internal-linking SEO win: related-sermons links deepen the archive's link graph alongside the Phase 4 theme pages.

**Risk:** low — additive, cache-backed.

---

## Cross-cutting concerns

- **Feature flag** `archive_search.enabled` gates dispatch and every surface — dark-ship safe.
- **Cost model:** (1) re-transcription backfill — one-off; prefer local Whisper to avoid per-minute API cost. (2) embedding backfill — one-off, tiny on `text-embedding-3-small`. (3) theme descriptors — a few dozen one-off embeds. (4) per-search — one small query embed, bounded by the `archive-search` rate limiter. Themes and related-sermons cost nothing per request (precomputed/cached). There is no per-request LLM completion anywhere in the plan.
- **Mock parity:** deterministic mock embeddings keep the parallel suite offline; semantic quality is validated via the benchmark harness on real data, the Phase 4 calibration report, and a non-CI check.
- **Accessibility & mobile** per the design workflow (loading / empty / error / success, keyboard + focus) before finishing UI phases; activate the `frontend-design` skill.
- **Observability:** reuse the structured logging patterns (`SermonProcessingLogger` style) for transcription, ingestion, and query paths.

## SEO note (consequence of Decision 2, revised)

With Q&A gone, the SEO story *improves*: everything this plan ships is public and indexable. Raw search-query states stay `noindex` (query-parameter pages are thin), but theme pages are purpose-built topic landing pages over our own content, and related-sermons adds internal links across the archive. No editorial-answer curation pipeline is needed — theme pages are lists of real sermons, so there is no generated prose to review before indexing.

## Suggested PR stack (merge in order)

```text
P0 foundations
  -> P1 timestamped transcription + re-transcribe backfill
  -> P2 embedding ingestion + backfill + dimension benchmark (LOCK dims)
  -> P3 public semantic search
  -> P4 browse-by-theme (vocabulary + assignment + public pages)
  -> P5 related sermons
```
Notes: the P1 re-transcription *batch run* is background compute and can proceed in parallel with P2/P3 *development*; data just needs to be ready before each surface goes live. P4 and P5 are independent of each other and can swap order — whichever ships first carries the centroid computation.

## Quality gates (every PR)

1. Focused tests for the changed behaviour (mock embeddings; existing transcription tests green for P1).
2. `vendor/bin/sail composer phpstan` — 0 errors.
3. `vendor/bin/sail bin pint --dirty`.
4. `vendor/bin/sail artisan test --parallel --compact`.
5. `vendor/bin/sail artisan dusk` for UI phases (P3/P4/P5).

## Risks & mitigations (summary)

- **Live-pipeline regression (P1)** -> config-gated transcription path, mock/local/openai seam, full transcription suite, staged rollout.
- **Re-transcription cost (P1)** -> local Whisper for the backfill; background batch.
- **Search latency at full corpus** -> dimension benchmark + lock (P2); facet pre-filter on the search path; themes/related precomputed offline.
- **Search-box API cost/abuse (P3)** -> per-IP `archive-search` rate limiter + flag.
- **Mis-filed theme assignments (P4)** -> curated descriptors, similarity threshold, per-theme caps, maintainer calibration report before launch.
- **Bible-text copyright** -> quote transcript, not licensed passages; respect `scripture_passages.copyright`/FUMS.

---

## Amendment record

- **2026-07-20 (later still)** — [SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md)
  approved: it builds this plan's **Phase 0 embedding foundations** (EmbeddingServiceInterface,
  mock/OpenAI services, `VectorMath::cosine` + the Resemblyzer switch, the `embeddings` config
  block) with songs as first consumer, and creates a **shared `themes` table** in its Phase 5.
  When this plan starts: Phase 0 reduces to the sermon-specific parts only (`TranscriptChunker`,
  `sermon_transcript_chunks`, the `archive_search` config block), and Phase 4 must add a
  `sermon_theme` pivot against the existing `themes` table instead of creating `sermon_themes`.
- **2026-07-20 (later)** — [SITE-SEARCH-2026-07-20.md](SITE-SEARCH-2026-07-20.md) approved: it
  ships keyword search in the exact UI slot Phase 3 was designed for (`q` param + debounced box
  on `BrowseSermons`, `noindex`/canonical SEO). Phase 3 is re-scoped to a backend swap behind
  `archive_search.enabled`, keeping the keyword path as the fallback (note added to Phase 3).
- **2026-07-20** — Maintainer decision: no AI-generated answers. Removed the members-only "Ask the Archive" grounded Q&A (former Phase 4), its Decisions 2 and 5, the `archive-qa` limiter and per-user budgets, SSE answer streaming, and the anti-hallucination test burden. Promoted theme browsing to Phase 4 as a first-class, precomputed, curated-vocabulary navigation surface. All remaining surfaces are retrieval-only: they navigate to a sermon or a timestamped extract and show only verbatim transcript text and existing metadata.
