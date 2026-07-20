# Song Scripture & Theme Search (2026-07-20) — scripture lookup, theme browsing & semantic lyric search for the song catalogue

> **Status (2026-07-20): approved, not started.** No dependencies on the July simplification
> backlog — songs never touch the media pipeline, so nothing here waits for Workstreams 1/2 or
> items 2.3/1.7a. Safe to start any time.
>
> **Cross-plan contract:** this plan now carries the **shared embedding foundations** (Phase 0)
> and the **shared `themes` table** (Phase 5) that
> [SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md)
> previously designed for itself. When the sermon plan starts, its Phase 0 is already built (it
> adds only the sermon-specific `TranscriptChunker` + chunk table), and its Phase 4 reuses the
> `themes` vocabulary via a `sermon_theme` pivot rather than creating `sermon_themes`. An
> amendment recording this is in that plan's amendment record.
>
> **What an agent must not do without maintainer input:** flip any user-facing flag before the
> maintainer has signed off the corresponding calibration report (scripture tagging in Phase 2,
> theme assignment in Phase 5); author the theme vocabulary (maintainer-curated); choose the
> final curated-data import columns without confirming against the data the maintainer actually
> has (Praise! scripture index or similar).

## Goal

Make it easy for someone planning a service to find a song relevant to what will be preached —
whether they start from **a Bible passage** ("we're in John 3 on Sunday"), **a theme**
("assurance"), or **a free-text idea** ("God's faithfulness in suffering"). All surfaces are in
the members area, where the song catalogue already lives.

Three compounding navigation features on the existing song catalogue:

1. **Scripture search** — type a reference into the existing search box (or arrive via a chip);
   get songs that relate to that passage, from three blended sources: curated data, offline LLM
   tagging, and embedding similarity against a public-domain Bible text.
2. **Theme browsing** — a curated theme vocabulary (shared with the future sermon theme pages)
   with theme chips filtering the catalogue.
3. **Semantic lyric search** — the existing keyword search box gains a meaning-based band, so
   "songs about grace in weakness" works even when no lyric contains those words.

Plus **related songs** and scripture/theme chips on each song's show page.

## Decisions locked (2026-07-20, maintainer)

1. **Scripture↔song relevance is a mix of three mechanisms (A + B + C); service-history
   co-occurrence is excluded** (maintainer judged it unlikely to help).
   - **(A) Embedding similarity against a public-domain Bible text** — catches paraphrase
     matches at arbitrary verse granularity, fully automatic. Never displayed; matching only.
   - **(B) Offline LLM tagging** — a batch command reads each song's lyrics and emits
     *structured* scripture references (book/chapter/verse-range + confidence) into
     `song_scripture_references`. Handles allusion ("Rock of Ages" ↔ Exodus 33) that
     embeddings miss. Calibration report reviewed by the maintainer before the flag flips.
   - **(C) Curated data** — maintainer-provided references imported into the same table with
     `source = curated`, which **outranks and overrides** `source = ai` rows for the same song.
2. **Theme vocabulary is shared with sermons and LLM-assigned.** One `themes` table
   (maintainer-curated names + descriptors, order of 15–30) serves songs now and sermons later.
   Songs are assigned by an offline LLM **closed-set** classification against the vocabulary
   (the model may only pick from the curated list — no invented themes), with a maintainer
   calibration pass before launch.
3. **Surfaces: members `BrowseSongs` page and the song show page.** No public exposure (the
   song catalogue is behind `auth`+`verified` and stays there; public surfaces would raise
   CCLI/lyrics display questions). The admin service-planning screen is explicitly out of scope
   for now — it can consume the same services later if wanted.
4. **Semantic free-text search is a core requirement, built from the start.** The shared
   embedding infrastructure (sermon plan Phase 0) is therefore built *here*, with songs as its
   first consumer. The keyword search path remains the flag-off / embedding-failure fallback
   and is never deleted.
