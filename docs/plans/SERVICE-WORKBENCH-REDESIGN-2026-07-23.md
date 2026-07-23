# Service Workbench Redesign — One Service Record

> **Status (2026-07-23): ready to implement — the R9 dependency is satisfied.** This plan
> records the maintainer's review of `/admin/services/790` and is executable without further
> design decisions. It supersedes the service-page view structure specified in Phase 1 of the
> now-completed service-screens consolidation plan.
>
> **Dependency:** R9 of
> [JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)
> has removed the heuristic path (commits `01dd1dcd0`..`a130092ee`), which unblocks this rewrite.
> R9 did **not** delete the `timeline-alignment-*` partials it had been scheduled to remove — they
> still exist and are still `@include`d by `processing-run-card.blade.php:51`. **This plan now owns
> that deletion (step C.1).** The useful comparison data from that table must survive in the primary
> service record, and no work should deepen those partials before step C deletes them.
>
> **Coordination:**
>
> - R14 will fold `tests/Feature/Livewire/AdminChurchServiceTest.php` into the namespaced
>   per-component suites. Put all new coverage in
>   `tests/Feature/Livewire/Admin/ChurchServices/`; do not add assertions to the legacy flat
>   suite.
> - The design-system refresh may land before or after this work. Rebase over its token and
>   component changes rather than restoring old slate/gray or button variants.
> - Do not change which sections enter the review queue, publication eligibility, extraction
>   policy, or plan/recording matching. This plan changes the workbench's status contract and
>   presentation of existing evidence, not the review predicates or media pipeline.
>
> **Who benefits:** the operator and volunteers who review a Sunday service after uploading
> its presentation plan and recording.
>
> **What observably improves:** the page has one title, one truthful status and next action,
> and one chronological service record in which transcript evidence and genuine problems are
> visible without opening multiple disclosures.

## Why the current page is confusing

The screenshot is not primarily a visual-polish problem. Three different concepts are competing
for prominence:

1. the partial plan imported from OpenLP or email;
2. the sections detected in the recording; and
3. processing and publication diagnostics.

The code already builds a merged plan/recording model:
`ChurchServiceShowPresenter` creates both `serviceTimeline` and its human-readable `serviceFlow`
from the same `ServiceRecordTimeline` rows. The view then displays the plan separately, displays
the flow under "Recording", and repeats the same comparison in a detailed alignment table. This
makes the least complete renderings easiest to see and the most useful evidence hardest to reach.

Specific defects verified against the current code and screenshot:

- The layout renders the service label as a large public-style `<h1>`, the breadcrumb repeats it,
  and `<x-admin.page>` renders a second `<h1>`.
- The page title uses the terse `14 Jun 2026 Morning` data format rather than a human page title.
- "Back to services" duplicates the Services breadcrumb.
- The pipeline stepper is not a sequence. "Review" can be blocked by `needs_review` or a pending
  merge even when no run completed, while "Recording" is considered complete as soon as any run
  row exists. The screenshot's failed run therefore appears to have reached Review without being
  Processed.
- The plan and recording are separate sections even though their relationship is the operator's
  real subject.
- Every detected row without a linked plan item is internally `unplanned`. The workbench gives
  these rows a rose border and sometimes a "Not in plan" badge, although an OpenLP plan normally
  contains only slide-backed items. Prayers, notices, readings, the sermon and transitions are
  expected to exist only in the recording.
- "Processing Timeline" is a fixed technical log. Missing historic step logs are synthesised as
  "Not recorded", creating a large diagnostic block that drives no operator action.
- The labelled transcript excerpt is behind each row disclosure even though it is one of the
  clearest ways to verify a detected section.
- The detailed table is a denser duplicate of the service flow. Its useful fields are planned
  context, detected context, timing and publication state; these belong in the primary record.
- Technical values—the run UUID, parse method, confidence, filename and exact step timestamps—are
  more prominent than the current failure and what to do next.
- The right sidebar repeats the date and service type and labels `updated_at` as "Imported", which
  is not a safe claim after later edits.
- The three-quarter-width content rail, narrow sidebar and full comparison table make the longest
  and most important part of the page harder to scan, especially below desktop widths.

## Target information architecture

The page should answer, in this order:

1. **Which service is this?**
2. **Does it need me, and what should I do next?**
3. **What happened through the service, and what evidence supports that?**
4. **What was the source plan and how can I edit it?**
5. **What technical detail do I need if something failed?**

### 1. Compact page identity

- Render exactly one `<h1>`: `Sunday 14 June 2026`.
- Render `Morning service` as supporting metadata or a neutral badge, not as part of the date.
- Use a browser title such as `Sunday 14 June 2026 — Morning service`.
- Use a shorter breadcrumb leaf such as `14 June 2026`; the parent Services crumb supplies the
  missing context.
