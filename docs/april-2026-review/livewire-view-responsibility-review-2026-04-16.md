# Livewire Responsibilities and Blade/View Composition Review

Date: 2026-04-16

Scope: static, read-only review of current Livewire responsibilities and Blade/view composition, with admin Livewire reviewed first and shared/public surfaces reviewed second. I treated the March review files as historical background and only repeated issues that still appear to exist in the current codebase.

## Findings

### 1. [High] `ListCalendarEvents` still bypasses the calendar categorization use-case

`app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php:54-75` still updates `meeting_slug` and `is_categorized_automatically` directly inside the Livewire component. That duplicates domain behavior that already exists in `app/Services/CalendarService.php:83-103`, where `manuallyCategorizeEvent()` also pushes the categorization back to Google when the event is synced. The inline select in `resources/views/livewire/admin/calendar-events/list-calendar-events.blade.php:77-87` is therefore still wired to the thinner, bypass path.

This is more than a style issue. It means the list-screen categorization flow can diverge from the canonical behavior and leave remote calendar metadata stale. It is also still a component-responsibility leak: the Livewire list owns persistence logic that the service layer already models.

### 2. [High] `ShowChurchService` is still both a workflow controller and a processing read-model assembler

The March extraction work helped, but `app/Livewire/Admin/ChurchServices/ShowChurchService.php:48-67` and `app/Livewire/Admin/ChurchServices/ShowChurchService.php:216-518` still leave this page owning a large amount of orchestration and read shaping. The component resolves related processing runs across multiple identity paths, builds service timelines, builds processing timelines, formats durations and messages, derives fallback processing IDs from import metadata, and also hosts write-side actions like reclassify, merge resolution, and upload deletion in `app/Livewire/Admin/ChurchServices/ShowChurchService.php:74-211`.

The paired Blade layer is still correspondingly heavy. `resources/views/livewire/admin/church-services/show-church-service.blade.php:25-43` hands most of the page to `resources/views/livewire/admin/church-services/partials/unified-timeline.blade.php:1-343`, which is effectively its own mini presentation system with collapsed sections, row-type branching, multiple inline `@php` blocks, and several action surfaces.

Net effect: this page remains the clearest admin Livewire surface where the UI boundary is still too wide, even after the March cleanups.

### 3. [Medium] The church-service Blade layer is still a parallel admin composition system

Most non-church-service admin Livewire screens now converge on the shared composition layer: `resources/views/components/admin/page.blade.php:1-18`, `resources/views/components/admin/list-shell.blade.php:1-20`, `resources/views/components/admin/form-shell.blade.php:1-31`, `resources/views/components/admin/filter-bar.blade.php:1-20`, and `resources/views/components/admin/empty-state.blade.php:1-39`. The church-service cluster mostly does not.

The remaining bespoke shells are concentrated in the area with the most screens and the most workflow complexity:

- `resources/views/livewire/admin/church-services/manage-church-service.blade.php:1-24`
- `resources/views/livewire/admin/church-services/review-inbound-emails.blade.php:1-36`
- `resources/views/livewire/admin/church-services/list-section-publications.blade.php:1-36`
- `resources/views/livewire/admin/church-services/upload-church-service.blade.php:1-21`
- `resources/views/livewire/admin/church-services/submit-email-text.blade.php:1-13`
- `resources/views/livewire/admin/church-services/processing-review-list.blade.php:1-11`
- `resources/views/livewire/admin/church-services/processing-review.blade.php:1-11`
- `resources/views/livewire/admin/church-services/service-review-dashboard.blade.php:1-41`
- `resources/views/livewire/admin/church-services/show-church-service.blade.php:1-15`

That divergence now shows up less as “missing primitives” and more as “workflow pages never rejoined the system after the primitives existed”. The result is repeated title/action rows, repeated filter wrappers, ad-hoc empty states, and more inline presentation logic than comparable admin screens. `resources/views/livewire/admin/church-services/review-inbound-emails.blade.php:49-182`, `resources/views/livewire/admin/church-services/service-review-dashboard.blade.php:130-145`, and `resources/views/livewire/admin/church-services/partials/unified-timeline.blade.php:139-315` are the clearest examples.

### 4. [Medium] `EditSermon` still owns too many unrelated write concerns for one Livewire page

