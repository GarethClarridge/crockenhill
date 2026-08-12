# Song scripture, theme, and semantic discovery

> **Status (2026-08-12): approved direction, not started.** Reordered to ship exact, inspectable
> navigation before embedding infrastructure. Scripture and theme discovery are independent
> releases. Semantic search and related songs follow behind a shared embedding foundation. The
> public-domain Bible similarity band is optional enrichment and blocks none of the earlier value.

## Outcome

Help verified members planning a service find songs by:

1. an exact Bible passage or chapter;
2. a maintainer-curated theme;
3. a free-text idea matched semantically against lyrics; and
4. related-song links from a song page.

All request-time output is retrieval of existing song metadata. No generated prose or answers are
shown. Lyrics remain within the existing members-only surface.

## Ownership and cross-plan boundaries

This plan owns:

- `song_scripture_references` and exact song↔passage search;
- the shared `themes` vocabulary/table and song theme assignments;
- the generic embedding service, config, vector maths, and deterministic test implementation;
- song embeddings, semantic lyric search, and related-song ranking;
- optional public-domain Bible embeddings.

The [semantic sermon plan](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) consumes the generic
embedding foundation and the same `themes` table. It owns sermon chunks, `sermon_theme`, public
sermon ranking, and player timestamps. It must not fork those shared contracts.

The historic import plans own catalogue/usage convergence and all source-workbook decisions. This
feature consumes the current canonical `songs` table and never imports historic song usage or
changes historic dispositions. Lyrics-hash commands are idempotent; rerun their stale/coverage
modes after historic G9 admits or changes songs, without making G9 a launch dependency.

Site Search owns public-site `SearchTerms`; this members-only song feature may reuse that bounded
normalisation where it fits, but it must preserve `BrowseSongs`' established full-text keyword
behaviour and must not make Site Search depend on song work.

## Locked product decisions

- Members-only `BrowseSongs` and song-show pages; no admin-planner or public surface in this plan.
- Curated scripture rows outrank AI rows for the same song/passage.
- Scripture tagging is structured, offline, validated through `ScriptureReferenceResolver`, and
  calibrated before display.
- Theme names/descriptors are maintainer-authored. The LLM performs closed-set assignment only and
  cannot invent themes.
- Keyword search remains the fallback whenever semantic search is disabled, limited, or fails.
- MySQL JSON vectors plus bounded PHP cosine ranking; no vector database or new dependency.
- Separate feature flags for scripture, themes, semantic search, related songs, and optional Bible
  similarity.

## Delivery 1 — exact scripture discovery

**First user-visible release; no embedding dependency.**

### Data and preparation

Add `song_scripture_references` with:

- song ownership;
- normalised book/chapter and nullable verse start/end;
- canonical display reference;
- `curated|ai` source and nullable AI confidence;
- lyrics hash/model/batch provenance for AI rows;
- indexes for book/chapter overlap and an idempotency key that permits curated and AI provenance.

Provide two supported, re-runnable maintenance commands:

1. a curated importer whose adapter/columns are finalised only after inspecting the maintainer's
   real source sample;
2. a structured-output scripture tagger with dry-run, stale-only, per-song, and bounded batch
   modes.

Validate every AI reference through the existing resolver. Reject unknown books, malformed spans,
out-of-range verses, and results over the configured per-song cap. Never delete curated rows. A
dry-run calibration report shows proposed rows, confidence distribution, rejects, and source
lyrics hashes; maintainer approval sets the display threshold.

These are recurring catalogue-repair tools, not disposable one-shots. If implementation instead
creates a one-use migration command, its class docblock must declare a deletion trigger.

### Exact UI

When a `BrowseSongs` query parses cleanly as scripture and `scripture_enabled` is on:

- rank curated references before AI references;
- rank verse-overlap before chapter-only matches, then confidence and existing stable title order;
- label the normalised passage and show an honest empty state;
- preserve current keyword behaviour for text that does not unambiguously parse as scripture.

Add scripture chips to song-show pages, linking back to the catalogue query. Do not wait for
semantic “possibly related” results.

### Delivery 1 gates

- multi-chapter and verse-range normalisation/overlap tests;
- curated precedence, idempotent import/tag reruns, stale lyrics, rejection counts, and preserved
  manual rows;
- ambiguous text such as a song title ending in a number stays keyword search;
- URL round-trip, flag-off regression, loading/empty/error states, keyboard flow, Dusk, and focused
  visual review;
- maintainer approves a held-out calibration report before enabling the UI.

## Delivery 2 — shared themes and theme browsing

**Independent of Delivery 1 and embeddings; second user-visible release.**

Add:

- `themes`: maintainer-authored name, slug, descriptor, and ordering; reserve an optional descriptor
  embedding field/sidecar without requiring it now;
- `song_theme`: song/theme ownership, `curated|ai` source, confidence, lyrics hash/model/batch
  provenance, and uniqueness that preserves curated corrections.

Load a reviewed vocabulary of roughly 15–30 themes through a supported, idempotent data command or
seed mechanism. Add a structured closed-set assignment command with dry-run/stale/batch modes.
Reject unknown slugs and retain curated pivots.

After maintainer calibration approval:

