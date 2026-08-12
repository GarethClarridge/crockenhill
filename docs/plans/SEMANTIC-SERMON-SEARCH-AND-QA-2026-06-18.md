# Semantic sermon retrieval — search, themes and related sermons

> **Status (comprehensively re-planned 2026-08-12): approved direction, not started.** The old
> simplification gates are complete. The pipeline already produces and durably stores timestamped
> `ChurchServiceTranscript` artifacts, sermon bounds and extraction plans; do not extend the old
> `TranscriptionServiceInterface` or re-transcribe the whole archive before shipping value.
>
> Two dependencies are deliberate and have one owner each:
>
> 1. [Site Search](SITE-SEARCH-2026-07-20.md) Delivery 1 owns the sermon archive's `q` URL/input,
>    deterministic keyword fallback and SEO handling.
> 2. [Song Scripture & Theme Search](SONG-SCRIPTURE-AND-THEME-SEARCH-2026-07-20.md) owns the generic
>    `EmbeddingServiceInterface`, `VectorMath`, embedding config and shared `themes` table.
>
> The historic import is **not** a launch dependency. Index the current eligible public corpus,
> then rerun the idempotent backfill/calibration after historic G9 admits more artifacts. This plan
> consumes promoted facts; it never adds import, Bundle A/B, manifest or release steps.

## Outcome

Build three public, retrieval-only ways into the preaching archive:

1. natural-language sermon search with a verbatim matching transcript extract and exact player
   timestamp;
2. curated theme pages listing sermons genuinely about that theme; and
3. related-sermon links on sermon pages.

No request-time answer generation, chat or AI-authored public prose. Visitors see sermon metadata
and words actually spoken by the preacher.

## Locked decisions

- **Storage/ranking:** MySQL JSON vectors plus bounded PHP cosine ranking. Do not introduce a
  vector database or upgrade the database engine for this feature.
- **Shared foundation:** consume the song plan's embedding contract/config/vector maths unchanged.
  Sermon-specific storage belongs here.
- **Exact links only:** a searchable chunk is eligible only when its source cues can be mapped to
  the actual published player's timeline. Never estimate timestamps from character offsets.
- **Incremental coverage:** launch over a certified eligible subset and report coverage honestly.
  A smaller exact corpus is better than a nominally complete corpus with invented timings.
- **Dimensions:** the shared service is dimension-configurable; benchmark the larger sermon chunk
  matrix and lock one dimension before the public semantic flag flips.
- **Failure fallback:** the keyword metadata search remains available when semantic search is off,
  rate-limited or fails.

## Existing assets and the real gap

The current livestream/video pipeline already stores:

- a normalized timed `ChurchServiceTranscript` through `ServiceArtifactStorage`;
- the transcript path on `MediaProcessingLog`;
- `sermon_start_time` / `sermon_end_time` and a recorded extraction plan whose segments describe
  continuous or concatenated output; and
- a plain published sermon transcript sliced from the same service artifact.

The historic normal-output/Bundle A contract preserves those run fields and transcript artifacts.
Older audio-only or pre-LLM sermons may have only plain text and audio. The missing feature is a
certified mapping from timed service cues to player-relative chunks, not a new live transcription
pipeline.

## Delivery order

```text
Shared embedding foundation (song plan) + Site Search Delivery 1
        └──> S1 exact eligible-corpus index
                 └──> S2 semantic ranking in existing q UI   [first public win]
                          ├──> S3 optional coverage expansion
                          ├──> S4 theme pages
                          └──> S5 related sermons
```

S3, S4 and S5 are independent after S2's index/service contracts. S4 also needs the shared theme
vocabulary. Historic G9 simply adds another S1/S3 backfill run.

## S1 — exact eligible-corpus index (dark, first consumer of shared embeddings)

### Eligibility and timeline mapping

Create a focused `SermonTranscriptIndexEligibility`/equivalent service. A sermon is eligible only
when all of the following are provable:

- the sermon is currently public under `SermonExposurePolicy`;
- a retained full-service transcript artifact parses into timed cues;
- the linked processing run has the actual extraction plan/bounds used for the public media; and
- every indexed cue maps through that plan onto the player's output timeline.

For a continuous extraction, player time is source cue time minus extraction start. For a concat
plan, map cues through each ordered source segment and accumulate the durations of earlier output
segments; drop cues in omitted gaps. Put this mapping in one pure tested class. Do not use
`sermon_end_time - sermon_start_time` for concat duration—the model already documents why it is
wrong.

Record an explicit ineligibility reason (missing artifact, missing plan, private, corrupt, unmapped)
for coverage reporting. Do not silently index a plain transcript with fake time.

### Storage and ingestion

- Add `sermon_transcript_chunks`: sermon/run natural ownership, chunk index, player-relative
  start/end, verbatim content, embedding JSON, model/dimensions, source artifact hash and embedded
  timestamp. Unique owner/chunk key; cascade from the sermon.
- `TranscriptChunker` groups adjacent mapped cues into bounded overlapping windows while retaining
  exact first/last player times.
- `EmbedSermonTranscript` batch-embeds and transactionally replaces one sermon's chunks. A source
  artifact hash makes reruns true no-ops and prevents stale provenance reuse.
