# Admin Livewire Responsibility Review (2026-03-17)

## Scope

- Reviewed every PHP file under `app/Livewire/Admin` plus the shared concern `app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php`.
- Focused on authorization, validation, transactions, queue dispatch, direct model mutation, query complexity, and duplication of domain logic.
- Static review only. I did not run Livewire tests, profile SQL, or exercise the browser flows.

## Overall Verdict

- Most admin CRUD components are thin enough to stay in Livewire. They authorize, validate, mutate one aggregate, and stop.
- The church-service import and review cluster is the real boundary problem. `ManageChurchService`, `ReviewInboundEmails`, `ServiceReviewDashboard`, and `ManagesSectionPublication` are acting like application services inside UI classes.
- `EditSermon` and `EditPreacher` still have worthwhile extraction seams, but they are not in the same urgency tier as the church-service cluster.
- The best exemplars in this tree are still `UploadChurchService` and `ProcessingReview`, both of which keep the UI shell in Livewire and delegate the real work to a dedicated action/service.
- There is already meaningful Livewire test coverage for most of the high-complexity components, so extraction is more of a test reshaping task than a greenfield testing task.

## Immediate Authorization Fixes (Do Before Refactors)

- `ListCalendarEvents` and `EditCalendarEvent` should get component-level admin authorization immediately. They are currently mounted under the admin route group in `routes/web.php:122-179`, but the components themselves do not use `WithAdminAuthorization` or call `authorizeAdmin()`: `app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php:14-85`, `app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php:13-87`. Their current suite covers admin happy paths only and does not assert non-admin rejection: `tests/Feature/Livewire/AdminCalendarEventTest.php:31-293`. `Risk: High | Effort: Small`.
- `MediaUploadField` should get explicit authorization before any extraction work. It mutates media in `app/Livewire/Admin/Components/MediaUploadField.php:39-85` without any internal guard, and there are no dedicated tests for it. It is currently only mounted from `resources/views/livewire/admin/pages/page-form.blade.php:52`, so the present exposure is limited by the parent admin page, but it is still a write-capable nested Livewire component. `Risk: High | Effort: Small`.
- `UploadChurchService::save()` and `SubmitEmailText::submit()` should re-authorize mutating requests. Both components authorize in `mount()`, but not inside the write action itself: `app/Livewire/Admin/ChurchServices/UploadChurchService.php:25-29`, `app/Livewire/Admin/ChurchServices/UploadChurchService.php:56-80`, `app/Livewire/Admin/ChurchServices/SubmitEmailText.php:27-31`, `app/Livewire/Admin/ChurchServices/SubmitEmailText.php:57-76`. The routes are admin-protected today, but Livewire actions should still re-check authorization. `Risk: Medium | Effort: Extra small`.

## Priority Matrix

| Surface | Recommendation | Risk | Effort | Main consumers |
| --- | --- | --- | --- | --- |
| `ManagesSectionPublication` | Delegate now | High | Small | `ListSectionPublications`, `ServiceReviewDashboard` |
| `ManageChurchService` | Delegate now | High | Large | `admin.services.create`, `admin.services.edit`, inbound-email manual review handoff, service create/edit/show links |
| `ReviewInboundEmails` | Delegate now | High | Medium-Large | `admin.services.inbound-emails`, admin dashboard/member-home links, redirects into service create/show flows |
| `ServiceReviewDashboard` | Delegate now | High | Extra Large | `admin.services.review`, review/publishing links from service pages and timeline partials |
| `ShowChurchService` | Targeted extraction | Medium | Medium-Large | `admin.services.show`, redirects from service save/import approval, service and song detail links |
| `ListCalendarEvents` | Targeted extraction | Medium | Small | `admin.calendar-events.index` |
| `EditSermon` | Targeted extraction | Medium | Medium | `admin.sermons.edit`, sermon admin redirects and list links |
| `EditPreacher` | Targeted extraction | Medium | Medium | `admin.preachers.edit`, preacher list edit links |
| `MediaUploadField` | Keep component, add guard | High | Small | Nested only in the admin page form today |

## Delegate Now

