# Livestream-Derived Church Service Plan

> Revised 2026-03-26 after architecture review
> Goal: if a livestream is the only source we ever receive, we should still persist a real service structure; if OoS/manual/email data later arrives, we should merge into the same service and require review only when high-confidence sources disagree.

## Architecture Decision: Livestream Is a First-Class Service Source

The placeholder-only approach is not the right long-term model.

- `ServiceSection` remains the run-scoped evidence model produced by livestream processing.
- `ChurchService` and `ChurchServiceItem` remain the persisted service structure used by the rest of the app.
- Livestream becomes a valid source of persisted service items, not just a temporary alignment input.
- We do not create service items during the early audio-only pass.
- We do project service items from livestream once speech classification has enriched the sections enough to be useful.
- We do not treat later OoS/manual/email imports as a separate service. They merge into the same canonical service record.
- When later human- or OoS-provided data disagrees with a high-confidence livestream-derived structure, we stage a review flow instead of silently overwriting one side.

## Why This Architecture

### Why not placeholders

A `church_services` row without items solves identity, but not the real downstream problem:

- no service structure to show in admin or reporting
- no song usage history
- no canonical list to reconcile against later
- a split between “real” services and “placeholder” services that the rest of the app has to keep special-casing

### Why not invent items during early classification

`ClassifyServiceSections` currently runs before speech transcription and speech section classification. At that point we still have audio-only guesses for many non-song sections. Freezing those guesses into canonical items too early would make the weakest stage of the pipeline the authoritative one.

### Why projection after speech classification

After `ClassifySpeechSections`, the pipeline has the best section data it is going to get from the livestream itself:

- better section types
- more reliable sermon / prayer / reading identification
- richer confidence metadata
- enough context to decide whether a detected structure is trustworthy enough to persist

That makes post-speech-classification projection the right place to materialize a service when no external OoS exists.

## Desired Lifecycle

1. Livestream runs through section classification and speech section classification.
2. A projection step decides whether to materialize or refresh a `ChurchService` from those sections.
3. If no service exists for the resolved `date + service`, create one with livestream-sourced items.
4. If a matching service exists and all active items are livestream-sourced, refresh that projected structure from the latest run.
5. If a matching service exists and contains non-livestream items, do not replace it with livestream projection. Use the existing canonical structure and run alignment/reconciliation instead.
6. If OoS/manual/email data later arrives, merge it into the same service.
7. If the sources agree or only add non-conflicting detail, auto-merge.
8. If high-confidence sources disagree materially, open a merge review instead of overwriting the conflicting items.
9. Once a merge is accepted, reconcile completed livestream runs against the resolved canonical service structure.

## Domain Model Changes

### 1. Introduce `livestream` as a real item source

Add `LIVESTREAM` to `ChurchServiceItemSource` and extend the `church_service_items.source` enum migration accordingly.

This lets the system express:

- a service imported from OpenLP
- a service manually entered or email-derived
- a service projected from livestream evidence

### 2. Treat service-level `source` as descriptive, not exclusive

`church_services.source` can remain a string, but it should describe the current primary provenance of the service:

- `livestream` when a service was first materialized from classified sections
- `openlp`, `email`, or `manual` when later canonical changes come from those sources

The service record stays the same record across those transitions.

### 3. Store projection provenance in metadata

Use metadata rather than new columns first.

Recommended service metadata shape:

```json
{
  "livestream_projection": {
    "projected_at": "2026-03-26T12:34:56+00:00",
    "processing_id": "uuid",
    "confidence_summary": {
      "high": 4,
      "low": 2,
      "manual_review": 1
    }
  }
}
```

Recommended item metadata additions:

```json
{
  "livestream_projection": {
    "processing_id": "uuid",
    "service_section_id": 123,
    "source_segment_ids": [10, 11],
    "confidence_level": "high"
  }
}
```

This gives us provenance, re-projection hooks, and later review context without an immediate schema explosion.

## Pipeline Change

Insert a new job after `ClassifySpeechSections` and before `AlignWithOos`.

Current relevant path:

- `ClassifyServiceSections`
- `TranscribeSpeechSegments`
- `ClassifySpeechSections`
- `AlignWithOos`

Proposed path:

- `ClassifyServiceSections`
- `TranscribeSpeechSegments`
- `ClassifySpeechSections`
- `ProjectLivestreamServiceStructure`
- `AlignWithOos`

`AlignWithOos` should remain responsible for aligning detected sections to an already-persisted canonical item list. It should not become the place where we create that list.

## Core Services To Introduce

### `ProjectLivestreamServiceStructure` job