5. **Vector store — JSON + PHP cosine** (inherited from the sermon plan's Decision 1; MySQL
   8.0.45 has no native vectors). At 1,151 song vectors a full PHP cosine scan is trivial —
   no pre-filtering or dimension benchmarking needed for the song corpus (the sermon plan
   still benchmarks before locking dimensions for its much larger chunk corpus).
6. **Retrieval-only, consistent with the sitewide policy** ([no AI-generated public
   answers](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md)): nothing user-facing is
   LLM-generated at request time. The LLM's offline outputs are *structured navigation
   metadata* (scripture references, theme memberships) — inspectable rows, not prose — and
   each batch is gated on a maintainer calibration review.

### Residual sub-decisions (settle during build, non-blocking)

- **Which public-domain translation to embed** *(settle in Phase 3)*. Leading recommendation:
  **World English Bible (WEB)** — public domain, modern vocabulary closer to contemporary
  song lyrics. A quick spike should compare WEB vs KJV retrieval on a handful of known
  hymn↔passage pairs (older hymn vocabulary may actually match KJV better); pick one, note
  the loser in the plan. Source: ebible.org distributions (no API, no key, importable file).
- **Curated import format** *(settle in Phase 2)*: confirm the columns of whatever data the
  maintainer provides (likely song title/number → reference list) before writing the importer.
- **Blend weighting between tagged and embedding tiers** *(settle in Phase 4 calibration)*:
  tagged matches always rank above the embedding band; the open question is only the embedding
  band's `min_score` cut-off.

## Background — the asset base

- **1,151 songs, every one with `lyrics_plain`** (avg ~780 chars). No transcription gap, no
  chunking: one embedding per song. This corpus is *far* cheaper to make semantic than the
  sermon archive.
- **Current search** (`PublicSongCatalogService`): tokens ANDed across title / alternate title
  / author / CCLI / lyrics (MySQL fulltext `MATCH AGAINST`), two-bucket ordering
  (title matches first), lyric snippets via `SongLyricSnippetBuilder`. Members-only at
  `/church/songs` (`BrowseSongs` Livewire component, `#[Url(as: 'q')]`).
- **Scripture plumbing**: `ScriptureReferenceResolver` (TechWilk parser — normalises and
  yields verse spans, already handles subranges/multi-part refs), `sermon_scripture_filters`
  (book+chapter per sermon), `scripture_passages` (api.bible cache — **licensed text**, not
  reusable for embedding; hence the public-domain corpus in Phase 3).
- **AI binding pattern**: `AiServiceProvider` binds mock/local/openai implementations from
  `config('media-processing.*.service')`. The OpenAI SDK is installed
  (`OpenAI::embeddings()->create()` is available but unused).
- **Cosine/centroid precedent**: `ResemblyzerSpeakerIdentificationService` stores JSON vectors
  and ranks by `cosineSimilarity()` — Phase 0 extracts this into `VectorMath`.
- **Sync hook**: `SongCatalogSyncService` upserts songs from OpenLP exports **without firing
  observers you can rely on** (cf. the sermon scripture-filter sync lesson) — embedding
  refresh must be hooked explicitly inside the sync path, keyed on a lyrics hash.

## Architecture overview

```text
OFFLINE (batch commands + queued jobs; all LLM/embedding work lives here)

  Song lyrics ──> EmbedSongLyrics job ──> song_embeddings (1 vector per song)
                                             │
  PD Bible text ──> bible:import ──> bible_verses
                 ──> bible:embed-windows ──> bible_embedding_windows
                        (chapter + overlapping verse windows, embedded once)

  Song lyrics ──> songs:tag-scripture (LLM, structured refs + confidence)
  Curated CSV ──> songs:import-scripture-references (source=curated, wins)
                                             │
                                             v
                              song_scripture_references

  Maintainer vocabulary ──> themes table
  Song lyrics ──> songs:assign-themes (LLM closed-set vs vocabulary)
                                             │
                                             v
                                        song_theme pivot

REQUEST TIME (members area; zero LLM calls; one embedding call only for free-text q)

  q parses as scripture reference?
    yes ──> SongScriptureSearchService
              tier 1: tagged matches (curated > ai, verse-overlap tightness, confidence)
              tier 2: "possibly related" — passage embedding (averaged from precomputed
                      windows; NO api call) cosine-ranked vs song_embeddings
    no  ──> keyword search (existing, untouched) as band 1
            + semantic band: embed(q) [one API call, cached + rate-limited]
              cosine vs song_embeddings, deduped against band 1

  theme chip ──> song_theme pivot filter (plain query, precomputed)
  song show page ──> scripture chips + theme chips + related songs
                     (cosine vs other song embeddings, cached)
```