- `ManagesSectionPublication` should move out of Livewire first. `app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php:15-146` performs state transitions, storage checks, audit metadata writes, and `PublishApprovedServiceSection` dispatch from a UI trait. It is reused by both `ListSectionPublications` and `ServiceReviewDashboard`, so extracting it shrinks two components at once. `Risk: High | Effort: Small`.
- `ManageChurchService` is the clearest fat component. `app/Livewire/Admin/ChurchServices/ManageChurchService.php:193-255` opens a transaction, writes import metadata, syncs items, links songs, finalizes canonical state, and marks inbound email review complete. `app/Livewire/Admin/ChurchServices/ManageChurchService.php:401-532` also parses stored inbound-email metadata and infers section types inside the component. This wants at least a `SaveChurchServiceFromAdmin` use-case plus a `PrefillChurchServiceFromInboundEmail` read/use-case. `Risk: High | Effort: Large`.
- `ReviewInboundEmails` should delegate its write workflows and preview building. `app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php:54-174` owns approve, reparse, edit-and-approve, and reject flows, while `app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php:271-354` builds preview DTOs, sanitizes HTML, and merges metadata. The clean split is an action set such as `ApproveInboundEmailImport`, `ReparseInboundEmail`, `RejectInboundEmail`, plus a preview factory/read model. `Risk: High | Effort: Medium-Large`.
- `ServiceReviewDashboard` is the largest and most expensive extraction. `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:53-274` validates, mutates sections, writes manual-review metadata, toggles publication state, marks services reviewed, and batch-approves publications. `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:344-808` also assembles the dashboard read model, grouping logic, summary logic, and batch-approval readiness logic. This should become dedicated write actions plus a dashboard query/read model. `Risk: High | Effort: Extra Large`.

## Targeted Extraction, Component Can Otherwise Stay

- `ShowChurchService` should keep the page shell but extract two seams. `reclassify()` in `app/Livewire/Admin/ChurchServices/ShowChurchService.php:65-99` should become an action/use-case, and the timeline/report assembly in `app/Livewire/Admin/ChurchServices/ShowChurchService.php:104-352` should move into a read model or presenter.
- `ListCalendarEvents` should keep the list UI but stop owning manual categorization. `app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php:42-48` updates the local `CalendarEvent` directly, while `app/Services/CalendarService.php:77-110` already contains the richer use-case that also updates Google extended properties. This is a concrete case of the component bypassing existing domain behavior.
- `EditSermon` does not meaningfully duplicate `PreacherResolutionService`, and the earlier wording overstated that. Its current logic in `app/Livewire/Admin/Sermons/EditSermon.php:120-167` intentionally uses a simpler preacher lookup path than `app/Services/PreacherResolutionService.php:18-44`. The extraction case is still good, but for different reasons: `save()` mixes preacher assignment rules, stale scripture invalidation, and conditional `QueueScriptureEnrichment` dispatch. A named `UpdateSermonDetails` action would improve reuse and testability without changing the current resolution semantics by accident.
- `EditPreacher` should extract the speaker-profile commands, not because of minor alias-normalization overlap, but because `app/Livewire/Admin/Preachers/EditPreacher.php:112-158` performs real domain work: approved-embedding aggregation, profile recomputation through `SpeakerIdentificationInterface`, and profile deactivation. The simple alias add/remove operations can stay component-owned or move later; the recomputation/deactivation paths are the main extraction seam.
- `ListSectionPublications` can stay as the listing shell, but its state changes should disappear once `ManagesSectionPublication` becomes a real action/service boundary.
- `ListSongs` and `ShowSong` can stay as read-side Livewire pages, but `app/Livewire/Admin/ChurchServices/ListSongs.php:80-132` and `app/Livewire/Admin/ChurchServices/ShowSong.php:36-79` duplicate song-usage analytics queries. That is acceptable for now, but it is a natural future `SongUsageReadModel`.
- `MediaUploadField` does not need an action layer yet, but it does need explicit authorization and tests before wider reuse.

## Consumer Graph