- Remove "Back to services". Keep the existing copy-link control in the breadcrumb toolbar.
- Add a backwards-compatible `showHeading`/equivalent layout option so this workbench can suppress
  the layout's large public page header and let `<x-admin.page>` own the single page heading.
  Default the option to the current behaviour so unrelated admin pages do not change.

The header may show compact, non-repeated facts: service slot, current overall status, plan source
and last updated time. Do not repeat the date in a sidebar.

### 2. One truthful status and next action

Remove the five-dot `Plan → Recording → Processed → Review → Published` stepper from this page.
Those states are neither mutually exclusive nor sequential.

Expose the existing rollup status from `ChurchServiceShowPresenter` and add an explicit processing
failure state to `ChurchServiceRollupStatus`. The deterministic precedence should be:

1. a current run is actively processing → **Processing**;
2. the latest relevant run failed and no newer run succeeded or is running → **Processing failed**;
3. genuine attention remains → **Needs review**;
4. no run exists → **Plan only** or **Awaiting recording**, according to the existing date rule;
5. all publication conditions are met → **Published**;
6. otherwise → **Ready**.

Do not let an old failed run override a newer successful run. Select the latest non-superseded
matching run explicitly and cover that precedence with tests.

> **Supersession decides which run survives — the status rollup only reads it (revised OD-2, 2026-07-23).**
> `ProcessingRunSupersessionService` now ranks runs by transcript-**confirmed song count** first,
> then high-confidence coverage, then **completed status**, then the softer confidence terms.
> Confirmed matches are grounded evidence (the transcript matched the catalogue above the writeback
> threshold); classification `confidence` is only the segmentation classifier's self-assessment of
> section *type*. This corrects service 785, where a *failed* run that merely *inferred* its songs
> by projecting the plan had superseded a *completed* run that had actually confirmed them, leaving
> every song flagged for confirmation despite a better run existing.
>
> Two consequences for this status contract:
> - The "newer completed run beats older failure" case is now resolved at the **supersession**
>   layer whenever song evidence and coverage tie, not only in the status display. Key the rollup
>   off the surviving (non-superseded) winner; do not re-implement run selection here.
> - Supersession may still *legitimately* keep a **failed** run as the winner when it carries
>   genuinely better structure or song evidence (OD-2's original point — status never vetoes better
>   structure). The status contract must render that survivor truthfully as **Processing failed**;
>   it must not assume the winner is always a completed run.

The page renders one compact status summary with a plain-language explanation and one primary next
action where applicable:

| State | Explanation | Primary action |
|---|---|---|
| Plan only | The service is in the future and has no recording yet | Upload recording |
| Awaiting recording | The service date has passed and no recording is attached | Upload recording |
| Processing | Recording is still being analysed | None; show current progress |
| Processing failed | Name the failed step/message when available; do not claim Review is current | Upload a replacement recording |
| Needs review | State the number and kind of genuine attention items | Jump to first attention row |
| Ready | Processing completed and nothing needs attention | No forced action |
| Published | Published outputs are available | Link to the published sermon/children's talk where available |

Warnings and pending structure merges sit immediately after this status summary because they can
change the next action. The existing review/publication batch actions stay adjacent to the status,
not inside a pseudo-stage tracker.

### 3. One chronological service record

Replace the separate "Order of service" and "Recording" regions with one primary
**Service record**.

When a classified run exists, its merged chronological rows are the main content. Each row shows:

- time range and duration;
- detected type and title;
- section summary, when present;
- a short transcript excerpt, visible without expanding the row;
- the matching plan item/source when one exists;
- genuine review and publication states;
- contextual actions for rows that actually need input.

The transcript excerpt and summary must not repeat identical text. If both exist and differ, show
the summary first and a short quoted excerpt beneath it. Preserve the current stored-data boundary:
the UI uses the existing section transcript excerpt and does not fetch or generate new transcript
content.

The plan/recording relationship is provenance, not automatically severity:

| Relationship | Default presentation |
|---|---|
| Detected section matches a plan item | Quiet `Matches plan` context with the plan title/source |
| Detected section has no plan item | Neutral `Recording only` context, or omit the badge when it adds no value |
| Plan item has no detected section | Neutral `Plan only` context |
| Plan and detected values genuinely conflict | Amber mismatch treatment with expected and detected values |
| Review predicates flag the section | Warning/error treatment from the actual review reason |

Remove rose row borders, rose table backgrounds and "Unplanned" language that arise solely from
`row_type === 'unplanned'`. Retain the internal row type if matching code needs it; it must not be
presented as a problem. Red/rose is reserved for failure or an action the operator genuinely needs
to take.

