# Service Screens Consolidation — Volunteer-First Admin IA

> **Status (2026-07-19): approved by maintainer; not started.** This plan reorganises the
> service admin screens from seven surfaces to three (plus the song catalogue). It was
> designed against the code as of 2026-07-19 (route list `routes/web.php:190-214`, components
> under `app/Livewire/Admin/ChurchServices/`).
>
> **Dependencies and coordination:**
>
> - **Phases 1–3 are independent of the noise plan** and can start any time. **Phase 4 (inbox
>   fold-in) must wait for Workstreams A + B of
>   [REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md](REVIEW-QUEUE-NOISE-AND-REVIEW-UI-2026-07-18.md)**
>   — folding today's 222-item queue into the main services list would poison the hub. That
>   plan's **C2 (Confirm action), C4, C5, C6** remain valid and should land before or during
>   Phase 1 here; its **C3 (inbox count copy) is superseded by Phase 4 of this plan** — do not
>   implement C3.
> - **Simplification remainder** ([JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)):
>   R3 makes mechanical changes to the same Livewire components (`EnsureServiceTrackingEnabled`
>   middleware, trait tidy-ups) — either order works, just rebase carefully. **R9 deletes the
>   workbench `reclassify()` affordance and the `timeline-alignment-*` partials**; Phase 1 here
>   must not deepen investment in those partials (render them where they render today; do not
>   move review UI *into* them). If R9 is in flight, coordinate merges on
>   `show-church-service.blade.php` and its partials.
> - **Agents must not** touch anything on the backlog item 1.5 deletion list (see the noise
>   plan's header for the class list) and must not change review-queue predicates — that is the
>   noise plan's Workstream A, not this plan.
> - Decisions **SD1–SD4** were resolved by the maintainer on 2026-07-19 (all four
>   recommendations accepted — see the decisions table). The whole plan is agent-executable.

## Design goal

The current IA is organised around **pipeline internals**: source formats (email vs `.osz` vs
media file) each get an upload page, review items are queued by **database kind**
(emails/sections/segments/service-flags), and the per-service surface is split into a "view"
page and an "edit" page that don't map to the real domain split (planned order of service vs
detected sections).

A volunteer does exactly three things: **put something in** (email, OoS, recording),
**confirm what the machine did**, and **fix the odd mistake**. The target IA gives each job
one screen, with the **service (date + slot)** as the organising unit throughout:

| Target screen | Job | Absorbs |
|---|---|---|
| **Services hub** (`/admin/services`) | "What needs me?" — triage | `ListChurchServices` + `ReviewInbox` |
| **Service page** (`/admin/services/{id}`) | Everything about one service: plan (editable), recording, review, publish | `ShowChurchService` + `ManageChurchService` (edit role) |
| **Add page** (`/admin/services/add`) | Get something in when no service exists yet | `UploadChurchService` + `SubmitEmailText` + `MediaUpload` entry + manual create entry |
| Song catalogue (unchanged) | Reference data | — |
| Sermon-segment chooser (unchanged) | Focused modal task, linked from service page | — |

## Verified current inventory (2026-07-19)

| Route | Component | Actual role |
|---|---|---|
| `admin.services.index` | `ListChurchServices` | Table + "This Sunday" hero + 5 attention chips linking to inbox filters (`ListChurchServices.php:168-177`) |
| `admin.services.inbox` | `ReviewInbox` | 5 item kinds (email / section / segment / merge / service_flag) grouped by service; email actions (approve, edit-and-approve, re-parse, reject), `markServiceReviewed`, plus `ManagesSectionPublication` (approve/reject/requeue) |
| `admin.services.show` | `ShowChurchService` | The real workbench: processing runs, aligned timeline, inline **section** editing via `ReviewsServiceSections`, publication approval, merge resolution, reclassify/delete run |
| `admin.services.edit` / `.create` | `ManageChurchService` | Edits/creates the **planned** items via `ChurchServiceFormData`. Three roles: (a) manual create, (b) plan edit, (c) email edit-and-approve editor (prefills from `?inboundEmailId=&planKey=`, and `?date=&service=` for orphan inbox groups) |
| `admin.services.upload` | `UploadChurchService` | `.osz` upload; date/slot inferred from filename |
| `admin.services.submit-email` | `SubmitEmailText` | Paste email body → same pipeline as the Mailgun webhook |
| `admin.services.upload-recording` | `MediaUpload` (508 lines) | Audio/video/livestream upload + SSE progress. **Gated by the Sermon create Gate, not service-tracking** — deliberately reachable when service tracking is disabled (`media-upload/form.blade.php:12-14`) |