## Phase 0 — Shared embedding foundations *(subsumes sermon plan Phase 0, minus chunker)*

**Goal:** the embedding contract, config, and vector maths, built generic so the sermon plan
consumes them unchanged.

**Changes**

- `config/media-processing.php`: `embeddings` block — `service` (`mock|openai`), `model`
  (`text-embedding-3-small`), `dimensions` (default 1536; the song corpus needs no benchmark),
  API-key fallback. Also a `song_search` block: `semantic_enabled`, `scripture_enabled`,
  `themes_enabled` (three independent flags so surfaces dark-ship separately), `top_k`,
  `min_score`, `related_top_n`, `tagging.model`, `bible.translation`.
- `app/Contracts/EmbeddingServiceInterface.php` — `embed(string $text): array`,
  `embedBatch(array $texts): array`.
- `app/Services/Media/Embeddings/OpenAiEmbeddingService.php` — wraps
  `OpenAI::embeddings()->create()`; typed retryable/non-retryable exceptions cloned from
  `AudioTranscriptionService`.
- `app/Services/Media/Embeddings/MockEmbeddingService.php` — deterministic pseudo-vector
  seeded from `crc32($text)`, L2-normalised, configured dimension. CI never calls OpenAI.
- Bind in `AiServiceProvider` with the existing `match`/config shape.
- `app/Support/VectorMath.php` — `cosine()` (+ zero-vector guard) and `centroid()`; switch
  `ResemblyzerSpeakerIdentificationService` to it so cosine lives in one place.

**Tests:** mock determinism + dimension; cosine/centroid correctness incl. zero-vector guard;
binding resolves by config; speaker-ID suite still green after the `VectorMath` switch.

**Risk:** very low — no user-facing change; the only touched production path is the
speaker-ID cosine swap, covered by its existing tests.

---

## Phase 1 — Song embeddings + backfill

**Goal:** every song has one embedding, kept fresh as the catalogue syncs.

**Changes**

- Migration `song_embeddings`: `song_id` (FK cascade, unique), `embedding` (JSON),
  `embedding_model`, `embedding_dimensions`, `lyrics_hash`, `embedded_at`. Sidecar table (not
  columns on `songs`) so the ~15–30 KB vector never rides along on catalogue queries.
- `app/Jobs/EmbedSongLyrics.php` — embeds `title + "\n" + lyrics_plain` (title carries real
  signal for songs), upserts the row, idempotent, skips when `lyrics_hash` unchanged. Gated by
  any of the three `song_search` flags being on.
- Hook in `SongCatalogSyncService`: after upsert, dispatch when the lyrics hash changed
  (explicit call, not an observer — the sync path bypasses observer-driven invalidation).
- `songs:embed-backfill` command (mirrors `BootstrapSpeakerProfilesCommand`): `--limit`,
  `--song=`, dry-run; processes songs with no row or a stale `lyrics_hash`.
- `app/Services/Song/SongEmbeddingMatrix.php` — loads all song vectors once, caches the
  decoded matrix (plain arrays — no Eloquent models in the cache), versioned cache key bumped
  on re-embed; this is the scan target for every cosine ranking in later phases.

**Tests:** job idempotency + hash-skip; sync hook dispatches only on lyric change; backfill
scope; matrix cache invalidation; flag-off = no dispatch.

**Cost note:** 1,151 songs × ~200 tokens on `text-embedding-3-small` — well under $0.01,
one-off. Re-embeds only on lyric edits.

**Risk:** low — additive data, no UI.

---

## Phase 2 — Scripture reference data (LLM tagging + curated import)