- `ManagesSectionPublication` is consumed by `app/Livewire/Admin/ChurchServices/ListSectionPublications.php:17-88` and `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:29-851`.
- `ManageChurchService` is mounted at `routes/web.php:154` and `routes/web.php:164`. It is linked from `resources/views/livewire/admin/church-services/upload-church-service.blade.php:8`, `resources/views/livewire/admin/church-services/list-church-services.blade.php:20`, `resources/views/livewire/admin/church-services/list-church-services.blade.php:103`, `resources/views/livewire/admin/church-services/service-review-dashboard.blade.php:107`, and `resources/views/livewire/admin/church-services/show-church-service.blade.php:13`. It is also the target of `ReviewInboundEmails::editAndApprove()` in `app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php:102-117`.
- `ReviewInboundEmails` is mounted at `routes/web.php:156`. It is linked from `resources/views/members/home.blade.php:53`, `resources/views/livewire/admin/church-services/list-church-services.blade.php:14`, `resources/views/livewire/admin/church-services/submit-email-text.blade.php:5`, `resources/views/livewire/admin/church-services/submit-email-text.blade.php:26`, and `resources/views/livewire/admin/church-services/review-inbound-emails.blade.php:15`.
- `ServiceReviewDashboard` is mounted at `routes/web.php:158`. It is linked from `resources/views/livewire/admin/church-services/show-church-service.blade.php:10`, `resources/views/livewire/admin/church-services/list-section-publications.blade.php:9`, `resources/views/livewire/admin/church-services/list-church-services.blade.php:8`, and `resources/views/livewire/admin/church-services/partials/unified-timeline.blade.php:306`.
- `ShowChurchService` is mounted at `routes/web.php:165`. It is the redirect target for `ManageChurchService::save()` in `app/Livewire/Admin/ChurchServices/ManageChurchService.php:251-254` and `ReviewInboundEmails::approve()` in `app/Livewire/Admin/ChurchServices/ReviewInboundEmails.php:90-93`. It is also linked from `resources/views/livewire/admin/church-services/upload-church-service.blade.php:77`, `resources/views/livewire/admin/church-services/list-church-services.blade.php:110`, and `resources/views/livewire/admin/church-services/show-song.blade.php:64`.
- `EditSermon` is mounted at `routes/web.php:150` and linked from `resources/views/livewire/admin/sermons/list-sermons.blade.php:117`.
- `EditPreacher` is mounted at `routes/web.php:170` and linked from `resources/views/livewire/admin/preachers/list-preachers.blade.php:69`.
- `ListCalendarEvents` and `EditCalendarEvent` are mounted at `routes/web.php:173-174` and linked from `resources/views/members/home.blade.php:96`, `resources/views/livewire/admin/calendar-events/list-calendar-events.blade.php:101`, and `resources/views/livewire/admin/calendar-events/edit-calendar-event.blade.php:5`.
- `MediaUploadField` is currently nested only inside `resources/views/livewire/admin/pages/page-form.blade.php:52`.

## Testing Impact and Migration Strategy

- The high-complexity church-service surfaces already have dedicated test suites:
  - `ManageChurchService`, `ShowChurchService`, and `UploadChurchService`: `tests/Feature/Livewire/AdminChurchServiceTest.php:138-976`
  - `ReviewInboundEmails` and the inbound-email-to-manual-review handoff: `tests/Feature/Livewire/AdminInboundEmailReviewTest.php:77-460`
  - `ServiceReviewDashboard`: `tests/Feature/Livewire/AdminServiceReviewDashboardTest.php:84-609`
  - `ListSectionPublications`: `tests/Feature/Livewire/AdminSectionPublicationQueueTest.php:64-220`
  - `SubmitEmailText`: `tests/Feature/ChurchServices/SubmitEmailTextTest.php:40-140`
- The targeted extraction candidates also have meaningful coverage:
  - `EditSermon`: `tests/Feature/Livewire/Admin/EditSermonTest.php:54-204`, `tests/Feature/Admin/SermonScriptureEnrichmentTest.php:39-90`, `tests/Feature/Livewire/AdminSermonTest.php:121-173`
  - `EditPreacher`: `tests/Feature/PreacherAdminTest.php:74-221`, `tests/Feature/Admin/AdminLivewireAuthorizationTest.php:98-142`, `tests/Feature/DataIntegrity/PreacherIntegrityTest.php:50`
- Calendar coverage is weaker where the immediate security gap exists. `tests/Feature/Livewire/AdminCalendarEventTest.php:31-293` exercises admin behavior, filtering, and saving, but it does not include non-admin `assertForbidden()` checks for either calendar component.
- `MediaUploadField` currently has no dedicated test coverage.