Responsibilities:

- load the processing log and classified sections
- resolve the service identity
- decide whether projection should happen
- call the projection service

### `LivestreamChurchServiceProjectionService`

Responsibilities:

- create a `ChurchService` when none exists
- project `ServiceSection` records into `ChurchServiceItem` payloads
- sync those items using `ChurchServiceItemSyncService`
- link projected items back to their originating sections
- compute initial review state based on projection confidence

### `LivestreamSectionToServiceItemMapper`

Responsibilities:

- convert classified `ServiceSection`s into `ChurchServiceItem` payloads
- normalize type/title/section type
- omit sections that should never become canonical items
- attach projection metadata and confidence

### `ChurchServiceStructureMergeService`

Responsibilities:

- compare existing canonical items with an incoming source payload
- classify differences as safe auto-merge vs review-required
- persist safe changes
- stage unresolved conflicts for manual review

This is the key service for the later “OoS arrives after livestream” case.

## Projection Rules

### Case A: no matching service exists

- Create `ChurchService` for `date + service`
- Set `source = livestream`
- Project items from the classified sections
- Link sections to the created items
- Mark `needs_review` if the projected structure contains low-confidence or manual-review sections

### Case B: matching service exists and all active items are `livestream`

- Treat the service as a refreshable projection
- Re-project from the latest classified sections
- Update existing livestream items using `ChurchServiceItemSyncService`
- Re-link sections deterministically

This supports reruns and reclassification without duplicating services.

### Case C: matching service exists and any active item is non-livestream

- Do not overwrite the canonical structure with livestream projection
- Keep using the canonical item list for alignment/reconciliation
- Livestream stays an evidence source only in this case

This prevents later human or OoS work from being blown away by reruns.

## Merge / Review Strategy For Later OoS, Email, Or Manual Data

### Principle

Not every disagreement should block automation.

We should distinguish:

- enrichment: the new source fills blanks or improves metadata without changing meaning
- non-conflicting additions/removals: safe structural updates
- material disagreement: both sides appear credible but the structure does not match

### Proposed merge policy

#### Auto-merge

Auto-apply when the incoming source:

- fills blank titles or references
- identifies songs already detected by livestream
- adds unambiguous metadata without changing item meaning
- changes low-confidence livestream-derived items with a stronger source

#### Review-required merge

Open a merge review when all of the following are true:

- the existing item or structure is livestream-derived with high confidence
- the incoming source is also high-confidence
- the incoming change is material

Examples:

- a detected reading becomes a prayer
- the detected order places “Sermon” third, but OpenLP places it fifth
- a high-confidence detected song conflicts with a different OpenLP song title at the same point in the service

### How to persist review-required conflicts

Do not silently overwrite conflicting items.

Instead:

- persist a `pending_structure_merge` payload in `church_services.import_metadata`
- include the incoming source, confidence summary, and a snapshot of conflicting current items
- set `needs_review = true`
- keep the currently accepted canonical list unchanged until review is resolved

Recommended metadata shape:

```json
{
  "pending_structure_merge": {
    "incoming_source": "openlp",
    "created_at": "2026-03-26T12:34:56+00:00",
    "confidence": {
      "current": "high",
      "incoming": "high"
    },
    "conflicts": [
      {
        "type": "position_conflict",
        "current_item": { "...": "..." },
        "incoming_item": { "...": "..." }
      }
    ],
    "proposed_items": [
      { "...": "..." }
    ]
  }
}
```

That gives the review UI enough information to present “keep current”, “accept incoming”, or later “resolve per item”.

## How Existing Services Should Evolve

### `ChurchServiceItemSyncService`

Extend, do not replace.

Needed changes:

- accept `ChurchServiceItemSource::LIVESTREAM`
- add merge-authority rules for livestream vs human/OoS sources
- preserve the existing human-to-human merge behavior for `manual` and `email`
- preserve song-specific conflict handling, but add livestream-aware variants where needed

Conceptually the source groups become:

- human-authored: `manual`, `email`
- structured external: `openlp`
- detected: `livestream`

### `ChurchServiceCanonicalUpdateService`

Extend to understand `livestream` as a valid incoming source and to record merge-review metadata without assuming every change is immediately canonical.

### `ReconcileServiceSections`

Keep it for the “canonical structure changed after processing” path.

Once later OoS/manual/email changes are accepted, the existing reconciliation flow should relink completed livestream runs to the resolved service structure.

## PR Overview

