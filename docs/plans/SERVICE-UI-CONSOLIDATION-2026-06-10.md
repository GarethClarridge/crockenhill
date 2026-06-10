# Service & Livestream UI Consolidation — Implementation Plan

Created 2026-06-10. **Amended same day after a Codex review** — the amendments tighten the
read-model contracts (single-source-of-truth counts, three-path run matching, blob-column
safety, per-action authorization, config-gate matrix) so the consolidation cannot silently lose
the existing matching/counting/performance semantics. Approved direction: reorganise the
service/livestream admin UI around **the service** (the Sunday) instead of around pipeline
tables, consolidating 13 surfaces into 6.

## Background

The service/media admin area has grown one page per pipeline table. Today an admin juggles:

| # | Route | Component | Role |
|---|-------|-----------|------|
| 1 | `/admin/sermon-upload` | [MediaUpload](../../app/Livewire/MediaUpload.php) | Upload recording (audio/video/livestream) |
| 2 | `/admin/services/upload` | [UploadChurchService](../../app/Livewire/Admin/ChurchServices/UploadChurchService.php) | Upload OpenLP `.osz` plan |
| 3 | `/admin/services/submit-email` | [SubmitEmailText](../../app/Livewire/Admin/ChurchServices/SubmitEmailText.php) | Paste order-of-service email |
| 4 | `/admin/services/create`, `/{id}/edit` | [ManageChurchService](../../app/Livewire/Admin/ChurchServices/ManageChurchService.php) | Manual plan entry / item editing |
| 5 | `/admin/services/inbound-emails` | [ReviewInboundEmails](../../app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php) | Approve low-confidence email parses |
| 6 | `/admin/services/review` | [ServiceReviewDashboard](../../app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php) | Flagged sections grouped by service; edit/approve/merge |
| 7 | `/admin/services/section-publications` | [ListSectionPublications](../../app/Livewire/Admin/ChurchServices/ListSectionPublications.php) | The same sections, by publication status |
| 8 | `/admin/services/processing/review` | [ProcessingReviewList](../../app/Livewire/Admin/ChurchServices/ProcessingReviewList.php) | Runs paused for sermon-segment confirmation |
| 9 | `/admin/services/processing/{log}/review` | [ProcessingReview](../../app/Livewire/Admin/ChurchServices/ProcessingReview.php) | Pick the correct sermon segment |
| 10 | `/admin/services` | [ListChurchServices](../../app/Livewire/Admin/ChurchServices/ListChurchServices.php) | Services table |
| 11 | `/admin/services/{id}` | [ShowChurchService](../../app/Livewire/Admin/ChurchServices/ShowChurchService.php) | Planned-vs-detected timeline, run cards, merge resolution |
| 12 | `/admin/services/songs` (+ `/{song}`) | ListSongs / ShowSong | Song catalogue (reference data) |
| 13 | `/church/members` | [members/home.blade.php](../../resources/views/members/home.blade.php) | 7-button entry grid |

Diagnosis (from the 2026-06-10 UI review):

- **Pages mirror tables, not tasks.** The admin's mental model is *plan + recording in → sermon,
  songs, children's talk out*; no page represents that journey.
- **The same entity is reviewable in three places.** A pending section has Approve buttons on
  pages 6, 7, and links from 11. A paused run appears on 8, 11, and the members grid.
- **Navigation is a mesh.** Every page header carries 3–6 sibling-page buttons because no page is
  *home*.
- **Two input pages, two visual languages.** MediaUpload is hand-rolled legacy markup
  ([form.blade.php](../../resources/views/livewire/media-upload/form.blade.php)); the plan upload
  uses `x-admin.form-shell`.
- **No pipeline visibility.** Plan → recording → processing → review → published state is
  fragmented across four badge systems.

