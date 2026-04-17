# March 23-30 2026 Change Review

Date: 2026-04-16

## Scope

- Reviewed the effective repository delta between `76dada590d8571062813a56a219410e1dd015b40` (last commit before March 23, 2026) and `c2e1aff9249f5d09f4de0a7b7f2447a9e4dc345e` (last commit on March 30, 2026).
- Focused on maintainability, correctness boundaries, and new tech debt introduced or materially deepened by that week of changes.
- Treated later April changes as out of scope except where they helped confirm intent.

## Verdict

Yes. The March 23 to March 30 change set delivered a lot of useful work, but it also introduced several partial migrations and split-source contracts. The recurring pattern was not low-quality implementation. It was landing meaningful improvements without fully completing the surrounding boundary cleanup, which leaves the next iteration harder to reason about than it should be.

## What Improved

- Admin UX and component reuse moved forward substantially with the new `x-admin.*` shell primitives and broader Livewire 3 adoption.
- Security and exposure boundaries were tightened in several important places, especially around admin verification and private section-publication preview media.
- The church-service and media-processing areas gained more explicit collaborators and significantly better automated coverage.
- Route naming, accessibility primitives, and several public/admin flows are clearer than they were before this week started.

## Findings

### 1. [High] The upload and log-viewer refactor left interaction ownership half-finished

Representative commits:

- `54007d924` Resolve upload and log-viewer frontend state ownership
- `644ee0cf9` Extract media-processing status query service from ProcessingLogsViewer

Why this is tech debt:

- The drag-and-drop uploader now advertises "Drop your file here or click to browse", but the drop handler only clears the visual state and never assigns or uploads the dropped file.
- Upload coordination uses page-global custom events such as `media-upload:cancel-upload`, `media-upload:cancel-processing`, and `media-upload:retry-upload`, so the design quietly assumes there will only ever be one uploader on a page.
- `ProcessingLogsViewer` split ownership of auto-refresh between Livewire and Alpine: the checkbox both updates `autoRefresh` through `wire:model.live` and flips the same state again through `toggleAutoRefresh()`.
- The Alpine helper starts polling but has no teardown path, so the interval contract is weaker than it should be in a `wire:navigate` application.

Why it matters:

- This is the kind of debt that shows up as "small flaky UI behavior" rather than a single obvious outage.
- It raises the cost of adding another uploader, another processing panel, or any reuse of the same components.

Mitigation:

- Backlog item 10 already captures the right fix direction: make the drop zone real, scope events per uploader instance, choose one owner for log-viewer refresh state, and add explicit teardown.

### 2. [High] Admin hardening landed as two enforcement systems instead of one

Representative commits:

- `ccbbb64f1` Normalize admin verification requirement across all admin mutation paths
- `d685eb1e5` Remove WithAdminAuthorization duplication from routed admin components
- `ca540fa1b` Implement defense-in-depth authorization for admin components
- `b0b2331d9` Enforce defense-in-depth authorization in admin components

Why this is tech debt:

- Route entry now correctly uses `auth`, `verified`, and `admin`, but Livewire screens still defend themselves separately with `WithAdminAuthorization`.
- That trait checks `canAccessAdmin()`, while the route stack expresses a slightly different contract through middleware composition.
- No Livewire persistent middleware registration was added during this week, so first-page authorization and subsequent Livewire requests are still not enforced through one obvious, central mechanism.

Why it matters:

- This is maintainability debt in a security-sensitive area.
- Future changes can drift because a maintainer has to remember both the route contract and the component recheck contract.

Mitigation:

- Backlog items 2 and 7 should be treated as a pair here: define the intended member/admin boundaries once, then finish the HTTP and Livewire request-boundary cleanup so routed admin screens rely on one durable enforcement path.

### 3. [High] New church-service merge and livestream-projection workflows were stored as active JSON contracts

Representative commits:

