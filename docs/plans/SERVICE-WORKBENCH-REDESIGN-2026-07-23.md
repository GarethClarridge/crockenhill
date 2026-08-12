# Service workbench redesign — closeout plan

> **Status (2026-08-12): feature delivered; three closeout slices remain.** The information
> architecture and backend status contract shipped in `98dd4cab5` and `473ba42c9`. Do not reopen
> that implementation as a redesign project. Finish the orphan cleanup and behavioural coverage
> now; defer only the visual fixture until the design-system refresh has stabilised the shared
> admin shell.

## Delivered contract

The service workbench now provides:

- one page heading and a compact service identity;
- one truthful rollup status, explanation, and next action;
- one chronological `<ol>` service record combining plan and recording evidence;
- neutral plan-only/recording-only provenance rather than treating every unmatched row as an error;
- visible transcript evidence and contextual review/publication actions;
- a collapsed **Technical processing details** disclosure for run IDs, steps, diagnostics, and
  destructive upload removal;
- source-coverage guidance for OpenLP and email plans;
- responsive row stacking, stable `wire:key` values, and authorised Livewire actions.

The old alignment-table partial family was removed. The plan does not own review predicates,
publication eligibility, extraction policy, matching, or run-supersession rules.

New feature assertions belong in `tests/Feature/Livewire/Admin/ChurchServices/`. Do not add to the
legacy flat `tests/Feature/Livewire/AdminChurchServiceTest.php`; the simplification closeout plan
owns folding that suite into the namespaced tests.

## Remaining delivery 1 — delete the orphaned header partial

**Independent and ready now.**

Delete:

`resources/views/livewire/admin/church-services/partials/processing-run-header.blade.php`

It has no Blade include, PHP reference, or test reference; its delete-upload control was replaced by
the authorised action under Technical processing details. Before deletion, repeat the repository
reference search and verify the existing workbench rendering tests remain green.

This is a cleanup-only change. Do not move or restyle the replacement action.

## Remaining delivery 2 — prove browser behaviour

**Independent and ready now; do not wait for the design refresh.**

Add focused Dusk coverage for behaviour that PHP rendering tests cannot prove:

- toggle Edit plan, make a deterministic edit, save, and return to the unified read view;
- open an actionable review row and operate its supported action by keyboard;
- open Technical processing details by keyboard;
- exercise the authorised delete confirmation without deleting unrelated fixture data;
- cover merge or publication only where the fixture can make that action deterministic and
  independently reversible.

Use a purpose-built deterministic database fixture. Do not depend on imported production-shaped
archive data, random UUID copy, current dates, or asynchronous external services. Keep browser
assertions behavioural; appearance belongs to Playwright.

If the full interaction sequence is too broad for one robust test, ship small tests in this value
order: edit/save, actionable row, technical details, destructive confirmation, then optional merge
or publication. Each merged test is useful on its own.

## Remaining delivery 3 — lock the visual result

**Depends on design-system refresh Phases 2–5.** The dependency avoids approving a baseline that is
immediately invalidated by the planned admin-shell, component, typography, and screenshot-runner
changes. It does not block Deliveries 1 or 2.

After those phases land:

1. add a deterministic Playwright workbench fixture;
2. capture desktop and mobile states with a stable date, transcript excerpts, clean rows, one real
   attention row, and a failed-run summary;
3. keep UUIDs and relative timestamps out of the screenshot region;
4. review row density, transcript readability, focus styling, warning-colour restraint, and narrow
   viewport wrapping;
5. assert only the minimal interaction needed to expose a screenshot state—Dusk remains the owner
   of behaviour.

## Existing PHP/Livewire coverage to preserve

The namespaced suites and rollup-query tests must continue to prove:

- exactly one `<h1>` and no duplicate Back action;
- `ProcessingFailed` precedence without an old failure overriding a better surviving run;
- plan-only, recording-only, processing, failed, completed, and multiple-run rendering;
- OpenLP/email source guidance;
- unmatched recording rows are neutral unless a real review predicate applies;
- transcript excerpts are visible without a disclosure;
- static rows are not buttons and actionable rows retain correct ARIA;
- technical detail is collapsed but available;
- edit, review, merge, publication, replacement-upload, and delete actions remain admin-authorised.

Primary homes:

- `tests/Feature/Livewire/Admin/ChurchServices/ShowChurchServiceTest.php`
- `tests/Feature/Livewire/Admin/ChurchServices/ServiceFlowRowRenderingTest.php`
- `tests/Feature/Queries/ChurchServiceRollupQueryTest.php`

## Completion criteria

This plan closes when:

1. the orphan partial is deleted;
2. the highest-value workbench interactions have deterministic Dusk coverage;
3. the post-refresh desktop/mobile Playwright baselines are approved; and
4. the relevant focused tests, PHPStan, Pint, full parallel suite, and Dusk suite pass.

If the design refresh is cancelled rather than delayed, Delivery 3 becomes immediately executable
against the current design. Do not keep this plan open indefinitely for an abandoned dependency.

**Who benefits:** operators and volunteers reviewing Sunday services.

**What observably improves:** the delivered workbench stops carrying dead markup, its critical
keyboard workflows are regression-tested, and its stable post-refresh layout has visual baselines.