The backend is already well-factored for this consolidation: actions
(`App\Actions\ServiceReview\*`, `App\Actions\Publication\*`, `App\Actions\InboundEmail\*`,
[ConfirmLivestreamSermonSegment](../../app/Actions/ConfirmLivestreamSermonSegment.php)), queries
([ServiceReviewDashboardQuery](../../app/Queries/ServiceReviewDashboardQuery.php),
[ChurchServiceProcessingRunQuery](../../app/Queries/ChurchServiceProcessingRunQuery.php)), the
[ManagesSectionPublication](../../app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php)
trait, and the `awaitingManualSermonReview` scope are all reusable as-is. This is a UI/IA
refactor, not a domain change.

## Goal — target information architecture

Three core pages, one hierarchy:

1. **Services hub** (`/admin/services`) — attention strip, "This Sunday" hero card with pipeline
   stepper, services table with one rolled-up status column, single `+ Add` menu.
2. **Service workbench** (`/admin/services/{id}`) — the existing detail page absorbs *all*
   in-context review: inline section approve/reject/edit/merge/speaker, embedded sermon-segment
   confirmation, pipeline stepper header.
3. **Review inbox** (`/admin/services/inbox`) — one queue replacing four: emails, flagged
   sections, segment confirmations, merges and service flags, grouped by service, with inline
   quick actions and filter chips.

Unchanged: Songs catalogue, Sermons list, ManageChurchService edit form.

### Page disposition

| Current | Fate |
|---|---|
| ListChurchServices | Becomes the **hub** (Phase 1) |
| ShowChurchService | Becomes the **workbench** (Phase 3) |
| ReviewInboundEmails | Retired → inbox `Emails` filter (Phase 2/5) |
| ServiceReviewDashboard | Retired → inbox (triage) + workbench (editing) (Phase 3/5) |
| ListSectionPublications | Retired → inbox `Sections` filter (Phase 2/5) |
| ProcessingReviewList | Retired → inbox `Segments` filter (Phase 2/5) |
| ProcessingReview | Embedded in workbench; standalone route kept as fallback for orphan runs (Phase 3) |
| SubmitEmailText | Reached only via the Add menu; page survives unchanged (Phase 4) |
| UploadChurchService / MediaUpload | Both restyled/reached via Add menu; MediaUpload modernised (Phase 4) |
| members/home grid | Shrinks to Services · Review inbox (badge) · Sermons · Song catalogue (Phase 2) |

## Non-Goals

