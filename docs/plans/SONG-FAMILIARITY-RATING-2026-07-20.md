# Song Familiarity Rating (2026-07-20) — traffic-light badge on admin song surfaces

> **Status (2026-07-20): drafted, awaiting maintainer sign-off.** No dependencies on the July
> simplification backlog. Coordinates with
> [SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md](../archived-plans/SERVICE-SCREENS-CONSOLIDATION-2026-07-19.md)
> only at one seam: the song-suggestion picker work lands in `ChurchServiceFormData` (the shared
> form object), not in `ManageChurchService`'s view alone, because item editing now lives on the
> service page.
>
> **Maintainer decision needed before starting (D1 below):** where "sung exactly once in the
> last 5 years" lands. The requested spec (green > 3×/2y, amber > 1×/5y, red 0×/5y) leaves that
> single case unassigned; this plan defaults it to **amber**.

## Goal

Show a traffic-light **familiarity rating** for each song on the admin song surfaces, so the
person planning a service can see at a glance whether the congregation knows a song:

- 🟢 **Familiar** — sung in more than 3 services in the last 2 years
- 🟠 **Occasional** — sung in the last 5 years, but not often enough to be Familiar
- 🔴 **Unfamiliar** — not sung at all in the last 5 years

Surfaces (all admin, all already gated behind `service-tracking.enabled`):

1. **Song catalogue list** (`/admin/songs`, `ListSongs`) — badge next to each title, plus a
   familiarity dropdown filter.
2. **Song detail** (`/admin/songs/{song}`, `ShowSong`) — badge in the usage-stats block.
3. **Service plan song picker** (`ChurchServiceFormData::songSuggestions()`, rendered today by
   `ManageChurchService`) — badge on each suggestion row. This is the highest-value spot: it is
   the moment a planner chooses between a known and an unknown song.

## Non-goals

- **No badge on the members' song catalogue** (`BrowseSongs`, `/church/songs`). Familiarity is
  an internal planning signal; telling members a song is "unfamiliar" adds nothing and invites
  confusion. (Revisit only on maintainer request.)
- **No stored/denormalised familiarity columns and no migration** — see "Approach rationale".
- **No sorting by familiarity** in `ListSongs`. The list already sorts by usage count and last
  used date, which are finer-grained versions of the same signal; the filter covers the
  "show me the red ones" need.
- **No changes to what counts as a "use"** beyond the predicate the codebase already applies
  everywhere (`type = 'songs'`, `deleted_at IS NULL`, linked `song_id`).

## Approach rationale

**Compute on read; never store.** Three reasons, all repo-specific:

1. It is the established pattern. `ListSongs` (`usage_count`/`services_count`/`last_used_date`),
   `ShowSong` (`usageBaseQuery()`), and `PublicSongCatalogService`
   (`qualifyingUsageSubquery()`) all derive usage via correlated subqueries against
   `church_service_items` ⨝ `church_services` at render time.
2. `church_service_items` rows are written by many pipelines (OoS email import, OpenLP sync,
   livestream extraction, the song-linking backfill, manual plan editing), and this repo has
   already been bitten by sync paths bypassing observer-driven cache invalidation. Denormalised
   counters would inherit exactly that staleness class.
3. Scale is trivial: the admin list paginates 20 rows and the picker shows 5 suggestions, each
   adding two indexed correlated `COUNT` subqueries — the same cost shape as the three
   subqueries `ListSongs` already runs per row.

**Centralise the tier maths in one enum.** The mapping (two counts → tier → label + badge
variant) must not be re-derived in three Blade files. A string-backed
`App\Enums\SongFamiliarity` owns it, with thresholds read from config so they can be tuned
without code changes.

## Semantics (exact definitions)

A **"time sung"** = one **distinct church service** (`COUNT(DISTINCT church_service_id)`)
containing a non-deleted `church_service_items` row with `type = 'songs'` and this `song_id`.
Distinct services, not items: a song reprised within one service was still learned once.
This matches the existing `services_count` in `ListSongs`.

**Date window**: `church_services.date` between (today − N years, inclusive) and **today,
inclusive**. The upper bound matters: services are created ahead of time from OoS plan emails
and manual planning, and a song scheduled for next Sunday has not been sung yet.

**Counting rules deliberately NOT applied** (record so nobody "fixes" this later):

- The Phase 6.1 OoS-eligibility predicate in
  `PublicSongCatalogService::qualifyingUsageSubquery()` (which suppresses plan items when a
  completed livestream contradicts them) is a *publication* rule for the public catalogue.
  Familiarity uses the simpler predicate shared by `ListSongs`/`ShowSong`: if the item is on
  the confirmed record of a past service, it counts.
- `ListSongs`' service/date filters do **not** narrow the familiarity windows. Usage columns
  answer "usage within my current filter"; familiarity answers the absolute question "does the
  congregation know this song". The badge stays constant while filtering.