**Goal:** a reviewed, queryable song↔scripture map with curated rows outranking AI rows.

**Changes**

- Migration `song_scripture_references`: `song_id` (FK cascade), `bible_book`,
  `bible_chapter`, `verse_start` (nullable), `verse_end` (nullable), `normalized_reference`
  (display form from `ScriptureReferenceResolver::normalizeAll()`), `source`
  (enum `curated|ai`), `confidence` (nullable float, AI only), `source_lyrics_hash`
  (AI only — marks rows stale when lyrics change), timestamps. Index
  `(bible_book, bible_chapter)`; unique `(song_id, bible_book, bible_chapter, verse_start,
  verse_end, source)`. Multi-chapter references store one row per chapter span, mirroring
  `sermon_scripture_filters` granularity while keeping verse precision.
- `songs:tag-scripture` command — batches songs through the configured LLM
  (`song_search.tagging.model`) with structured output: list of references + confidence.
  Every returned reference is validated through `ScriptureReferenceResolver` — unparseable or
  hallucinated book names are dropped and counted. `--dry-run` writes a **calibration report**
  (per-song reference list + confidence histogram + rejection counts) for maintainer review;
  `--stale` re-tags only songs whose `source_lyrics_hash` no longer matches. Never touches
  `curated` rows.
- `songs:import-scripture-references` command — imports the maintainer's curated data (format
  confirmed against the actual data first — residual sub-decision). Curated rows replace AI
  rows for the same song+passage on read (precedence enforced in the query service, Phase 4).
- **Gate:** maintainer reviews the dry-run calibration report and picks a confidence floor
  before the real tagging run.

**Tests:** reference validation drops junk; row shaping for multi-chapter/verse-range inputs;
idempotent re-run; `--stale` scope; curated import + precedence; mock LLM fixture parity.

**Cost note:** ~1,151 short completions on a mini-class model — a few pounds, one-off;
re-runs only for changed lyrics.

**Risk:** low-medium — the real risk is **over-generous AI tagging** (every song vaguely
"relates to" Psalm 23). Mitigations: confidence floor from the calibration report, per-song
reference cap, curated overrides, and the Phase 4 UI labelling tiers honestly.

---

## Phase 3 — Public-domain Bible corpus + passage embeddings

**Goal:** any parseable reference can be turned into a passage embedding with **zero
request-time API calls**, powering the "possibly related" tier and verse-arbitrary lookup.

**Changes**

- `bible:import {path}` — imports a public-domain translation (WEB recommended; KJV spike per
  the residual sub-decision) into `bible_verses` (`translation`, `bible_book`, `chapter`,
  `verse`, `text`). Source file from ebible.org checked into storage or downloaded at run
  time — **never** the api.bible licensed cache in `scripture_passages`.
- `bible:embed-windows` — precomputes embeddings into `bible_embedding_windows`
  (`translation`, `bible_book`, `chapter`, `verse_start`, `verse_end`, `embedding` JSON,
  model/dimensions columns): one window per chapter plus overlapping ~6-verse windows
  (stride 3). Idempotent; `--translation=`.