Cross-cutting facts an implementer needs:

- `ReviewsServiceSections` and `ManagesSectionPublication` (in
  `app/Livewire/Admin/ChurchServices/Concerns/`) are already shared traits — the section-editing
  and publication logic is component-independent. The consolidation is mostly view/routing
  work, not action rewrites.
- `AdminAttentionCounts` provides cached true totals; `ReviewInboxQuery::build()` returns
  service-grouped items with a per-source cap; `ChurchServiceRollupQuery` provides the per-row
  status + pipeline steps already rendered on the hero card via `x-admin.pipeline-steps`.
- `MediaUpload::startProcessing()` passes `SermonService::tryFrom($this->serviceOverride)` to
  the processor (`MediaUpload.php:204-210`) but has **no date parameter** — the date comes
  from filename/`fileModifiedDate` inference. Contextual upload (Phase 3) adds one.
- Entry links live in: the hub's "Add service" action menu
  (`list-church-services.blade.php:9-22`), members home quick links
  (`members/home.blade.php:38-63`), `sermons/index.blade.php`, `list-sermons.blade.php`,
  `sermon-segment-review.blade.php`, and the legacy redirects at `routes/web.php:170,200-203`.
- Test estate: `ShowChurchServiceTest`, `AdminChurchServiceTest` (manage),
  `ManageChurchServiceAuthTest`, `ReviewInboxTest` + `ReviewInboxQueryTest`,
  `UploadChurchServiceTest`, `SubmitEmailTextTest`, `MediaUploadTest` (Livewire + Api +
  AutoSubmit), `AdminUrlStateTest`, `AdminLivewireAuthorizationTest`
  (`adminLivewireRouteScenarios()` at `tests/Feature/Admin/AdminLivewireAuthorizationTest.php:162`
  — **every route change must be reflected there**, per the Livewire page-layout coverage
  convention), Dusk `UploadRecordingTest` + `MembersTest`.

## Phases

Every phase: activate the `frontend-design` skill before any view work; British English
strings; failing test first for behaviour changes; end with the four quality gates
(`pint --dirty`, `composer phpstan`, `test --compact --parallel`, `dusk`). Old URLs get
`Route::redirect` entries following the existing pattern at `routes/web.php:200-203` (the
retired service-UI URLs all 302 — keep that convention). One PR per phase; phases are
separately shippable and each leaves the UI coherent.

### Phase 1 — one service page (merge edit into show) `[design]`

**Outcome:** `/admin/services/{id}` is the only per-service URL. It shows the plan and the
detected sections as the two things they are, and the plan is editable in place.
`/admin/services/{id}/edit` becomes a redirect. `ManageChurchService` keeps its **create**
and **email edit-and-approve** roles only.

1. **Move the plan editor into `ShowChurchService`.** Add the `ChurchServiceFormData` form
   object and the item-manipulation actions (`addItem`, `removeItem`, `moveItemUp/Down`,
   `selectSong`, `updatedFormItems`, `save`) to `ShowChurchService` — extract them from
   `ManageChurchService` into a shared trait (suggested:
   `Concerns/EditsPlannedItems`) so create keeps using them. `save()` on the service page
   calls the same `SaveChurchServiceFromAdmin` action but stays on the page (no redirect).
2. **View structure.** The page presents two clearly-labelled regions:
   - **"Order of service"** (the plan): the current `planned-only-list` position. Default
     rendering is the read view; an "Edit order of service" toggle swaps in the item editor
     from `manage-church-service.blade.php` (extract the item-editor markup into a shared
     partial/component so create and the service page render one implementation). Keep the
     save-clears-review-flag behaviour and the ordering-conflict error path
     (`ManageChurchService.php:130-137`).
   - **"Recording"** (detected sections): the existing run cards/timeline **unchanged in
     structure** (R9 will prune them; don't remodel them here). The noise plan's C2 Confirm
     button and C4/C5 fixes land in this region.
   - Empty states become the contextual entry points: no plan → "Paste the email · Upload the
     `.osz` · Add items by hand" (linking to the Add page pre-filtered, Phase 2, and inline
     edit mode); no recording → "Upload a recording" (Phase 3 makes this pre-filled).
3. **Routing.** `admin.services.edit` → `Route::redirect` to `admin.services.show` (Livewire
   can't carry the "open in edit mode" flag through a plain redirect — use a `?edit=1` query
   param read by an `#[Url]` property to auto-open the editor). `admin.services.create` stays
   on `ManageChurchService`; delete the now-dead edit branches from it (`churchService`
   mount path, "View service" button) once nothing routes there with a model.