| PR | Title | Scope |
|----|-------|-------|
| 1 | Livestream source groundwork | Enum/migration, metadata conventions, no behavior change |
| 2 | Livestream projection pipeline | New job + projection service + linking logic |
| 3 | Merge/review engine for later sources | Conflict staging, review metadata, safer import/update path |
| 4 | Review UI and operational hardening | Admin review actions, regression coverage, observability |
| 5 | Merge policy: identity-first matching | Fix false conflicts on song reordering and duplicate section types |

## PR 1: Livestream Source Groundwork

### Goal

Prepare the domain model to persist livestream-derived service items safely.

### Tasks

- Add `LIVESTREAM` to `ChurchServiceItemSource`
- Create migration to extend `church_service_items.source` enum
- Audit casts, factories, tests, and any source comparisons for the new enum case
- Define shared metadata schema for service/item livestream projection provenance
- Extend `ChurchServiceCanonicalUpdateService` to accept `livestream` as an incoming source where appropriate

### Tests

- `ChurchServiceItemSource` enum coverage
- `ChurchServiceItemSyncService` source normalization tests
- regression tests around existing `manual`, `email`, and `openlp` behavior

## PR 2: Livestream Projection Pipeline

### Goal

Materialize a real service structure from livestream sections when no external OoS exists.

### Tasks

- Add `ProjectLivestreamServiceStructure` job
- Insert it into the livestream pipeline after `ClassifySpeechSections`
- Implement `LivestreamChurchServiceProjectionService`
- Implement `LivestreamSectionToServiceItemMapper`
- Create/update matching `ChurchService` records when projection is allowed
- Sync projected items via `ChurchServiceItemSyncService`
- Link `ServiceSection.church_service_item_id` back to projected items
- Compute `needs_review` from section confidence and unresolved section review flags
- Skip projection when a matching service already contains non-livestream items

### Tests

- no matching service -> creates service and items from livestream
- rerun against livestream-only service -> refreshes projected items, no duplicate service
- matching non-livestream service -> skips projection, keeps canonical structure
- projected items retain provenance metadata and section links

## PR 3: Merge / Review Engine For Later Sources

### Goal

Handle “livestream first, OoS later” without either duplicating services or blindly overwriting credible detected structure.

### Tasks

- Introduce `ChurchServiceStructureMergeService`
- Feed OpenLP/email/manual imports through merge planning before canonical sync is applied
- Auto-apply safe enrichments and low-risk merges
- Stage high-confidence disagreements in `pending_structure_merge`
- Reopen `needs_review` when staged conflicts exist
- Dispatch reconciliation only after merge acceptance or safe auto-merge completion

### Tests

- low-confidence livestream + OpenLP disagreement -> incoming source can auto-apply
- high-confidence livestream + high-confidence OpenLP disagreement -> merge is staged for review
- human-approved service remains protected from later livestream reruns
- accepted merge re-dispatches reconciliation for matching completed runs

## PR 4: Review UI And Operational Hardening

### Goal

Make staged merge conflicts resolvable by admins and observable in production.

### Tasks

- Extend the service review dashboard to show pending livestream-vs-OoS merges
- Add actions to accept incoming structure or keep current structure
- Record resolution metadata in `import_metadata`
- Ensure resolution re-runs service section reconciliation for matching completed runs
- Add logging / metrics for projection, skipped projection, staged merges, and resolved merges

### Tests

- dashboard displays pending merge conflicts
- accepting incoming structure updates canonical items and requeues reconciliation
- keeping current structure clears pending merge and preserves existing items

## PR 5: Merge Policy — Identity-First Matching

### Goal

Eliminate false conflicts when incoming items are reordered relative to the livestream
projection. The current `StructureMergePolicy` matches incoming items to existing items
by position first, with a type+section_type fallback. This generates spurious review
triggers whenever a worship leader moves a song between the planning stage and the
OpenLP file — a routine occurrence.

### Problem: misaligned match vocabulary

`StructureMergePolicy::classifyIncomingItems` and `ChurchServiceItemSyncService` share
the same array snapshots but use different matching strategies:

- `ChurchServiceItemSyncService` runs a **three-tier match**: stable identity
  (`openlp_search_title` / `source_title`) → normalised song title → position fallback.
- `StructureMergePolicy` runs a **two-tier match**: position → type+section_type.

The policy therefore cannot recognise a song it would have matched in the sync step,
causing it to classify a reordering as a conflict.

#### Concrete failure case

Livestream projects: `[Amazing Grace (pos 1, high), Sermon (pos 2, high), How Great
Thou Art (pos 3, high)]`. OpenLP arrives with the same songs reordered: `[How Great
Thou Art (pos 1), Sermon (pos 2), Amazing Grace (pos 3)]`.