- `app/Services/Scripture/PassageEmbeddingService.php` — given a normalised reference:
  find windows overlapping the verse span (via the resolver's spans), average them
  (`VectorMath::centroid`), return the vector. Whole-chapter/book requests average chapter
  windows. Pure lookup — no API call.

**Tests:** import row counts/spot verses; window coverage (every verse in ≥1 window);
overlap-lookup correctness incl. cross-chapter spans; averaging; unknown reference → null.

**Cost note:** whole Bible ≈ 1M tokens; chapter + verse windows ≈ 2.5× that on
`text-embedding-3-small` — pennies, one-off per translation.

**Risk:** low — offline, additive. Storage note: ~10k window vectors × ~15 KB JSON ≈ 150 MB;
acceptable, but confirm dimensions choice (512 would quarter it) when running the WEB/KJV
spike — the corpus embeds in one cheap pass either way, so re-running is painless.

---

## Phase 4 — BrowseSongs search UI (scripture mode + semantic band)

**Goal:** the first user-facing win: one search box that understands references, keywords,
and meaning.

**Changes**

- `app/Services/Song/SongScriptureSearchService.php` — parse `q` via
  `ScriptureReferenceResolver`; **tier 1**: `song_scripture_references` overlap query, ranked
  curated-first, then verse-overlap tightness (verse-exact above chapter-level), then
  confidence; **tier 2**: passage embedding (Phase 3) cosine-ranked against
  `SongEmbeddingMatrix`, `min_score` floor, deduped against tier 1, capped.
- `app/Services/Song/SongSemanticSearchService.php` — embed the query (one API call; result
  cached by normalised query string ~24 h), cosine vs the matrix, floor + cap.
- `BrowseSongs` integration: if `q` parses as a reference **and** `scripture_enabled`, render
  scripture mode — a banner ("Songs for *John 3:16–18*"), tier 1 results, then a clearly
  labelled "Possibly related" band. Otherwise the existing keyword search renders untouched
  as band 1, with a "Related by meaning" band appended when `semantic_enabled` (deduped).
  Keyword-only behaviour is byte-identical when both flags are off, and is the automatic
  fallback when embedding calls fail.
- `song-search` rate limiter in `RateLimitServiceProvider`, keyed per user (members-only —
  user id, not IP), budgeting the query-embedding spend. Scripture mode costs nothing and is
  not limited.
- Activate the `frontend-design` skill for the UI work; loading/empty/error states; British
  English, sentence case ("Possibly related", "Related by meaning").

**Tests:** reference detection routing (incl. strings that half-look like references —
"Amazing grace 3" must stay keyword); tier ordering with deterministic mock embeddings;
curated-over-ai precedence; dedupe between bands; rate limiter; flags off = current
behaviour byte-for-byte (regression snapshot of the existing component tests); embedding
failure falls back silently to keyword. Dusk: reference query → scripture mode renders;
free-text query → both bands; chip navigation.

**Risk:** medium — first user-facing surface, touches the existing search path. Mitigated by
the two independent flags, the untouched keyword default, and fallback-on-failure.

---

## Phase 5 — Shared themes (vocabulary + LLM assignment + browse UI)

**Goal:** theme navigation on the song catalogue, on a vocabulary the sermon plan will share.

**Changes**

- Migrations: `themes` (`name`, `slug`, `descriptor` text, `descriptor_embedding` nullable
  JSON — populated so the sermon plan's embedding-assignment mechanism works against the same
  rows later) and `song_theme` pivot (`theme_id`, `song_id`, `source` enum `curated|ai`,
  `confidence` nullable). **Named for sharing**: the sermon plan adds `sermon_theme` against
  this same `themes` table (contract recorded in both plans).
- Vocabulary seeder/command from a maintainer-authored list (order of 15–30 themes, name +
  one-to-two-sentence descriptor). No auto-generated themes, ever.
- `songs:assign-themes` command — LLM **closed-set** classification: the prompt lists the
  vocabulary; the model returns applicable slugs + confidence per song; unknown slugs are
  rejected and counted. `--dry-run` calibration report (per-theme song lists + counts) for
  maintainer sign-off; threshold + per-theme caps; idempotent; never touches `curated` pivot
  rows (maintainer hand-corrections survive re-runs).
- UI (`themes_enabled`): theme chips/select on `BrowseSongs` with `#[Url]` `theme` param
  filtering the catalogue query; theme chips on cards optional. Composes with range/search.
- **Gate:** maintainer reviews the calibration report and tunes descriptors/threshold before
  the flag flips.

**Tests:** closed-set rejection of invented slugs; assignment idempotency + curated-row
preservation; pivot filter query; URL param round-trip; flag-off hides everything. Dusk:
chip → filtered list → song.

**Risk:** low-medium — additive and precomputed; the mis-filing risk is the same as the
sermon plan's Phase 4 and gets the same mitigation (curated vocabulary, threshold, caps,
human calibration).

---

## Phase 6 — Song show page: scripture chips, theme chips, related songs

**Goal:** compound the data on every song page and deepen catalogue navigation.