Rows with no actions or additional metadata are static content, not disclosure buttons. Rows with
review controls or useful secondary detail retain a keyboard-operable disclosure with
`aria-expanded`, `aria-controls` and a uniquely identified region.

When no classified run exists, the same Service record region shows the plan items in order and
the relevant recording empty/failure state. It must not introduce a second peer-level "Order of
service" region.

### 4. Explain and edit the source plan without making it a second page

Keep "Edit plan" as a secondary header action. In edit mode, show the existing shared planned-items
editor above the record and return to the unified read view after saving.

In read mode, show one short source note:

- OpenLP: `Presentation plan from OpenLP. It usually contains slide-backed items only, so other
  parts of the service may appear only in the recording.`
- Email: `Plan imported from an email. It may describe more of the service than the presentation
  slides.`
- Manual/mixed: use equally plain provenance copy without making coverage claims the data cannot
  support.

Derive this copy from the actual item sources. Do not infer that the plan is complete, and do not
make plan absence a review reason.

The existing plan list should not be rendered in full above a classified recording. Planned
titles, source and match state are already represented in the merged record. If a compact
"View plan items" disclosure remains useful for editing context, it is secondary and must not
duplicate the primary row content by default.

### 5. Demote technical processing detail

For each upload, replace the prominent UUID-led run card with a concise upload summary:

- friendly label (`Recording uploaded 20 June 2026 at 19:14`);
- current run status and failure message;
- source filename when useful;
- the primary service record for the selected/current run.

Move the run UUID, fixed processing steps, exact timestamps, durations, parse method, confidence
and filename diagnostics into a collapsed **Technical processing details** disclosure. Show actual
recorded steps before synthesised historic placeholders; after R9, prefer omitting meaningless
"Not recorded" rows when no diagnostic evidence exists.

Move "Delete upload" into this technical/destructive area or an overflow action. It must remain
available with its confirmation and authorisation, but it should not be the most prominent action
beside a failed upload.

Use the full available workbench width for the service record. Fold the current sidebar metadata
into the compact header/source note and technical disclosure. Rename the current `updated_at`
display to `Last updated`; only display `Imported` if a real import timestamp is available.

For multiple non-superseded runs, make the newest relevant run the primary record and list older
runs under an **Other uploads** disclosure. Never merge sections from different recordings into
one apparent chronology.

## Implementation sequence

Implement as one PR after R9, with commits kept green in this order.

### A. Pin the status contract with failing tests

1. Extend `ChurchServiceRollupStatus` with `ProcessingFailed`, including neutral-to-danger badge
   styling consistent with the refreshed admin tokens.
2. Update `ChurchServiceRollupQuery` to select the latest relevant run deterministically and apply
   the precedence above.
3. Pass the rollup status, attention count and selected primary run through
   `ChurchServiceShowReadModel`.
4. Add a small presenter-level status summary/next-action structure. Keep display copy out of the
   query; the query owns state, the presenter owns workbench wording and links.
5. Prove active, failed, failed-then-succeeded, review, no-run, ready and published cases.

This is the only backend behaviour change. It should also make a current processing failure
truthful on the services hub; update that rendering and its focused tests rather than allowing the
hub and workbench to disagree.

### B. Remove the duplicate page chrome

1. Add the opt-out for the layout page header with a backwards-compatible default.
2. Give browser title, breadcrumb leaf, visible date heading and service-slot label separate
   values rather than deriving all four from one terse label.
3. Remove the duplicated Back action and sidebar date/type.
4. Add an HTML assertion that the rendered workbench contains exactly one `<h1>`.

### C. Replace the split regions with the unified record

1. Retain the detailed alignment table's useful comparison fields — planned context, detected
   context, timing and publication state — in the merged row view data rather than recreating the
   table. Then **delete the now-orphaned partials** and their include:
   `resources/views/livewire/admin/church-services/partials/timeline-alignment-table.blade.php`,
   `timeline-alignment-table-row.blade.php`, and the `@include` at
   `processing-run-card.blade.php:51`. These three form a self-contained cluster
   (`processing-run-card` → `timeline-alignment-table` → `timeline-alignment-table-row`) with no PHP
   or test references, so once the comparison fields are preserved and the include removed they can
   be deleted safely. The table consumes `$processingRunView->serviceTimeline`, the same source the
   merged record uses, so no data plumbing is lost.
2. Prefer a typed row/read-model structure over growing anonymous Blade conditionals. Build the
   display data in `ChurchServiceShowPresenter` or a focused data object; Blade should render
   already-decided labels, tones and optional fields.
3. Create a service-record partial/component and a row partial/component. Reuse the existing
   section review, merge and publication actions.