- add a URL-persisted theme filter/chips to `BrowseSongs`, composable with range and keyword query;
- add theme chips to the song-show page;
- keep theme visibility fully off when the flag is disabled or vocabulary is absent.

Tests prove closed-set rejection, curated preservation, idempotency, URL/filter composition, empty
vocabulary, and flag-off behaviour. Dusk proves chip → filtered catalogue → song.

This delivery creates the sole shared theme vocabulary. The sermon plan may begin its theme work as
soon as this table/vocabulary contract is stable; it does not wait for song semantic search.

## Delivery 3 — generic embedding foundation

**Shared infrastructure; start after the two simpler discovery releases unless sermon semantic
work needs it earlier.** This delivery itself stays dark.

- Add `EmbeddingServiceInterface` with single and batch operations, an OpenAI implementation, and a
  deterministic dimension-correct mock. Reconfirm the current supported embedding model and
  optional-dimensions API at implementation time.
- Bind it through the existing AI service provider/config conventions. Local and CI must never call
  the network.
- Add `VectorMath::cosine()` and `centroid()` with zero-vector/dimension guards. Move the existing
  speaker-identification cosine calculation onto it only with regression tests, so vector maths has
  one owner.
- Keep model and dimensions in every stored-vector contract; a change makes old vectors stale.

Tests cover binding, deterministic mock parity, batch ordering, dimensions, retryable/non-retryable
errors, cosine/centroid correctness, and speaker-identification regression.

## Delivery 4 — song embeddings and semantic lyric search

Depends on Delivery 3 and ships the infrastructure's first song-facing value.

### Index

- Add a sidecar `song_embeddings` row per song: vector JSON, model/dimensions, lyrics hash, and
  embedded timestamp. Do not load vectors with ordinary song catalogue queries.
- Embed title plus plain lyrics in an idempotent queued job.
- Hook catalogue sync explicitly on lyrics-hash change; do not rely on observers bypassed by sync.
- Provide a supported dry-run/limit/song/stale backfill command.
- Load decoded vectors into a versioned plain-array matrix cache and invalidate it on replacement;
  never cache Eloquent models.

### Search

Preserve the existing keyword band. When `semantic_enabled` is on, append a deduplicated **Related
by meaning** band:

- normalise and cache the query embedding;
- make at most one API call per uncached query;
- rate-limit by authenticated user;
- rank the bounded song matrix by cosine with a calibrated floor/cap;
- silently retain keyword results on timeout, limit, or embedding failure.

The flag flips only after a held-out query set, latency, minimum-score, and cost report are approved.
Tests use mock vectors to prove ranking, deduplication, cache invalidation, limiter behaviour,
fallback, and unchanged keyword output when disabled. Dusk covers keyword-only and combined bands.

## Delivery 5 — related songs

Depends only on Delivery 4's vectors and is independent of scripture/themes.

Add a small related-song strip to the members' song-show page using cached nearest neighbours.
Exclude self and unavailable songs; invalidate on matrix model/dimension/version change. Keep the
block absent when disabled or fewer than the minimum honest neighbours pass the calibrated score.

## Delivery 6 — optional Bible-similarity enrichment

Depends on Deliveries 1, 3, and 4. It is explicitly optional: exact scripture search already
delivered the core passage-planning use case.

After explicit source/licence approval, import a public-domain translation with recorded source
URL, licence assertion, artifact hash, translation ID, and exact row counts. Never reuse the
licensed `scripture_passages` text. Precompute chapter and overlapping verse-window embeddings with
model/dimension provenance.

For a parseable reference, derive a passage vector from overlapping stored windows and append a
clearly labelled **Possibly related** band after tagged matches. There is no request-time Bible API
or embedding call. Calibrate the score against known positive/negative pairs before enabling it.

Do not build a WEB-versus-KJV spike in the repository as a throwaway feature. Evaluate candidate
public-domain corpora offline, record the chosen source/provenance in the implementation PR, and
import only the approved artifact.

## Recommended order and parallelism

```text
D1 exact scripture search + show chips       [first value]
D2 themes + browse/show chips                [independent second value]
D3 generic embedding foundation              [shared enabler]
   ├── D4 song semantic search ──> D5 related songs
   └── semantic sermon indexing/search
D1 + D3 + D4 ──> D6 optional Bible similarity
```

D1 and D2 may run in either order according to which maintainer dataset is ready first. Do not hold
one for the other. Historic G9 triggers stale/coverage reruns across delivered data features but no
new schema or duplicate importer.

## Cross-cutting gates

- No UI flag flips before its calibration evidence is accepted.
- Log batch IDs, counts, timing, model/dimensions, hashes, rejects, and estimated spend—never lyrics
  or member search text.
- All external calls are queued/offline except one cached, rate-limited free-text query embedding.
- Activate frontend, Livewire, and Tailwind skills for each UI delivery.
- Every PR requires focused tests, PHPStan, Pint, and the full parallel suite; UI deliveries also
  require Dusk and intentional visual review/baseline updates where appearance changes.

**Who benefits:** members planning services and, through the shared foundation, visitors searching
the sermon archive later.

**What observably improves:** exact passage and theme navigation arrive before any semantic
infrastructure, then meaning-based song and sermon discovery share one tested vector contract.