### Testing migration strategy

1. Keep a thin Livewire integration suite for mount, authorization, validation wiring, redirects, and notifications.
2. Move branch-heavy domain behavior into action/use-case tests as logic is extracted.
3. Do not delete component-level assertions until the equivalent action-level coverage exists.
4. Add explicit non-admin authorization regression tests for the calendar components and the media upload field as part of the immediate fix pass.

## Can Stay As-Is

- `CreatePage`, `EditPage`, and `PageForm` are still thin form components/traits: `app/Livewire/Admin/Pages/CreatePage.php:24-35`, `app/Livewire/Admin/Pages/EditPage.php:32-43`, `app/Livewire/Admin/Pages/PageForm.php:28-69`.
- `CreateMeeting`, `EditMeeting`, `MeetingForm`, and `ListMeetings` remain cohesive CRUD/read-side code: `app/Livewire/Admin/Meetings/CreateMeeting.php:25-48`, `app/Livewire/Admin/Meetings/EditMeeting.php:41-64`, `app/Livewire/Admin/Meetings/MeetingForm.php:43-90`, `app/Livewire/Admin/Meetings/ListMeetings.php:76-126`.
- `CreateUser`, `EditUser`, and `ListUsers` are still reasonable in-component admin mutations while the role model remains a simple boolean: `app/Livewire/Admin/Users/CreateUser.php:56-77`, `app/Livewire/Admin/Users/EditUser.php:67-95`, `app/Livewire/Admin/Users/ListUsers.php:45-74`.
- `CreatePreacher` and `ListPreachers` are straightforward CRUD/list components: `app/Livewire/Admin/Preachers/CreatePreacher.php:49-62`, `app/Livewire/Admin/Preachers/ListPreachers.php:61-82`.
- `ListSermons` is a read-side list with moderate query logic but no write-side orchestration: `app/Livewire/Admin/Sermons/ListSermons.php:102-157`.
- `EditCalendarEvent` can stay once the admin-authorization gap is fixed. Its save path is still simple single-model CRUD: `app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php:61-76`.
- `UploadChurchService` can stay once the mutating action re-authorizes. It already follows the preferred pattern of validate, delegate, and translate errors: `app/Livewire/Admin/ChurchServices/UploadChurchService.php:56-79`.
- `SubmitEmailText` can stay once `submit()` re-authorizes. It creates one record and dispatches one job: `app/Livewire/Admin/ChurchServices/SubmitEmailText.php:57-76`.
- `ProcessingReview` and `ProcessingReviewList` are already thin and properly delegated/read-side: `app/Livewire/Admin/ChurchServices/ProcessingReview.php:35-63`, `app/Livewire/Admin/ChurchServices/ProcessingReviewList.php:22-34`.
- `ListChurchServices` is a normal list component: `app/Livewire/Admin/ChurchServices/ListChurchServices.php:67-110`.

## Decay Watchlist (Revisit By 2026-06-30)

- `ListPages::deleteSelected()` in `app/Livewire/Admin/Pages/ListPages.php:73-79` bulk-deletes via query builder. Revisit if page deletion picks up model hooks, media cleanup, or audit behavior.
- `ListSongs` and `ShowSong` duplicate song-usage analytics queries. Revisit if song reporting changes or query performance becomes visible.
- `ListUsers` is fine while roles remain a boolean `is_admin`. Revisit if the project moves to richer permissions.
- `MediaUploadField` should be revisited if it is reused outside admin page editing or exposed to any non-admin context.

## Suggested Extraction Order

1. Land the immediate authorization fixes and their missing regression tests.
2. Replace `ManagesSectionPublication` with explicit publication actions.
3. Extract `SaveChurchServiceFromAdmin` and `PrefillChurchServiceFromInboundEmail` from `ManageChurchService`.
4. Extract the `ReviewInboundEmails` action set and preview read model.
5. Split `ServiceReviewDashboard` into a dashboard query plus focused write actions.
6. Extract the `ShowChurchService` reclassification action and timeline/read-model builder.
7. Move `ListCalendarEvents::categorize()` onto `CalendarService::manuallyCategorizeEvent()`.
8. Revisit `EditSermon` and `EditPreacher` after the church-service cluster is under control.