4. Make excerpt text visible, de-duplicate summary/excerpt copy, and reserve disclosure behaviour
   for rows with hidden content or actions.
5. Keep the plan editor's existing Livewire actions and security checks. Change only where the
   editor appears and how the read state is composed.

### D. Correct severity, provenance and diagnostics

1. Replace automatic unplanned warning treatments with the neutral relationship language above.
2. Add source-coverage copy for OpenLP, email and manual/mixed plans.
3. Collapse processing and import diagnostics under Technical processing details.
4. Surface current failure text and the supported recovery action outside that disclosure.
5. Move the destructive upload action into the diagnostic/overflow region.

### E. Finish responsive and accessible behaviour

1. At narrow widths, stack the time range above the row title; allow status badges to wrap; keep
   transcript copy at a readable line length. There should be no primary horizontal-scrolling
   table.
2. Keep focus-visible styles and 44px touch targets on row disclosures and actions.
3. Use semantic `<ol>`/`<li>` chronology, one `<h1>`, ordered section headings and live status
   only where asynchronous Livewire updates require it.
4. Preserve `wire:navigate` on internal links and `wire:key` on every rendered run/row.
5. Verify edit, review, merge, publication, upload replacement and delete confirmation flows with
   keyboard interaction.

## Test plan

Follow the bug workflow: write the failing rendering/state tests first, confirm each fails for the
current reason, then implement.

### Focused PHP/Livewire coverage

Add or update namespaced tests for:

- exactly one `<h1>`, the long date format, shorter breadcrumb and absence of Back to services;
- a latest failed run rendering **Processing failed**, never an active Review stage;
- a newer completed run superseding an older failure for status purposes;
- OpenLP coverage guidance and email coverage guidance;
- recording-only sections remaining visible without rose/error treatment or a fake attention
  count;
- genuine mismatch/manual-review/pending-publication states retaining their warning and actions;
- transcript excerpt visible in the row without toggling a disclosure;
- a clean row not being rendered as a button, while actionable rows preserve disclosure ARIA;
- plan-only, recording-only, processing, failed-without-sections, completed-with-sections and
  multiple-run layouts;
- technical details collapsed by default, with the run ID and recorded steps still available;
- `updated_at` labelled Last updated rather than Imported;
- edit-plan mode, batch confirmation/publication, section editing, merging and upload deletion
  continuing to call `authorizeAdmin()`.

Primary homes:

- `tests/Feature/Livewire/Admin/ChurchServices/ShowChurchServiceTest.php`
- `tests/Feature/Livewire/Admin/ChurchServices/ServiceFlowRowRenderingTest.php` (rename if the
  replacement row component warrants it)
- `tests/Feature/Queries/ChurchServiceRollupQueryTest.php`
- existing presenter/builder integration suites only where their data contract actually changes

Do not add new coverage to `tests/Feature/Livewire/AdminChurchServiceTest.php`; port any still-live
workbench assertions encountered there into the namespaced suite in preparation for R14.

### Browser and visual coverage

- Add Dusk coverage for edit-plan toggle/save, opening an actionable review row, opening technical
  details, and keyboard operation. Dusk owns behaviour.
- Add a deterministic Playwright visual fixture/spec for this workbench at desktop and mobile
  widths. Seed stable dates, transcript excerpts and statuses; do not use live relative timestamps
  or random UUID text in the screenshot region. Playwright owns appearance only.
- Review the desktop and mobile diffs by eye, with particular attention to row density, transcript
  readability, warning-colour restraint and the failed-run state.

## Acceptance criteria

- The workbench has exactly one `<h1>` and no duplicated Back action.
- The visible title reads as a date; service slot is supporting metadata.
- One overall status agrees with the newest relevant processing run and the services hub.
- A failed latest run cannot appear to have progressed to Review.
- The plan and recording are presented as one chronological service record, not two peer sections
  plus a duplicate table. The `timeline-alignment-table*` partials and their `processing-run-card`
  include are deleted, and no view references them.
- Transcript excerpts are visible by default.
- Missing plan linkage is neutral provenance. Only real review predicates, mismatch or processing
  failure use warning/error colour.
- OpenLP's slide-only coverage is explained; emailed/manual plan provenance remains visible.
- Technical processing steps, UUIDs and import diagnostics remain available but are collapsed and
  secondary.
- The current review, edit, merge, publication, replacement-upload and delete workflows remain
  authorised and functional.
- Desktop and mobile visual baselines are approved; Dusk interaction coverage and all project
  quality gates pass:
  `vendor/bin/sail bin pint --dirty`,
  `vendor/bin/sail composer phpstan`,
  `vendor/bin/sail artisan test --parallel --compact`,
  and `vendor/bin/sail artisan dusk`.