`app/Livewire/Admin/Sermons/EditSermon.php:172-225` still handles a broad save use-case inline: validation, preacher resolution, preacher-source rules, scripture-passage lookup, scripture linkage invalidation/reuse, and enrichment dispatch. On top of that, `app/Livewire/Admin/Sermons/EditSermon.php:228-324` also owns thumbnail selection, thumbnail regeneration, video visibility override, and manual video-quality assessment dispatch.

The component is well-tested and now benefits from the newer form-shell composition in `resources/views/livewire/admin/sermons/edit-sermon.blade.php:29-369`, so this is not a “broken today” finding. It is still a Livewire responsibility boundary issue, though: one page component is acting as the write surface for sermon metadata, preacher identity cleanup, scripture identity sync, thumbnail management, and video-processing controls at the same time.

### 5. [Medium] The shared `x-toggle` component still blurs ownership between Alpine and Livewire

The primitive is much better than it was in March because it now supports plain Blade usage, but the Livewire branch in `resources/views/components/toggle.blade.php:12-38` still makes Alpine and Livewire co-own the same boolean via `$wire.entangle(...)`. That matters because several callers now explicitly ask for real-time semantics with `wire:model.live`, for example:

- `resources/views/livewire/admin/meetings/meeting-form.blade.php:52`
- `resources/views/livewire/admin/calendar-events/list-calendar-events.blade.php:20-21`
- `resources/views/livewire/admin/sermons/list-sermons.blade.php:28-30`
- `resources/views/livewire/admin/users/user-form.blade.php:21`

The component reads only the model name and then always entangles the bare property, so the shared primitive, not the caller, decides how immediate the Livewire sync really is. Even where the runtime behavior is acceptable today, the ownership boundary is still blurry: Alpine owns the switch’s immediate truth, Livewire owns the server truth, and the call site cannot express that difference cleanly.

## Open Questions

- Should the church-service admin area now be treated as the next candidate for `x-admin.page` / `x-admin.list-shell` / `x-admin.form-shell` convergence, or is it intentionally allowed to stay a workflow-specific exception?
- For sermon editing, is the intended long-term boundary one “update sermon details” action plus separate media-management actions, or is it acceptable for this page to remain the single command surface for all sermon-side admin tasks?
- For switches, does the team still want Alpine to own the animated state for Livewire-bound toggles, or would a Livewire-first switch primitive be preferable now that `.live` filters are used more widely?
- The media upload flow is much more encapsulated than it was in March, but it still keeps a dedicated Alpine controller in `resources/js/livewire/media-upload-controller.js:12-179`. Is that now considered an accepted boundary, or should it eventually collapse further into a more clearly Livewire-owned flow?

## What Improved Since March

- `ManageChurchService` is materially thinner. The main transaction/orchestration path now lives in `app/Actions/SaveChurchServiceFromAdmin.php:17-91`, and inbound-email prefilling now lives in `app/Actions/PrefillChurchServiceFromInboundEmail.php:16-140`.
- `ReviewInboundEmails` also improved substantially. The component now delegates approve/reparse/reject behavior to dedicated actions and delegates preview shaping to `app/Actions/InboundEmail/InboundEmailPreviewFactory.php:11-112`.
- `ServiceReviewDashboard` is no longer the giant all-in-one component from March. Its query/read-model work now lives in `app/Queries/ServiceReviewDashboardQuery.php`, and its write operations are action-driven.
- The admin composition layer that March recommended now exists and is used widely: `x-admin.page`, `x-admin.list-shell`, `x-admin.form-shell`, `x-admin.filter-bar`, and `x-admin.empty-state`.
- `PageFormData` and `MeetingFormData` exist, so the earlier trait-based form concern has been addressed for those surfaces.
- The primitive layer is healthier than it was in March. `resources/views/components/card.blade.php:1-18` is neutral by default with optional `prose`, `resources/views/components/button.blade.php:1-57` has explicit navigation control, and `resources/views/components/toggle.blade.php:12-52` now supports non-Livewire Blade usage.
- `ProcessingLogsViewer` no longer resolves an HTTP controller as a service boundary. It now depends on `app/Services/GetMediaProcessingStatus.php` through `app/Livewire/ProcessingLogsViewer.php:21-64`, which is a real improvement even though the surrounding upload ecosystem is still a custom workflow.
