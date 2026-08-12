# Song familiarity rating

> **Status (2026-08-12): approved direction, not started.** To avoid leaving one case unassigned,
> this plan uses a non-blocking default: a song used exactly once in five years is **Occasional**
> unless the maintainer overrides it before implementation. This keeps all tiers exhaustive and
> keeps Unfamiliar truthful (“no recorded use in five years”). The first delivery is useful without
> the picker and without historic-import completion.

## Outcome

Give service planners a text-labelled traffic-light signal based on recorded congregational use:

- **Familiar** — used in at least four distinct occurrences in the last two years;
- **Occasional** — not Familiar, but used at least once in the last five years;
- **Unfamiliar** — no recorded use in the last five years.

Show it only on admin planning surfaces:

1. song catalogue list, with a familiarity filter;
2. song detail usage summary;
3. service-plan song suggestions.

Do not expose this internal judgement in the members' song catalogue and do not add familiarity
sorting or a stored/denormalised tier column.

## Historic-import boundary

The historic plans own workbook reconciliation, import/apply controls, and F60–F62 evidence. This
plan must not import, repair, or separately interpret that workbook.

Compute familiarity from the existing `SongUsageQuery::occurrences(publicOnly: false)` relation:

- canonical service items already count through `service:*` identities;
- unresolved date-only reports count through `report:*` identities after the authorised historic
  apply;
- resolved reports are already excluded so their canonical item is not double-counted.

This makes the feature independently deliverable now: it reflects the data currently present and
automatically improves when the historic operation later adds approved evidence. Historic G9 should
run the familiarity tests/smoke as a consumer check, but no familiarity backfill is required.

Do not build a second union or direct `song_usage_reports` query in this feature. F60–F62 must make
the source evidence exact before production apply; familiarity merely consumes the shared read
model after that gate.

## Counting contract

An occurrence is one distinct `service_identity` from `SongUsageQuery`, for the song, dated from
today minus the configured window through today inclusive. Future plans do not count. A repeated
song item within one canonical service counts once. Soft-deleted canonical items and resolved
historic reports follow the exclusions already centralised in `SongUsageQuery`.

The familiarity window is absolute. It is not narrowed by the admin list's current date or service
filters.

Add to `config/service-tracking.php`:

```php
'familiarity' => [
    'familiar_min_occurrences' => 4,
    'familiar_within_years' => 2,
    'known_within_years' => 5,
],
```

Use “occurrences”, not “services”, in the config/API because date-only historic evidence cannot
truthfully claim a morning/evening service identity.

## Shared implementation seam

Centralise the behaviour rather than repeating correlated subqueries or threshold maths:

- `App\Enums\SongFamiliarity`: string-backed `Familiar`, `Occasional`, `Unfamiliar`; owns label,
  existing badge variant (`success`, `amber`, `danger`), description, and `fromCounts()`.
- a focused query service built on injected `SongUsageQuery`: adds the two bounded distinct-count
  subqueries to a `Song` builder and supplies the equivalent predicates for list filtering.

Use correlated `WHERE` subqueries for the filter, not `HAVING` on selected aliases: paginator count
queries remove the select list. Select only the required song columns and retain existing eager
loads.

## Delivery 1 — catalogue list and detail

**Independent, highest-value first release.**

1. Add the enum, config, and shared query service.
2. Add two count aliases to `ListSongs` and render the existing text-labelled `<x-badge>` beside
   each title.
3. Add a URL-persisted familiarity filter with the three exhaustive values and include it in the
   component's normal reset/has-filter contract.
4. Extend `ShowSong`'s usage read to show the same badge and threshold-derived explanation.
5. Keep all surfaces behind the existing service-tracking/admin controls.

Create/extend namespaced coverage under
`tests/Feature/Livewire/Admin/ChurchServices/`, including a new `ShowSongTest` if necessary. Do not
put new assertions in the legacy flat `AdminChurchServiceTest`; the simplification closeout owns
its removal.

### Delivery 1 tests

Freeze time and prove:

- four recent occurrences are Familiar;
- three recent occurrences are Occasional;
- one occurrence within five years is Occasional;
- none within five years is Unfamiliar;
- the exact date-window boundaries;
- a future occurrence is excluded;
- repeated items in one canonical service count once;
- soft-deleted items are excluded;
- an unresolved date-only report counts once;
- a resolved report plus its canonical item counts once, not twice;
- overridden config thresholds are read live;
- each list filter paginates and returns only the correct tier;
- detail and list descriptions remain consistent.

## Delivery 2 — service-plan suggestion badges

Depends only on Delivery 1 and is independently deployable.

1. Apply the shared familiarity-count query to
   `ChurchServiceFormData::songSuggestions()`.
2. Return Livewire-serialisable primitives for label and badge variant alongside suggestion ID and
   title; update the exact PHPDoc array shape.
3. Render a small, non-shrinking text badge on each suggestion without making long titles or the
   Use song action inaccessible.
4. Keep rating visible after selection only if the existing form state can carry it without a
   second query or duplicated tier logic; it is optional, not a release dependency.

Create focused namespaced suggestion coverage, for example
`tests/Feature/Livewire/Admin/ChurchServices/ManageChurchServiceSongSuggestionsTest.php`, rather
than extending the legacy flat suite.

## Accessibility and visual scope

- The visible words Familiar, Occasional, and Unfamiliar carry meaning; colour is supplemental.
- Reuse existing badge variants and focus/touch patterns; introduce no new colour tokens.
- Activate the project frontend/Livewire/Tailwind skills for the Blade work.
- This is admin-only and currently needs no public Playwright baseline. Dusk should cover the picker
  only if its interaction changes beyond rendering an extra label.

## Recommended order

1. Delivery 1: shared calculation plus list/detail.
2. Delivery 2: planning picker.
3. Historic F60–F62/G9 later enrich the same read model; run consumer smoke, no new feature PR.

## Quality gates

Each delivery requires focused tests, PHPStan, Pint, and the full parallel suite. Run Dusk when the
picker interaction changes. All relative-date tests must freeze time.

**Who benefits:** the operator and service planners choosing songs.

**What observably improves:** planners can distinguish well-known, occasional, and unrecorded songs
on the catalogue before the same signal is added at the moment of selection.