**Changes**

- Song show page (`church.songs.show`): scripture reference chips (each linking back to the
  catalogue in scripture mode — `/church/songs?q=John+3`), theme chips (linking to the theme
  filter), and a "Related songs" strip — top-N cosine neighbours from `SongEmbeddingMatrix`,
  cached per song, invalidated by the matrix version key.
- Respect the per-surface flags: each block renders only when its flag is on and data exists.

**Tests:** chip links round-trip to the right catalogue state; related-songs ranking with
mock embeddings; cache invalidation on re-embed; flags off = current page unchanged.

**Risk:** low — additive, cache-backed.

---

## Suggested PR stack (merge in order)

```text
P0 shared embedding foundations (VectorMath + interface + mock/openai + config)
  -> P1 song embeddings + backfill + matrix
  -> P2 song_scripture_references (LLM tagging + curated import + calibration gate)
  -> P3 PD Bible corpus + window embeddings + PassageEmbeddingService
  -> P4 BrowseSongs UI (scripture mode + semantic band + rate limiter)
  -> P5 shared themes (vocabulary + assignment + chips)
  -> P6 song show page (chips + related songs)
```

Notes: P2 and P3 are independent of each other (both need P1's flags/infra conceptually but
neither needs the other); P4 needs all of P1–P3. P5 needs only P0/P1 and can run in parallel
with P2–P4 development. P6 needs P1 (+P2/P5 data for its chips, degrading gracefully without).
The offline batch runs (backfill, tagging, Bible embedding) are background compute and can
proceed while later PRs are built — data just has to be ready before each flag flips.

## Cross-cutting concerns

- **Flags:** `song_search.scripture_enabled` / `semantic_enabled` / `themes_enabled` gate
  every surface independently — each phase dark-ships.
- **Cost model:** all one-off/offline except the free-text query embedding (one small call per
  uncached search, rate-limited per member, cached 24 h by query). Scripture mode, themes,
  and related songs cost **zero** API calls at request time.
- **Mock parity:** deterministic mock embeddings + fixture-driven mock LLM tagging keep the
  parallel suite fully offline; semantic quality is validated by the two maintainer
  calibration reports, not CI.
- **Copyright:** song lyrics stay members-only (unchanged). The embedded Bible text is public
  domain and never displayed; the licensed `scripture_passages` cache is untouched. Displayed
  scripture *references* are facts, not licensed text.
- **British English + sentence case** in all user-facing strings.
- **Cache safety:** `SongEmbeddingMatrix` and related-songs caches hold plain arrays only —
  never Eloquent collections (per the Spatie-media cache-leak lessons).
- **Observability:** structured logging on the batch commands (counts, rejections, spend
  estimates) in the `SermonProcessingLogger` style.

## Quality gates (every PR)

1. Focused tests for the changed behaviour (mock embeddings / mock LLM fixtures).
2. `vendor/bin/sail composer phpstan` — 0 errors.
3. `vendor/bin/sail bin pint --dirty`.
4. `vendor/bin/sail artisan test --compact --parallel`.
5. `vendor/bin/sail artisan dusk` for UI phases (P4/P5/P6).

## Risks & mitigations (summary)

- **Over-generous AI scripture tags** → calibration report + confidence floor + per-song cap
  + curated overrides + honest tier labelling in the UI.
- **Weak embedding matches for allusive hymns** → that's what the tagged tier is for; the
  embedding tier is explicitly the *second* band, labelled "Possibly related".
- **Existing search regression** → flags default off; keyword path untouched and asserted
  byte-identical; embedding failure falls back to keyword.
- **Query-embedding spend/abuse** → per-user `song-search` limiter + 24 h query cache +
  members-only surface.
- **Vocabulary drift vs the sermon plan** → single `themes` table + the cross-plan contract
  recorded in both plans; sermon plan consumes, never forks.
- **Sync staleness** → lyrics-hash keyed re-embed hooked explicitly in
  `SongCatalogSyncService` (observers are bypassed on sync); `--stale` re-tagging for AI
  scripture rows.