4. **Tests.** Move/adapt the edit-role tests from `AdminChurchServiceTest` into
   `ShowChurchServiceTest` (item CRUD, ordering conflict, song suggestion selection,
   review-flag clearing on save); keep create-role tests where they are. Update
   `ManageChurchServiceAuthTest`, `AdminLivewireAuthorizationTest` scenarios (drop
   `admin.services.edit`, assert the redirect separately), `AdminUrlStateTest` for the new
   `?edit` param. Redirect test: GET old edit URL → 302 to show.

### Phase 2 — one Add page (merge the three intake pages) `[design]`

**Outcome:** a single `/admin/services/add` page with two intents — **"Order of service"**
and **"Recording"** — replaces `upload`, `submit-email`, and the "Create manually" scatter.
Routing to the right parser is the code's job, not the volunteer's.

1. **New route + component** `admin.services.add` → `AddToService` (name flexible), a thin
   host with a segmented control for the intent (`#[Url]` `intent` param so links can deep-link
   `?intent=plan` / `?intent=recording`).
   - **Plan intent:** one card accepting *either* a dropped/browsed `.osz` *or* pasted email
     text — a file input and a textarea are trivially co-presentable; whichever is provided on
     submit routes to the existing `UploadChurchService` save path or `SubmitEmailText` submit
     path. Implementation choice for the agent: embed the two existing components as Livewire
     children and let the host toggle them, **or** merge their logic into the new component
     and delete both (preferred if the merged component stays under ~200 lines — they are 111
     and 94 lines and share no state). Keep the post-submit affordances: recent imports list,
     "view in review inbox" (Phase 4 changes this link target to the hub), optional
     from/subject fields collapsed behind a disclosure. A tertiary "start from a blank plan"
     link goes to `admin.services.create`.
   - **Recording intent:** render the existing `MediaUpload` component as a child, unchanged.
     **Do not move or re-gate `MediaUpload`**: `admin.services.upload-recording` must remain a
     working standalone route because it is reachable when service tracking is disabled
     (Sermon create Gate only). The Add page's recording tab simply hosts the same component;
     when service tracking is disabled the Add page 404s (like its siblings) and the
     standalone route remains the entry.
2. **Routing.** `admin.services.upload` and `admin.services.submit-email` →
   `Route::redirect` to `add?intent=plan`. `admin.services.upload-recording` **stays**
   (see above) — the Add page links to it rather than duplicating it, or hosts it; either
   way the URL keeps working.
3. **Link sweep.** Hub "Add service" menu collapses to two items (Add… / Upload recording —
   or a single "Add" button, maintainer taste, see SD2); members home quick links
   (`members/home.blade.php`), `sermons/index.blade.php`, `list-sermons.blade.php`, the
   empty-state buttons in `list-church-services.blade.php:144-153`, and the cross-links inside
   `submit-email-text.blade.php` / `upload-church-service.blade.php` all repoint.