**Tiers** (evaluated in order; `familiar` wins):

| Tier | Rule (with default thresholds) | Badge |
|---|---|---|
| `Familiar` | ≥ **4** distinct services in the last **2** years | `success` (green) |
| `Occasional` | not Familiar, and ≥ **1** distinct service in the last **5** years | `amber` |
| `Unfamiliar` | **0** distinct services in the last **5** years | `danger` (red) |

The three tiers are exhaustive and mutually exclusive — every song gets exactly one badge.

Config, in `config/service-tracking.php` (next to the existing `song_linking` block):

```php
'familiarity' => [
    'familiar_min_services' => 4,   // "> 3 times"
    'familiar_within_years' => 2,
    'known_within_years' => 5,      // amber/red boundary window
],
```

### D1 — the "exactly once in 5 years" case (maintainer to confirm)

The requested amber rule was "> once in 5 years", i.e. ≥ 2. That leaves a song sung exactly
once in 5 years in neither amber (needs ≥ 2) nor red (needs 0). **Default in this plan: it is
amber** — red's meaning stays crisp ("we have literally not sung this in 5 years") and the
config needs no fourth knob. If the maintainer wants ≥ 2 for amber, add
`'occasional_min_services' => 2` to the config block and put the 1-count case in red; only the
enum and its unit test change.

## Verified code map (line refs checked 2026-07-20)

| What | Where |
|---|---|
| Song model | `app/Models/Song.php` — `churchServiceItems()` HasMany; no custom builder class (only `SermonBuilder` exists in `app/Models/Builders`) |
| Admin list + existing subquery pattern | `app/Livewire/Admin/ChurchServices/ListSongs.php:84-100` (`usageBaseQuery()` + three `selectSub`s), filter plumbing via `WithFilterableListing` (`filterProperties()` at :59) |
| Admin list view | `resources/views/livewire/admin/church-services/list-songs.blade.php` — title column ~:80-95, filter controls near the top, columns declared ~:55 |
| Song detail stats | `app/Livewire/Admin/ChurchServices/ShowSong.php:40-56` (`usageBaseQuery()` + usage stats), view `resources/views/livewire/admin/church-services/show-song.blade.php` |
| Picker suggestions | `app/Livewire/Forms/ChurchServiceFormData.php:210-242` (`songSuggestions()` returns `array{id,title}` rows), rendered at `resources/views/livewire/admin/church-services/manage-church-service.blade.php:111-128` |
| Badge component | `resources/views/components/badge.blade.php` — variants `success`, `amber`, `danger` already exist; text label inside the pill (not colour-only, so colour-blind safe) |
| Config home | `config/service-tracking.php` (`song_linking` block precedent for threshold knobs) |
| Tests to extend | `tests/Feature/Livewire/Admin/ChurchServices/ListSongsTest.php`, `tests/Feature/Livewire/AdminSongCatalogTest.php` (ShowSong), `tests/Feature/Livewire/AdminChurchServiceTest.php` (form/suggestions) |
| Visual regression | `tests/playwright/` covers public pages only (homepage, sermons, meetings, nav) — **no baseline churn** from admin-only badges |

## Implementation

### PR 1 — enum, query scope, list + detail badges