Current behaviour: positions 1 and 3 each find a different song at their slot → both
flagged `review_required` → merge staged, admin interrupted.

Correct behaviour: `How Great Thou Art` found anywhere in the existing list by title →
`isSongIdentificationMatch` passes → `auto_merge`. Same for `Amazing Grace`. Sermon
matches by position. All three items are `auto_merge`. No review required.

The same problem occurs with any service that has two items of the same type and
section_type (two songs, two readings). The type fallback matches the first one it
finds regardless of identity, so the second incoming item of that type has no match
and is classified as `unmatched_incoming` even when an equivalent existing item exists.

### Proposed fix: three-tier matching in `classifyIncomingItems`

Add a **stable identity match** step before the existing position lookup, and track
matched existing item indices to prevent double-matching. The new ordering is:

1. **Stable identity match** — for songs, match by `openlp_search_title`, then
   `source_title`, then normalised title. For non-song items, match by section_type +
   normalised title.
2. **Position match** — same type at the same position (existing fallback).
3. **Type+section_type match** — same type and section_type anywhere in the list
   (existing last resort), but only when no stable identity match was found.

The existing `isSongIdentificationMatch` and `titlesSemanticallyMatch` helpers in
`StructureMergePolicy` already implement song identity comparison. They are currently
used only in the *safety check* phase (`isAutoMergeSafe`). This PR promotes them to
the *matching* phase as well.

Matched existing item indices must be tracked across iterations so one existing item
cannot be used to satisfy two incoming items (mirrors `$matchedExistingItemIds` in
`ChurchServiceItemSyncService`).

### Changes

#### `StructureMergePolicy`

- Replace the current position → type fallback loop in `classifyIncomingItems` with
  a three-pass match: stable identity → position → type+section_type.
- Add a `$matchedExistingIndices` accumulator so each existing snapshot can only be
  matched once.
- Extract `findStableIdentityMatch(array $existingSnapshots, array $incomingItem,
  array $alreadyMatchedIndices): ?array` — returns the first unmatched snapshot that
  satisfies song identity or non-song title+section_type identity.
- The existing `findTypeMatch` becomes a true last resort, constrained to only
  snapshots not already claimed by a stable or position match.

#### No changes needed elsewhere

`ChurchServiceItemSyncService`, `ChurchServiceStructureMergeService`, and the
projection services are not affected. The fix is entirely within `StructureMergePolicy`.

### Tests

- high-confidence songs reordered between livestream and OpenLP → auto-merge, no staged
  review
- two songs in service, both present in OpenLP but swapped → both auto-merge
- song present in livestream with no title, identified by `openlp_search_title` →
  auto-merge
- genuinely different song at the same position (not a reorder) → still review-required
- non-song section with same section_type appearing twice, incoming changes one of them
  → only the changed one is review-required, the unchanged one is auto-merge
- existing tests continue to pass (regression coverage for all current
  `StructureMergePolicy` and `ChurchServiceStructureMergeService` tests)

### What this does not change

- The safety rules in `isAutoMergeSafe` are unchanged. Matching and safety are separate
  concerns: better matching means fewer items reach `isAutoMergeSafe` with the wrong
  counterpart, but the rules for what constitutes a safe change remain the same.
- The `unmatched_incoming` classification remains valid for genuinely new items — items
  that have no identity match, no position match, and no type match in the existing list.
- Structural position conflicts (same song, moved to a materially different slot) remain
  detectable because the safety check still compares positions when neither side has
  `source_title` or `openlp_search_title`. This PR only fixes the case where identity
  should have been the primary match signal.

## Risks And Mitigations

### Risk: projection churn on reruns

Mitigation:

- only allow automatic refresh when all active items are livestream-derived
- store `processing_id` in item metadata so reruns are traceable

### Risk: review state becomes noisy

Mitigation:

- only stage review for material disagreements when both sides are high-confidence
- auto-merge enrichments and low-confidence corrections

### Risk: repeated reconciliation loops

Mitigation:

- keep the current after-commit reconciliation dispatching
- dispatch only when canonical structure is actually accepted or changed
- avoid dispatching from projection when sections are already directly linked in the same transaction

## Recommended First Implementation Slice

If we want the smallest end-to-end path first, build these in order:

1. `LIVESTREAM` source groundwork
2. projection job and projection service after `ClassifySpeechSections`
3. create service + items only when no matching service exists
4. skip projection when non-livestream items already exist
5. defer full staged merge-review UI to the next PR once livestream-first services exist in the wild

That gets the main missing behavior into production quickly without locking us into the rejected placeholder design.