4. **Tests.** New feature test for the Add host (intent switching, both submit paths, disabled
   404); update `UploadChurchServiceTest` / `SubmitEmailTextTest` (or fold into the new test
   if the components merge); redirect tests for the two retired URLs;
   `AdminLivewireAuthorizationTest` scenarios (+`admin.services.add`, − retired routes); Dusk
   `MembersTest` (quick links) and `UploadRecordingTest` (only if its entry URL changed —
   it shouldn't).

### Phase 3 — contextual upload from the service page `[design]`

**Outcome:** uploading *to a service you're looking at* pre-answers the date/slot question
that filename inference and the morning/evening override exist to guess.

1. Add optional mount/`#[Url]` context to `MediaUpload`: `churchServiceId` (or `date` +
   `service`). When present: pre-select `serviceOverride`, pass the known date through to
   `$this->processor->process(...)` so identity resolution prefers it over
   filename/`fileModifiedDate` inference, and show a "Uploading for **22 Feb 2026 —
   Morning**" banner with a "wrong service?" escape hatch. **Verify against
   `MediaProcessingIdentityResolver` / the processor's signature first** — if the processor
   cannot yet accept an explicit date, that seam is the first commit (with its own test).
2. The service page's "no recording yet" empty state and the plan-empty state link to the
   Add page / upload-recording with this context.
3. **Tests:** context pre-fill test in `tests/Feature/Livewire/MediaUploadTest.php`; an
   identity-resolution test proving an explicit date wins over a contradictory filename;
   Dusk `UploadRecordingTest` still green.

### Phase 4 — fold the inbox into the hub `[design]` — **gated on noise plan A + B**

**Outcome:** `/admin/services` answers "what needs me?" directly; `/admin/services/inbox`
becomes a redirect. Nothing the inbox can do disappears — resolution moves to where the
context is.

1. **Hub layout.** Above the table: a **"Needs attention"** section replacing both the
   attention-chip strip and the separate inbox. Reuse `ReviewInboxQuery::build()`'s
   service-grouped output, but render each group as **one row per service** with a summary
   line ("2 songs to confirm · sermon segment needs choosing") linking into the service page
   — not the inbox's per-item cards. Two item kinds cannot defer to a service page and keep
   inline actions:
   - **Emails** (may predate any service): keep approve / edit-and-approve / re-parse /
     reject inline (the actions move from `ReviewInbox` to the hub component or a dedicated
     child component). The bulky diagnostics `<details>` block
     (`review-inbox.blade.php:146-213`) moves behind the email row's disclosure, unchanged.
   - **Orphan section groups**: keep the "Create this service" affordance
     (`review-inbox.blade.php:52-58`).
   All other kinds (sections, segments, merges, service flags) render as the service-row
   summary; their actions already exist on the service page (segments via the chooser link).
2. **What is deliberately dropped:** the four kind-filter chips (kind is an implementation
   category), and the per-item section cards with inline approve/reject — after the noise
   plan's A-series + C2, per-section resolution on the service page is one click, so the
   inbox's inline duplicates are not worth their confusion. If the maintainer wants to keep
   inline section approve/reject on the hub, that is SD3.
3. **Counts:** the section header shows the true total from `AdminAttentionCounts` with the
   capped-list sentence in plain copy ("Showing the newest N of M — resolve items to see
   older ones"). This supersedes noise-plan C3.
4. **Routing:** `admin.services.inbox` → `Route::redirect` to `admin.services.index`
   (drop the `?filter=` distinctions; the legacy redirects at `routes/web.php:200-203`
   re-point to the hub). `ReviewInbox` component + view are deleted once their email actions
   have moved.
5. **Tests:** port `ReviewInboxTest`'s action coverage to the hub component;
   `ReviewInboxQueryTest` survives (the query stays); update `AdminLivewireAuthorizationTest`
   (− inbox route), `AdminUrlStateTest` (inbox `filter` param dies, hub params live),
   redirect tests for all four legacy inbox URLs; a rendering test that a service with mixed
   attention kinds produces one row with the right summary.

## Decisions (resolved by maintainer, 2026-07-19)

| # | Decision | **Resolution** | Applies to |
|---|---|---|---|
| SD1 | Phase 1 edit affordance | **Edit-mode toggle** — one implementation shared with create; no per-item inline editor | Phase 1 |
| SD2 | Hub "Add" affordance | **Single "Add" button** → Add page; the intent switch does the rest | Phase 2 |
| SD3 | Sectional actions on the hub's attention rows | **Defer to the service page** — attention rows are summaries + links; only emails and orphan-group creation keep inline actions | Phase 4 |
| SD4 | Fate of `SubmitEmailText` + `UploadChurchService` | **Merge into the new Add component and delete both** | Phase 2 |

## Sequencing summary

1. **Phase 1** (service page merge) — start any time; highest confusion-removal per effort.
   Coordinate with noise-plan C2/C4/C5 (same views) and R9 (don't deepen timeline partials).
2. **Phase 2** (Add page) — independent of Phase 1; any time.
3. **Phase 3** (contextual upload) — after Phases 1 + 2 (needs both surfaces).
4. **Phase 4** (inbox fold) — **only after noise plan Workstreams A + B are merged** and the
   local queue demonstrably drops to the noise plan's target (~30 sections / ~12 services).

## Acceptance criteria

- Exactly three service screens remain (hub, service page, add page) plus songs and the
  sermon-segment chooser; every retired URL 302s to its successor.
- A volunteer can complete the full weekly flow — approve Thursday's email, upload Sunday's
  OoS and livestream, confirm the flagged items, publish — visiting only the hub, one service
  page, and the Add page, with no decision that requires knowing a file format or pipeline
  internals.
- The plan-vs-detected distinction is visible on the service page as two labelled regions,
  not two URLs; editing either kind happens on that one page.
- `MediaUpload` remains reachable and functional with service tracking disabled (existing
  Dusk `UploadRecordingTest` + the disabled-state feature tests stay green).
- No review predicate, publication gate, or extraction policy changes anywhere in this plan —
  `SermonAutoExtractionPolicy`, `ReviewInboxQuery` predicates, and
  `ServiceReviewDashboardQuery` semantics are untouched (Workstream A owns those).
- `AdminLivewireAuthorizationTest` scenarios match the final route table; all four quality
  gates pass per phase.