1. **`App\Enums\SongFamiliarity`** (string-backed: `Familiar`, `Occasional`, `Unfamiliar`)
   with:
   - `public static function fromCounts(int $servicesInFamiliarWindow, int $servicesInKnownWindow): self`
     — reads thresholds from `config('service-tracking.familiarity')`;
   - `label(): string` — "Familiar" / "Occasional" / "Unfamiliar";
   - `badgeVariant(): string` — `success` / `amber` / `danger`;
   - `description(): string` — human sentence for tooltips/detail page, built from the
     configured thresholds ("Sung in 4+ services in the last 2 years", "Not sung in the last
     5 years"), so UI copy never drifts from the config.
2. **Config block** in `config/service-tracking.php` as above.
3. **Query helper on `Song`**: a static
   `Song::familiarityUsageSubquery(int $withinYears): Builder` returning the correlated
   `ChurchServiceItem` builder (`whereColumn` on `songs.id`, `type = 'songs'`,
   `whereNull('deleted_at')`, join to `church_services`, `whereBetween` on the date window,
   `selectRaw('COUNT(DISTINCT church_service_items.church_service_id)')`), plus a
   `scopeWithFamiliarityCounts(Builder $query)` that `selectSub`s two aliases:
   `familiar_window_services` and `known_window_services`. A scope (not a new builder class)
   matches how `ListSongs` composes its existing subqueries; introducing a `SongBuilder` just
   for this is out of proportion.
4. **`ListSongs`**: chain `->withFamiliarityCounts()`; in the Blade title cell render
   `<x-badge :variant="$familiarity->badgeVariant()" size="xs">{{ $familiarity->label() }}</x-badge>`
   (compute `$familiarity` once per row via `SongFamiliarity::fromCounts(...)` from the two
   aliases). Add `#[Url(except: null)] public ?string $familiarityFilter = null` +
   `filterProperties()` entry + a select with the three tiers.
   - **Filter mechanics — use WHERE, not HAVING**: filter by comparing the same correlated
     subqueries in the WHERE clause (Laravel subquery where clauses). `HAVING` on the
     `selectSub` aliases breaks `paginate()` — the count query strips the select list, so the
     aliases don't exist in the count pass. Green: familiar-window subquery `>=` threshold;
     red: known-window subquery `=` 0; amber: known-window `>=` 1 AND familiar-window `<`
     threshold.
5. **`ShowSong`**: extend the existing single stats query with two conditional counts
   (`COUNT(DISTINCT CASE WHEN church_services.date BETWEEN ? AND ? THEN church_service_items.church_service_id END)`
   per window, or two small extra queries off `usageBaseQuery()` — implementer's choice;
   the per-song cost is irrelevant). Render the badge next to the usage stats with
   `description()` as supporting text.
6. **Tests**:
   - New unit test `tests/Unit/SongFamiliarityTest.php`: boundary table for `fromCounts` —
     (4, n)→Familiar, (3, 1)→Occasional, (0, 1)→Occasional, (0, 0)→Unfamiliar, plus a case
     with overridden config thresholds proving they're read live.
   - `ListSongsTest`: freeze time (`$this->travelTo(...)`); seed one song per tier plus the
     boundary cases: sung 4× just inside 2 years (green), sung 4× but 25 months ago (amber),
     sung once 4y11m ago (amber under D1 default), last sung 5 years + 1 day ago (red),
     **future-dated service does not count**, **twice within one service counts once**,
     soft-deleted item excluded. Assert badge labels in rendered output and assert each
     `familiarityFilter` value returns exactly the expected songs with correct pagination.
   - `AdminSongCatalogTest`: ShowSong renders the correct badge + description for one green
     and one red song.
7. Run `pint --dirty`, `composer phpstan`, `artisan test --compact --parallel`, `artisan dusk`
   (all via sail).

### PR 2 — service-plan picker badge

1. **`ChurchServiceFormData::songSuggestions()`**: add the two familiarity counts to the
   suggestion query (`withFamiliarityCounts()` scope — the query is already
   `Song::query()`) and extend the returned shape to
   `array{id: int, title: string, familiarity_label: string, familiarity_variant: string}`
   (resolve the enum in PHP; pass primitives to Blade so the array stays
   Livewire-serialisable). Update the PHPDoc array shape.
2. **`manage-church-service.blade.php`** suggestion row (:111-128): badge between the title
   span and the "Use song" affordance. Keep the row a single flex line;
   `size="xs"`, `shrink-0` so long titles truncate rather than the badge.
   *(When plan 7 later moves this markup into the shared service page, the badge travels with
   it — the data comes from the form object, which both screens share.)*
3. Optionally (cheap, same data): show the badge next to the linked-song confirmation block
   below the picker (`@if($item['song_id'])` branch) so the rating stays visible after
   selection. Requires carrying the two counts through `selectSong()` state or re-resolving on
   render — only do it if it falls out simply; the suggestion-row badge is the requirement.
4. **Tests**: extend the `songSuggestions` coverage in `AdminChurchServiceTest` — frozen
   time, one green + one red candidate song matching the typed title, assert the returned
   suggestion arrays carry the right label/variant, and assert the rendered component shows
   the badge text.
5. Same four quality gates as PR 1.

## Accessibility & design notes

- The badge is **text inside a coloured pill**, never colour alone — `<x-badge>` already
  renders the label in the pill, satisfying colour-blind users and WCAG 1.4.1. No extra
  `aria-label` needed; the visible text is the accessible name.
- Variants `success`/`amber`/`danger` are existing design-system tokens
  (`badge.blade.php:10-16`); no new colours. Follow the `frontend-design` skill when touching
  the Blade files.
- British English throughout UI copy and code ("Occasional", not tier names needing spelling
  variants — but keep it in mind for `description()` strings).

## Risks / gotchas for the implementing agent

- **Time-frozen tests are mandatory.** Every threshold is relative to "today"; unfrozen tests
  will pass for years and then rot. Use `travelTo()` in each test, not `setUp`-wide state that
  other tests inherit.
- **`paginate()` + HAVING** is the one real query trap — see PR 1 step 4.
- **`churchServiceItems` includes children's-talk-adjacent item types** on paper — the
  predicate `type = 'songs'` is what keeps counts honest; copy it from `usageBaseQuery()`
  rather than re-deriving.
- **Do not add the badge to `BrowseSongs`** even though the scope would make it a one-liner —
  explicit non-goal above.
- `ListSongs`/`ShowSong` 404 when `service-tracking.enabled` is false; the picker is part of
  admin service editing. No new gating needed anywhere.