- No changes to the processing pipeline, actions, jobs, or models' write paths.
- No changes to public-facing pages (sermon archive, children's corner, podcasts).
- No new packages.
- The Songs catalogue and Sermons admin pages keep their current design.
- Mobile-first redesign of tables is out of scope (existing `overflow-x-auto` pattern stays).

## Design rules (apply to every phase)

- Follow [docs/design-style-guide.md](../design-style-guide.md) and the `frontend-design` skill.
  Admin pages: neutral, fast-scanning, `x-admin.*` shells, shared form components.
- **Colour discipline:** amber = needs a human, teal = done/ready, sky = machine working,
  rose = error/rejected, slate = inert (plan-only). Service-type chips (Morning/Evening) lose
  their green/amber fills — use neutral `bg-gray-100 text-gray-700` so amber regains meaning.
- Internal links use `wire:navigate`; icon-only actions get `aria-label`; loading states via
  `wire:loading`; empty states for every list.
- Config gates carry over: every new surface respects `service-tracking.enabled`; section
  entries also respect `media-processing.section_publishing.enabled`
  (see `abortIfDisabled()` in the existing components).

## Read-model contracts (binding for every phase)

These invariants exist in the current code as deliberate decisions, several pinned by
regression tests. The consolidation must not re-derive them loosely.

**C1 — Counts and lists share predicates.** Any attention count shown on the hub strip or
members-home badge MUST be computed from the same code path that produces the corresponding
inbox/workbench rows — never a parallel SQL approximation. Specifically: "flagged sections" is
*not* `publication_status = pending_approval`; a section is a review candidate when
[ServiceReviewDashboardQuery::reviewReasons()](../../app/Queries/ServiceReviewDashboardQuery.php)
is non-empty (manual review, speaker review, pending approval, low confidence, inferred/unmatched
song, heuristic demotion — seven reasons, partly PHP-evaluated). A cheap count that only sees
`pending_approval` would show "All caught up" while the inbox still has work. Where the exact
count is too expensive for a high-traffic page (members home), cache it briefly
(`Cache::remember`, ≤60 s) rather than substituting a weaker predicate — a stale badge is
acceptable, a wrong predicate is not.

**C2 — Run↔service matching has three paths.** A `MediaProcessingLog` belongs to a service when
*any* of: (a) extracted date+slot identity match, (b) `church_service_id` FK, or (c) fallback
processing-ids harvested from `items.livestream_processing_id` and
`import_metadata.livestream_projection.processing_id` (repaired runs) — see
[ChurchServiceProcessingRunQuery::fallbackProcessingIdsForService()](../../app/Queries/ChurchServiceProcessingRunQuery.php),
pinned by `it_shows_repaired_runs_found_via_item_projection_columns` in
[ShowChurchServiceTest](../../tests/Feature/Livewire/Admin/ChurchServices/ShowChurchServiceTest.php).
Any new bulk/rollup query MUST reuse this matching via a shared, extracted scope — not reimplement
paths (a)+(b) and drop (c).

**C3 — No blob columns in `MediaProcessingLog` lists.** Every list/aggregate query over
`media_processing_logs` selects an explicit safe column list (TD-004B): the table carries
oversized JSON blobs (`visual_samples`, `song_clusters`, `rms_stats`, `ai_analysis`, …) that must
never be hydrated for queue rendering. Pinned by
[ProcessingReviewListTest](../../tests/Feature/Livewire/Admin/ChurchServices/ProcessingReviewListTest.php);
copy the column list from [ProcessingReviewList](../../app/Livewire/Admin/ChurchServices/ProcessingReviewList.php).
The inbox and rollup queries inherit this rule.

**C4 — Admin authorization is per-component *and* per-action.** Every new Livewire component
under `App\Livewire\Admin` must use `WithAdminAuthorization` (pinned by
[AdminLivewireComponentsUseTraitTest](../../tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php)),
and every state-mutating action method calls `$this->authorizeAdmin()` first. When porting
dashboard actions in Phase 3, note the **existing gap**: `confirmMerge()` in
[ServiceReviewDashboard](../../app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php)
mutates without a per-action authorize call — fix it during the port, don't copy it.

**C5 — Config-gate matrix.** `service-tracking.enabled = false` ⇒ all service surfaces
(hub, workbench, inbox, upload pages) 404 as today, `AdminAttentionCounts` returns **all zeros**,
and members home hides the Services/Review-inbox buttons (the Sermons and recording-upload
entries are *not* gated — `MediaUpload` is gated only by the Sermon create Gate).
`media-processing.section_publishing.enabled = false` ⇒ section counts contribute zero, the
inbox omits the Sections chip and section entries, and the workbench hides
publication/approval affordances (matching today's
[ListSectionPublications](../../app/Livewire/Admin/ChurchServices/ListSectionPublications.php) gate).
Every new query/component gets explicit tests for both gates.

## Quality gates

Run for every phase before finalising (per project policy):

- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail composer phpstan` — 0 errors
- `vendor/bin/sail artisan test --compact --parallel`
- `vendor/bin/sail artisan dusk`

---

## Phase 1 — Services hub (no route changes)

**Goal:** `/admin/services` answers "does anything need me?" and "where is each Sunday in the
pipeline?" at a glance. Pure addition; nothing retired.

### 1.1 Attention-counts read model

- [ ] New `App\Queries\AdminAttentionCounts` returning
      `{pending_emails, awaiting_segment_runs, flagged_sections, pending_merges, services_needing_review}`:
      - emails: `InboundEmail` whereIn status `Pending|Failed` (the logic currently inlined in
        [MemberController](../../app/Http/Controllers/MemberController.php));
      - runs: `MediaProcessingLog::awaitingManualSermonReview()->count()` (also in MemberController),
        selecting safe columns only (contract C3);
      - **flagged sections: per contract C1**, derived from the dashboard-query review-candidate
        path (`reviewGroups()` + `summary()` sections count — the seven-reason predicate), NOT a
        raw `pending_approval` SQL count. Expose the count from
        [ServiceReviewDashboardQuery](../../app/Queries/ServiceReviewDashboardQuery.php) so the
        strip, the inbox, and the badge cannot disagree. Returns zero when section publishing is
        disabled (contract C5);
      - merges: reuse [ServiceReviewDashboardQuery::pendingMergeCount()](../../app/Queries/ServiceReviewDashboardQuery.php);
      - services: `ChurchService` where `needs_review = true`.
- [ ] `total()` helper for the members-home badge; the whole result is `Cache::remember`-ed
      (≤60 s) for the members-home call site only — the hub computes fresh (C1).
- [ ] All counts return zero when `service-tracking.enabled` is false (contract C5).
- [ ] Refactor `MemberController` to consume this query (removes the duplicated count logic).
- [ ] Unit/feature test for the query covering each count, the flagged-vs-pending distinction
      (a section flagged for *low confidence only* must appear in the count), and both config
      gates.

### 1.2 Rolled-up service status

- [ ] New `App\Enums\ChurchServiceRollupStatus`:
      `PlanOnly | AwaitingRecording | Processing | NeedsReview | Ready | Published`
      with `label()` and badge-class mapping following the colour rules above.
      (`PlanOnly` = no matching run and service date is in the future or recent;
      `AwaitingRecording` = no matching run, past date — implementation may merge these two if
      the distinction proves noisy; decide during build and document in the enum.)
- [ ] New `App\Queries\ChurchServiceRollupQuery` with a **bulk** method
      `forServices(EloquentCollection $services): array` (keyed by service id) so the paginated
      list (20 rows) computes statuses without N+1:
      - one query for `MediaProcessingLog` livestream runs matching the page's services via
        **all three match paths of contract C2** — (date, service) identity pairs,
        `church_service_id`, *and* fallback processing-ids from item/import-metadata projection
        columns. Extract the matching logic from
        [ChurchServiceProcessingRunQuery](../../app/Queries/ChurchServiceProcessingRunQuery.php)
        into a shared collaborator (e.g. `App\Support\ChurchServiceRunMatcher`) consumed by both
        the existing per-service query and the new bulk query, so the repaired-run regression
        test keeps one implementation honest. Bulk note: the page's services must eager-load
        `items` once for the fallback-id harvest (no per-row `loadMissing`). Select safe columns
        only (contract C3);
      - aggregate per service: any run in `Pending|Started|Processing` → `Processing`; any
        flagged section / awaiting-segment run / `needs_review` / pending merge →
        `NeedsReview` (with count); all sections `Published|NotApplicable` and a sermon exists →
        `Published`; else `Ready`.
- [ ] Replace the `needs_review` badge column in
      [list-church-services.blade.php](../../resources/views/livewire/admin/church-services/list-church-services.blade.php)
      with the rollup chip (`Needs review (n)` shows the count). Keep the `needsReviewFilter`
      URL param working; add a `status` filter option only if it falls out naturally —
      otherwise defer to Phase 5 polish.

### 1.3 Attention strip + "This Sunday" hero

- [ ] New Blade partial (or `x-admin.attention-strip` component) rendered above the filters on
      the hub: one chip per non-zero attention count, deep-linking (Phase 1 targets = the four
      existing queue pages; re-pointed to the inbox in Phase 2). All-zero state renders a single
      quiet "All caught up" line, not five zeros.
- [ ] Hero card for "This Sunday", defined precisely (date-desc alone would surface a
      far-future imported plan): among services dated within **±7 days of today**, pick the one
      closest to today, tie-breaking first on *needs attention* (non-Ready rollup), then most
      recent. If none in the window, fall back to the most recent past service. Card shows
      date/slot heading, item count, run summary, rollup chip, `Open service →`. Implemented
      with data already loaded for the table (no extra queries beyond the rollup bulk call —
      the hero's service is fetched alongside the page or reuses a row already present).
- [ ] Pipeline stepper visual: new `x-admin.pipeline-steps` component taking
      `[{label, state: done|active|blocked|todo}]`; used by the hero now, reused by the
      workbench in Phase 3. States derive from the rollup query.

### 1.4 `+ Add` menu

- [ ] Check `resources/views/components/` for an existing dropdown/menu component before
      writing one (per component-reuse rule). If none fits, add `x-admin.action-menu`
      (Alpine, keyboard-operable, `x-cloak`).
- [ ] Hub header gains one primary `+ Add` button with items: *Upload recording* →
      `admin.sermon-upload.create`; *Upload order of service* → `admin.services.upload`;
      *Paste email text* → `admin.services.submit-email`; *Create manually* →
      `admin.services.create`. The existing row of header buttons shrinks to the Add menu +
      `Song catalogue`.

### 1.5 Tests

- [ ] Extend [AdminChurchServiceTest](../../tests/Feature/Livewire/AdminChurchServiceTest.php)
      (hub renders strip, rollup chips, hero, Add menu; gates respected).
- [ ] New query tests: `AdminAttentionCountsTest`, `ChurchServiceRollupQueryTest`
      (cover every rollup state, the bulk no-N+1 shape via `expectsDatabaseQueryCount` or
      similar, **and a repaired-run case** — a log matched only via
      `items.livestream_processing_id` must roll up, mirroring the ShowChurchServiceTest
      regression).
- [ ] Hero-selection tests: future-only plans, ±7-day window tie-breaks, past-only fallback.

**Exit criteria:** hub shows attention strip, hero with stepper, one rollup status per row, and a
single Add menu; MemberController consumes the shared counts query; all gates green.

---

## Phase 2 — Review inbox

**Goal:** one chronological "Monday-morning sweep" queue. Old queue pages remain functional but
de-emphasised; deep links for *editing* still target the old pages until Phase 3.

### 2.1 Read model

- [ ] New `App\Queries\ReviewInboxQuery` returning service-grouped entries:
      `{group: {service|null, date_label, service_label}, items: [{kind, payload, actions}]}`
      where `kind ∈ {email, section, segment, merge, service_flag}`:
      - **email** — `InboundEmail` Pending/Failed + preview via
        [InboundEmailPreviewFactory](../../app/Actions/InboundEmail/InboundEmailPreviewFactory.php);
        attributed to a (date, slot) group when the parse resolved one, else an
        "Unattributed" group pinned first;
      - **section** — reuse [ServiceReviewDashboardQuery::reviewGroups()](../../app/Queries/ServiceReviewDashboardQuery.php)
        review-candidate logic + reasons (do not re-derive the flagging rules);
      - **segment** — `MediaProcessingLog::awaitingManualSermonReview()` with the **explicit
        safe-column select copied from
        [ProcessingReviewList](../../app/Livewire/Admin/ChurchServices/ProcessingReviewList.php)**
        (contract C3 / TD-004B) — extend the existing blob-column regression test to cover the
        inbox query;
      - **merge / service_flag** — `pending_structure_merge_source` / `needs_review` services.
      - Cap each source (e.g. 50) and order groups by service date desc;
        `reviewGroups()` currently loads unbounded — apply the cap here, not by
        rewriting that query.
      - Counts shown on chips come from the same result set (contract C1).
- [ ] Feature tests for the query: grouping, attribution fallback, caps, both config gates
      (sections omitted when publishing disabled; whole page 404 when service-tracking disabled).

### 2.2 Component and route

- [ ] New `App\Livewire\Admin\ChurchServices\ReviewInbox` at `/admin/services/inbox`
      (`admin.services.inbox`), registered **before** the `/{churchService}` catch-all in
      [routes/web.php](../../routes/web.php) (same constraint that bit the existing routes).
      Uses `WithAdminAuthorization`; **every mutating action calls `$this->authorizeAdmin()`**
      (contract C4 — the trait test enforces the trait automatically since the component lives
      under `App\Livewire\Admin`).
- [ ] Filter chips `All | Emails | Sections | Segments | Services` as a `#[Url]` param —
      the `Services` chip is the explicit home for merge and service-flag items (they also
      appear under `All`). Chips with a zero count render disabled/hidden. Layout:
      `x-admin.page`, one card per service group, rows per item.
- [ ] Inline quick actions, all reusing existing actions/traits:
      - email: Approve / Edit & approve (→ `services.create?inboundEmailId=`) / Re-parse /
        Reject — port handlers from
        [ReviewInboundEmails](../../app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php);
      - section: Approve / Reject / Requeue via
        [ManagesSectionPublication](../../app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php);
        an `Edit ↗` link to the Review Dashboard (re-pointed to the workbench in Phase 3);
      - segment: `Choose segment →` link (Phase 2 target: existing
        `admin.services.processing.review` detail page);
      - merge: `Resolve →` link to the service page (resolution UI already lives there);
      - service_flag: `Mark reviewed` via
        [MarkServiceReviewed](../../app/Actions/ServiceReview/MarkServiceReviewed.php).
- [ ] Empty state: "All caught up — nothing needs review." with a link back to the hub.

### 2.3 Re-point navigation

- [ ] Hub attention strip deep-links → inbox filters.
- [ ] [members/home.blade.php](../../resources/views/members/home.blade.php) "Services, sermons
      and songs" card shrinks to four buttons: **Services**, **Review inbox** (badge = total
      attention count from `AdminAttentionCounts`, cached per contract C1), **Manage sermons**,
      **Song catalogue**. (Upload actions live behind the hub's Add menu now.) When
      `service-tracking.enabled` is false, the Services and Review-inbox buttons are hidden and
      the sermon-recording upload remains reachable (contract C5) — today's grid silently links
      to 404s in that state; this fixes that.
- [ ] Old queue pages stay routable (bookmarks, mid-flight habits) but lose their members-home
      entries. Their header buttons gain a single `Review inbox` link; the rest of the
      cross-link mesh on those pages is removed.

### 2.4 Tests

- [ ] New `ReviewInboxTest` covering: rendering of each kind, each quick action (assert it
      delegates to the existing action and refreshes the list), filter chips (including the
      `Services` chip for merges/flags), empty state, per-action authorization (non-admin user
      rejected on every mutating method, per C4), config gates, and the blob-column guard for
      the segment source (extend the TD-004B pattern).
- [ ] Update [MembersTest](../../tests/Browser/MembersTest.php) (Dusk) for the new button set.
- [ ] Keep `AdminInboundEmailReviewTest`, `AdminSectionPublicationQueueTest`,
      `ProcessingReviewListTest` passing untouched (pages still live until Phase 5).

**Exit criteria:** every reviewable item type appears in one inbox with working quick actions;
members home and the hub route attention through it; legacy queue pages still pass their tests.

---

## Phase 3 — Service workbench

**Goal:** the service detail page becomes the single in-context review surface. Largest phase —
land as several PRs in the listed order.

### 3.1 Pipeline stepper header (small, independent)

- [ ] Extend [ChurchServiceShowPresenter](../../app/Presenters/ChurchServiceShowPresenter.php)
      to emit stepper states (`Plan / Recording / Processed / Review / Published`) from data the
      page already loads; render `x-admin.pipeline-steps` under the title. Test in
      [ShowChurchServiceTest](../../tests/Feature/Livewire/Admin/ChurchServices/ShowChurchServiceTest.php).

### 3.2 Inline section review on the timeline

- [ ] Move the Review Dashboard's per-section panel into the expandable area of
      [service-flow-row.blade.php](../../resources/views/livewire/admin/church-services/partials/service-flow-row.blade.php)
      (rows already have an Alpine `expanded` toggle): review-reason chips, type/title edits,
      children's-talk speaker picker, audio/video preview players, Approve/Reject/Requeue,
      merge-adjacent affordance between sibling rows.
- [ ] Port the supporting state/actions from
      [ServiceReviewDashboard](../../app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php)
      into [ShowChurchService](../../app/Livewire/Admin/ChurchServices/ShowChurchService.php):
      `sectionEdits`/`speakerEdits` seeding (**candidates only** — seeding every section of every
      run would balloon the Livewire payload), `saveSection`, `approvePendingPublications`
      (batch approve stays — per-service header button), `initiateMerge/confirmMerge/cancelMerge`,
      plus `ManagesSectionPublication`. **Fix during the port (contract C4):** `confirmMerge()`
      currently mutates without `$this->authorizeAdmin()` — add the call and a test; do not
      copy the gap. Keep the component lean by extracting a `Concerns\ReviewsServiceSections`
      trait if it grows past ~300 lines.
- [ ] Candidate-media preview URLs come from the existing
      `services.section-publications.preview-{audio,video}` routes — these routes **stay**
      (only the list page around them retires).
- [ ] Migrate the relevant tests from
      [AdminServiceReviewDashboardTest](../../tests/Feature/Livewire/AdminServiceReviewDashboardTest.php)
      to `ShowChurchServiceTest` as each behaviour moves.

### 3.3 Embedded segment confirmation

- [ ] Extract the segment-selection UI from
      [processing-review.blade.php](../../resources/views/livewire/admin/church-services/processing-review.blade.php)
      into a shared partial; render it on the workbench inside the run card when that run
      `requiresManualSermonReview()`. Confirm action reuses
      [ConfirmLivestreamSermonSegment](../../app/Actions/ConfirmLivestreamSermonSegment.php).
- [ ] The standalone `processing/{log}/review` page **stays** as the fallback for orphan runs
      (a paused run whose extracted date/slot matches no `ChurchService` — possible with bad
      filename parses) and renders the same shared partial. Inbox segment links prefer the
      workbench when a service match exists, else the standalone page.

### 3.4 Retire the Review Dashboard

- [ ] Re-point the inbox `Edit ↗` links (2.2) at workbench rows (`#section-{id}` anchors).
- [ ] `admin.services.review` → 302 to `admin.services.inbox`. Delete the component + view;
      keep [ServiceReviewDashboardQuery](../../app/Queries/ServiceReviewDashboardQuery.php)
      (the inbox and workbench consume it).
- [ ] Migrate remaining dashboard tests (summary cards → inbox; batch approve → workbench);
      add a redirect test.

**Exit criteria:** a flagged section or paused segment is fully resolvable on the service page;
the Review Dashboard URL redirects to the inbox; no review capability lost (test-mapped).

---

## Phase 4 — Inputs

**Goal:** both inputs share the admin visual language and one entry point (the Add menu).

- [ ] Restyle the MediaUpload form
      ([form.blade.php](../../resources/views/livewire/media-upload/form.blade.php)) to
      `x-admin.form-shell`: `x-select` for media type, `x-form-button`/`x-button` for actions,
      shared error/loading patterns. **Keep intact:** drag-drop Alpine controller, chunked
      upload progress, SSE status stream, auto-trim toggle, the singleton-per-page contract
      documented in [MediaUpload.php](../../app/Livewire/MediaUpload.php). Behavioural assertions
      live in `MediaUploadFieldTest` / `MediaUploadProgressTest` / `MediaUploadStatusTest` —
      they must pass unchanged.
- [ ] Move the page under the services prefix: route `admin.services.upload-recording`
      (`/admin/services/upload-recording`), with `admin.sermon-upload.create` kept as a 302.
      Title: "Upload recording". Update the Add menu target.
- [ ] [UploadChurchService](../../resources/views/livewire/admin/church-services/upload-church-service.blade.php)
      header: cross-link buttons reduce to the Add-menu pattern (`Back to services` only);
      sidebar "Recent imports" stays.
- [ ] On both upload pages, success states link onward to the created/affected service
      (already true for `.osz`; for recordings, link to the matched service when the identity
      resolver finds one — the workbench is where processing progress lives).
- [ ] Dusk: smoke-test the restyled recording form (type select → file input appears) since
      this is the page with the most bespoke JS. Feature tests: route redirect.

**Exit criteria:** both upload forms read as one product; old sermon-upload URL redirects;
upload JS behaviour tests unchanged and green.

---

## Phase 5 — Retire the remaining queues + polish

- [ ] `admin.services.inbound-emails` → 302 inbox `?filter=emails`. Delete component/view after
      porting any uncovered behaviours (re-parse, edit-and-approve are already in the inbox).
      Migrate `AdminInboundEmailReviewTest`.
- [ ] `admin.services.processing.review.index` → 302 inbox `?filter=segments`. Delete
      list component/view. Migrate `ProcessingReviewListTest`.
- [ ] `admin.services.section-publications` → 302 inbox `?filter=sections`. Delete list
      component/view (preview-media routes stay). **Conscious loss:** the global
      published-sections history table goes; per-service publication state remains on the
      workbench timeline and outputs are on the Sermons list. Revisit only if missed in
      practice. Migrate `AdminSectionPublicationQueueTest`.
- [ ] Sweep for dead references: `route('admin.services.review')` etc. in views/components,
      members-home counts, breadcrumbs:
      `rg -n "services\.review|section-publications|processing\.review\.index|inbound-emails"`.
- [ ] Colour-discipline pass over the surviving pages (service-type chips → neutral; amber
      reserved for needs-human) — touches
      [list-church-services](../../resources/views/livewire/admin/church-services/list-church-services.blade.php),
      timeline partials, inbox.
- [ ] Redirect tests for all three retired routes; full quality gates; update
      [docs/design-style-guide.md](../design-style-guide.md) if any new shared component
      (`pipeline-steps`, `action-menu`, `attention-strip`) merits a gallery entry, and add them
      to `/dev/components`. While in that file, **fix its stale intro line** (it still says
      "Laravel 12 + Livewire + Tailwind v3"; the project is Laravel 13 / Livewire 4 /
      Tailwind v4).

**Exit criteria:** 6 surfaces remain (hub, workbench, inbox, edit form, the two restyled upload
pages behind the single Add menu, songs); all legacy queue URLs redirect; no orphaned
views/components; suite + Dusk green.

---

## Test migration map

| Existing test | Destination |
|---|---|
| `AdminChurchServiceTest` (list) | Grows in Phase 1 (hub) |
| `ShowChurchServiceTest`, `ServiceFlowRowRenderingTest` | Grow in Phase 3 (workbench) |
| `AdminServiceReviewDashboardTest` | Split: inbox quick-actions (P2) + workbench editing (P3) + redirect test (P3.4) |
| `AdminInboundEmailReviewTest` | Inbox email actions (P2/P5) + redirect test |
| `AdminSectionPublicationQueueTest` | Inbox section filter (P2/P5) + redirect test |
| `ProcessingReviewListTest` | Inbox segment entries (P2/P5) + redirect test |
| `MediaUploadFieldTest` / `MediaUploadProgressTest` / `MediaUploadStatusTest` | Unchanged (P4 must keep green) |
| `MembersTest` (Dusk) | Updated button set (P2) |
| Action/query tests (`Actions/ServiceReview/*`, `Actions/InboundEmail/*`, etc.) | Untouched — domain layer doesn't change |

## Risks & mitigations

- **ShowChurchService component bloat** (P3): cap via extracted traits/partials; presenter keeps
  render() thin. Watch Livewire payload size — section edit state arrays should only seed for
  review-candidate sections (as the dashboard does today).
- **Unbounded review groups** (P2): `reviewGroups()` has no pagination; the inbox caps per
  source and the hub only uses counts. Do not ship the inbox without the caps.
- **Route order**: new `/services/inbox` and `/services/upload-recording` must precede the
  `/{churchService}` catch-all (existing pattern in [routes/web.php](../../routes/web.php)).
- **Orphan paused runs** (P3.3): keep the standalone segment-review page; never assume a run
  has a matching service.
- **Bookmarked URLs**: every retired route 302s to its successor — no 404s for muscle memory.
- **Config-gated installs**: all new surfaces honour `service-tracking.enabled` and
  `media-processing.section_publishing.enabled` exactly as the pages they replace.