- `9b3442217` Livestream Projection Pipeline
- `f31165977` Merge / Review Engine For Later Sources
- `ca113cd39` Merge Policy — Identity-First Matching
- `373561d1c` Harvest song title hints from transcript sections

Why this is tech debt:

- The week introduced real workflow state in `church_services.import_metadata.pending_structure_merge` rather than a first-class workflow model or explicit columns.
- It also introduced durable provenance in nested metadata such as `church_service_items.metadata.livestream_projection`, where runtime logic depends on JSON keys like `processing_id`, `service_section_id`, `source_segment_ids`, and `confidence_level`.
- The implementation is thoughtfully typed with JSON wrapper classes, but the application still coordinates array shapes across services, actions, and models instead of promoting the important parts into explicit relational or column-level contracts.

Why it matters:

- This is not passive metadata. It is active workflow and provenance state.
- The longer it remains JSON-led, the harder it becomes to query, backfill, constrain, and evolve safely.

Mitigation:

- Backlog items 4 and 5 are the right mitigation path: treat `media_processing_logs` and church-service workflow/provenance as authoritative application boundaries, then promote the remaining high-value JSON shapes out of hidden blobs.

### 4. [Medium] The admin redesign shipped as a partial migration with two live shell models

Representative commits:

- `8ed731a82` admin shell component family
- `d25ae2f54` Migrate legacy admin Blade screens onto the modern admin architecture
- `dbad0b9f0` Redesign the service admin view
- `dadfb0b28` Wider admin layouts

Why this is tech debt:

- The new `x-admin.page`, `x-admin.list-shell`, and `x-admin.form-shell` primitives are a good direction.
- But the week stopped with two active composition models:
  - newer Livewire/admin screens using the shared shell components directly
  - older controller-rendered admin pages still extending `layouts/admin`
- The church-service area also remained a bespoke exception rather than converging on the new composition layer.

Why it matters:

- This is a classic partial-migration cost: future work now has two credible patterns to copy.
- It increases layout drift, onboarding cost, and the likelihood that fixes need to be made twice.

Mitigation:

- Backlog items 8, 9, and 12A already describe the right follow-through: split overloaded church-service screens, bring the admin area onto one composition layer, and standardize the Blade shell contract.

### 5. [Medium] The test expansion improved confidence but deepened suite taxonomy drift

Representative change themes:

- Large numbers of new service, Livewire, and data-contract tests landed across `tests/Feature` and `tests/Unit`.
- Focused collaborator tests were added at the same time as broad high-level suites remained in place.

Why this is tech debt:

- A lot of the new coverage is valuable, but the week added more database-backed and framework-heavy tests under `tests/Unit`, which makes the top-level suite names less trustworthy as a guide to cost and scope.
- Broad Livewire suites still overlap with newer extracted action/query/service tests, especially around church-service and service-review flows.

Why it matters:

- This is maintenance and execution-cost debt rather than correctness debt.
- It makes failures harder to triage and makes the suite more expensive to keep healthy over time.

Mitigation:

- Backlog item 13 should stay in scope: tighten the suite taxonomy, move the heaviest bespoke setups onto shared scenario builders, and deliberately slim overlapping journey tests where focused seams now exist.

## Overall Assessment

The March 23 to March 30 work was productive and directionally good. The main debt introduced was partial-architecture-completion debt:

- better boundaries, but not fully singular ones
- better shared components, but not full convergence
- better typed metadata, but still too much hidden workflow state
- better test coverage, but with more suite overlap and taxonomy drift

That means the week was worthwhile, but it created follow-through obligations. If we do not take that follow-through seriously, the codebase will keep paying for both the old and new patterns at the same time.

## Backlog Mapping

- Finding 1 maps to backlog item 10.
- Finding 2 maps to backlog items 2 and 7.
- Finding 3 maps to backlog items 4 and 5.
- Finding 4 maps to backlog items 8, 9, and 12A.
- Finding 5 maps to backlog item 13.