- `sermons:embed-backfill` supports dry-run, limit and sermon selection. Its class docblock declares
  deletion only when continuous ingestion is proven and every admitted corpus has had an exact
  coverage audit; otherwise it remains a supported repair command.
- Hook new eligible sermons at the established post-processing/observer seam without making an
  embedding failure fail media publication. Record retryable failure for later backfill.
- Add `sermons:semantic-coverage` (or a reusable read-side report, not a throwaway one-shot) showing
  public total, exact eligible, indexed, stale and each ineligible reason.

Tests cover continuous and concat mapping, omitted gaps, source-hash idempotency, private exposure,
missing/corrupt artifacts and an intentionally ineligible old audio sermon.

## S2 — semantic search in the existing sermon `q` surface

### Service

`ArchiveSearchService`:

1. normalizes/caps the query using Site Search's shared term/value rules;
2. applies the existing public/facet filters before ranking;
3. embeds the query once;
4. cosine-ranks candidate chunks, groups by sermon and keeps the best verbatim extract; and
5. returns typed results with player-relative timestamps and coverage metadata.

Benchmark candidate dimensions and bounded candidate counts on production-shaped data. Lock
dimensions and a latency budget before launch. Cache normalized query embeddings briefly and add a
per-IP limiter for the only request-time API spend.

### Livewire/UI handoff

Site Search Delivery 1 already owns `#[Url(as: 'q')] public string $q`, the debounced input, filter
chips, `noindex` and canonical-without-`q`. Add one backend branch only:

- semantic flag on and service succeeds → semantic ranked results with snippet and timestamp link;
- flag off, no indexed candidates, rate limit or embedding failure → existing metadata keyword
  results.

Do not add a second search box, URL parameter or robots implementation. The site-wide `/search`
page remains deterministic metadata search; its “See all sermon results” link passes `q` into this
archive surface.

Add the smallest player enhancement that honours a validated `?t=<seconds>` value after media is
ready, clamps it to duration and works across `wire:navigate`. Dusk proves query → result → exact
seek for both audio and video where supported.

The public flag flips only after the maintainer reviews a held-out retrieval set and the coverage
report is shown beside the sign-off. Search copy must not imply complete archive coverage.

## S3 — optional coverage expansion for legacy sermons

This phase follows the public MVP; it does not block S2.

For a public sermon with audio/plain text but no timed service artifact, run the existing local
whole-recording transcription capability against the sermon media and persist a sermon-relative
timed sidecar with source hash/model/runtime provenance. Do not fabricate a `ChurchServiceTranscript`
or a church service when the source is sermon-only.

Use a resumable, dry-run-first backfill with a deletion trigger in its class docblock. Compare the
new text with the retained plain transcript; large divergence is a review/ineligible outcome, not
an automatic overwrite of public transcript text. This phase indexes timing evidence—it does not
rewrite titles, summaries or historic-import provenance.

After historic G9, first run S1 over promoted full-service artifacts; use S3 only for still-
ineligible admitted sermons.

## S4 — shared-theme sermon pages

Consume the song plan's `themes` rows; do not create `sermon_themes`. Add a `sermon_theme` pivot
with curated/automatic provenance and a re-runnable assignment command. Embed each curated
descriptor once and compare it with sermon centroids derived from chunks.

The maintainer reviews a dry-run theme → sermon report, tunes descriptors/threshold/caps and may
curate exceptions before `archive_search.themes_enabled` flips. Public theme index/detail pages
show existing sermon cards and optional best verbatim extracts, have proper breadcrumbs/meta/JSON-
LD, and enter the sitemap only after calibration approval.

## S5 — related sermons

Persist or cache per-sermon centroids, rank other public indexed sermons and render a small related
strip on sermon pages. Exclude self/private/ineligible items; invalidate when source hash,
embedding model/dimensions or exposure changes. This phase is additive and independent of theme
pages.

## Flags, privacy, cost and observability

- Separate `indexing_enabled`, `semantic_enabled` and `themes_enabled` flags. Indexing may dark-
  ship while UI remains off.
- CI uses the shared deterministic mock embedding service; no external calls.
- Public snippets are verbatim sermon transcript, never licensed Bible text or generated answers.
- Log counts, timings, model/dimension, hashes and error kinds—never query/transcript text.
- One-off compute is backfill/transcription; request-time cost is one cached/rate-limited query
  embedding. Themes and related sermons are precomputed.

## Quality and acceptance gates

Every code PR: focused red/green PHPUnit tests, PHPStan zero, Pint, full parallel suite; Dusk for
S2/S4/S5 interaction; Playwright only for intentional appearance changes.

Before S2 launch:

- exact timeline mapping is non-vacuously tested for continuous and concat extraction;
- dimension/latency/retrieval benchmark is approved;
- fallback works with the semantic service throwing;
- coverage report reconciles indexed rows to the admitted eligible corpus;
- no private/quarantined sermon appears through search, theme, related or direct snippet links;
- query states remain `noindex` with canonical free of `q`; and
- mobile, keyboard, loading, empty and error states pass the frontend workflow.
