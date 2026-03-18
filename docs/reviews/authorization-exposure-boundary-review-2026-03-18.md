# Authorization And Exposure Boundary Review

Date: 2026-03-18

## Scope

This review traced authorization and exposure boundaries across:

- web and API routes
- controllers
- Livewire admin components
- policies and gates
- model-level visibility helpers
- asset and file delivery
- admin/public transitions and derivative read paths such as sitemap and API responses

## Runtime Notes

Current resolved config in this environment:

- `sermons.childrens_talks.public = false`
- `media-processing.storage.sermon_disk = public`
- `media-processing.storage.transcript_disk = public`
- `thumbnail-generation.storage.disk = public`
- `service-tracking.enabled = true`

Read-only database checks in this environment did not show live `pages`, `sermons`, or `service_sections` rows, and there were no linked meetings or upcoming uncategorized confirmed events. That means several findings are code-path confirmed but could not be demonstrated against current content records here.

## Executive Summary

The highest-risk issues are on the file-delivery side, not the route side.

1. Unpublished section-publication assets are written to the public sermon disk and surfaced as direct URLs before publication.
2. "Private" Children's Corner content is guarded at the route level, but most actual media delivery bypasses the guarded controller and uses direct public storage URLs.
3. Several auth-only surfaces are effectively available to any internet user because self-registration is open and email verification is not required for those areas.

The broad pattern is that route/controller checks are often stricter than the derivative read paths that sit beside them: sitemap generation, direct storage URLs, page-link caches, meeting wrappers, and API resources.

## Effective Principals

| Principal | How they get access | Effective read access | Effective write/admin access |
| --- | --- | --- | --- |
| Anonymous visitor | No auth | Public site pages, public sermons, public sermon API, public meetings, `/calendar`, `/calendar/uncategorized`, `/meetings/{meeting}/events`, sitemap, login/register | None |
| Authenticated user | Public self-registration is enabled and logs the user in immediately | Everything anonymous users can see, plus `/church/members`, `/members/*` catch-all pages, `/church/songs/*`, and Children's Corner when `sermons.childrens_talks.public` is false | None |
| Verified non-admin | Same as authenticated user | Same as authenticated user | None |
| Unverified admin | Admin account without verified email | Same as authenticated user, plus direct access to any `Page` gated only by `admin=yes`, plus legacy/admin paths that rely only on `is_admin` | Can use `meetings` resource mutation routes and legacy sermon delete routes that do not require `verified` |
| Verified admin | Admin account with verified email | All of the above, plus full `/admin/*` and admin-only APIs via session auth | Full admin UI and privileged APIs |
| Admin PAT holder | Admin + verified + Sanctum token with required ability | `api/services/*` with `service:upload` or `api/media/*` with `media:process` | Church service upload/read or media-processing control, depending on token ability |

## Access Map

### Anonymous

- Public page routes are open by default and only `PageController` enforces `admin=yes` and `PageArea::MEMBERS` checks: `routes/web.php:216-218`, `app/Http/Controllers/PageController.php:23-84`.
- Public sermon pages and lists are open: `routes/web.php:58-104`, `app/Http/Controllers/SermonController.php:24-196`.
- Public sermon API is open: `routes/api.php:16-25`, `app/Http/Controllers/Api/SermonApiController.php:21-107`.
- Public meeting routes are open: `routes/web.php:53-56`, `app/Http/Controllers/MeetingController.php:38-88`.
- Public calendar routes are open, including uncategorized events and meeting event listings: `routes/web.php:50-53`, `app/Http/Controllers/CalendarController.php:16-50`.
- Public registration is enabled: `routes/web.php:109-116`, `app/Livewire/Auth/Register.php:49-69`.

### Any authenticated user

- `/church/members` only requires `auth`: `routes/web.php:182-184`, `app/Http/Controllers/MemberController.php:14-37`.
- `/church/songs/*` only requires `auth`: `routes/web.php:186-189`, `app/Http/Controllers/PublicSongListController.php:14-61`.
- Members-area pages under the catch-all only require `Auth::check()`: `app/Http/Controllers/PageController.php:35-41`, `app/Http/Controllers/PageController.php:64-66`.
- Children's Corner requires only a logged-in user when `sermons.childrens_talks.public` is false: `app/Services/SermonExposurePolicy.php:13-21`, `app/Http/Middleware/EnsureChildrensCornerAccess.php:21-28`, `routes/web.php:37-38`.

### Admin and verified-admin boundaries

- `/admin/*` is protected by `auth + verified + admin`: `routes/web.php:121-180`.
- Media-processing APIs require admin, verified email, and token ability when bearer auth is used: `routes/api.php:50-99`, `app/Http/Middleware/EnsureMediaProcessingAccess.php:17-35`.
- Church-service APIs require admin, verified email, and token ability when bearer auth is used: `routes/api.php:27-40`, `app/Http/Middleware/EnsureServiceTrackingAccess.php:17-33`.
- Legacy `meetings` admin routes only require `auth + admin`, not `verified`: `routes/web.php:45-48`.
- Legacy sermon delete routes only require `auth + admin`, not `verified`: `routes/web.php:85-103`, `app/Http/Controllers/Admin/SermonAdminController.php:100-168`.

## Findings

### 1. Unpublished section-publication media is publicly addressable before publication

Evidence:

- Section-publication candidates are extracted and written onto `sermon_disk`: `app/Jobs/PrepareSectionPublicationCandidates.php:188-260`.
- The current `sermon_disk` default resolves to `public`: `config/media-processing.php:49-60`.
- Publication is a later step; the `Sermon` record is only created once a section reaches `APPROVED` and the publish job runs: `app/Jobs/PublishApprovedServiceSection.php:73-141`.
- The admin review dashboard converts those unpublished asset paths into direct `Storage::url(...)` links: `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:391-399`, `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:811-818`.

Impact:

- Pending-approval or merely extracted section media can leak before publication.
- Anyone who learns the URL from the admin page, browser cache, logs, or guessable section IDs can fetch the file directly.
- This bypasses the publication workflow, which otherwise tries to distinguish `PENDING_APPROVAL`, `APPROVED`, and `PUBLISHED`.

Boundary failure:

- The workflow state machine is private.
- The file storage backing that state machine is public.

### 2. Children's Corner asset protection is bypassed by direct public storage URLs

Evidence:

- Children's Corner is supposed to be non-public unless config says otherwise: `config/sermons.php:3-6`, `app/Services/SermonExposurePolicy.php:13-21`.
- Routes are wrapped in `childrens-corner.access`: `routes/web.php:37-38`.
- There is a guarded asset controller for audio and thumbnails: `app/Http/Controllers/SermonAssetController.php:26-137`.
- But the `Sermon` model exposes `audio_url`, `video_url`, and `thumbnail_url` as direct public storage URLs: `app/Models/Sermon.php:163-177`, `app/Models/Sermon.php:621-623`, `app/Services/SermonStorageService.php:63-100`.
- The Children's Corner page uses those direct URLs for meta tags and for the actual media player elements: `resources/views/childrens-corner/show.blade.php:4-15`, `resources/views/childrens-corner/show.blade.php:82-104`.

Impact:

- The controller-level guard only protects the dedicated asset routes.
- The page hands authenticated users the raw public asset URLs anyway.
- Once a user loads a private talk, the media can be replayed or shared without going back through the auth check.

Related note:

- The same direct-URL pattern exists on public sermon pages too: `resources/views/sermons/sermon.blade.php:14-25`, `resources/views/sermons/sermon.blade.php:121-133`.
- That is fine for public sermons, but it shows the guarded asset controller is not the real source of truth.

### 3. "Auth-only" content is effectively open to any self-registered user

Evidence:

- Public registration is enabled and immediately authenticates the new user: `routes/web.php:109-116`, `app/Livewire/Auth/Register.php:49-69`.
- Members dashboard and songs only require `auth`: `routes/web.php:182-189`.
- Members-area pages only require `Auth::check()`: `app/Http/Controllers/PageController.php:35-41`, `app/Http/Controllers/PageController.php:64-66`.
- Children's Corner access for non-public talks is satisfied by any logged-in user, not by admin, verified, or invited-member status: `app/Services/SermonExposurePolicy.php:18-21`, `app/Http/Middleware/EnsureChildrensCornerAccess.php:21-28`.

Impact:

- The effective boundary is "has a user account", not "trusted church member".
- Because verification is not required for these routes, even a newly registered and unverified user can reach them.
- This materially weakens the privacy model for member pages, song history/lyrics, and non-public Children's Corner talks.

### 4. Meeting pages can bypass the underlying Page restrictions

Evidence:

- `PageController` is the only place that enforces `admin=yes` and members-area checks for pages: `app/Http/Controllers/PageController.php:29-41`, `app/Http/Controllers/PageController.php:59-67`.
- `MeetingController@show` loads the related `page` and renders its heading, image, and body directly with no equivalent authorization checks: `app/Http/Controllers/MeetingController.php:40-88`.
- Meeting photos are also exposed via direct Media Library URLs: `app/Models/Meeting.php:389-425`.
- Meetings are always added to the sitemap with no page-level visibility filtering: `app/Services/SitemapService.php:80-88`, `app/Models/Meeting.php:373-384`.

Impact:

- If a meeting is linked to a page that is meant to be admin-only or members-only, `/community/{meeting}` can still expose that page content and related assets.
- Even if the HTML route were later fixed, meeting photos are already on direct public URLs once referenced.

Current-state note:

- The current database snapshot in this environment had no `meetings.page_id` links, so I could not confirm a live exploit here.
- The bypass is present in code.

### 5. Public calendar routes expose looser event data than the main calendar page

Evidence:

- `/calendar` only shows upcoming confirmed events: `app/Http/Controllers/CalendarController.php:16-32`.
- `/meetings/{meeting}/events` is public and uses `CalendarService::getEventsForMeeting()`, which returns every status except `cancelled`: `routes/web.php:53`, `app/Http/Controllers/CalendarController.php:35-40`, `app/Services/CalendarService.php:16-37`.
- `/calendar/uncategorized` is also public and uses `getUncategorizedEvents()`, which does not filter status at all: `routes/web.php:52`, `app/Http/Controllers/CalendarController.php:43-49`, `app/Services/CalendarService.php:64-75`.
- Those public views render title, description, speaker, location, and schedule fields: `resources/views/meetings/events.blade.php:1-58`, `resources/views/calendar/uncategorized.blade.php:1-20`, `resources/views/components/calendar-event-card.blade.php:1-205`.

Impact:

- Tentative or otherwise not-confirmed event metadata can leak publicly through meeting-specific event pages.
- Uncategorized events, which are usually the least curated entries, are publicly exposed even though the main calendar page is stricter.

### 6. Sitemap generation leaks auth-only members pages and does not apply page/meeting policy consistently

Evidence:

- `SitemapService` excludes only `admin=yes` pages, not members-area pages: `app/Services/SitemapService.php:69-79`.
- `PageController` explicitly treats `PageArea::MEMBERS` as auth-only: `app/Http/Controllers/PageController.php:35-41`, `app/Http/Controllers/PageController.php:64-66`.
- All meetings are also added without checking whether their related page would be protected: `app/Services/SitemapService.php:80-88`.

Impact:

- Crawlers and unauthenticated users can discover auth-protected members-page URLs through `sitemap.xml`.
- If a meeting wraps restricted page content, its public sitemap entry becomes a discovery aid for that bypass too.

### 7. Hidden sermon content leaks through API and metadata read paths

Evidence:

- Public sermon pages only show the summary when `show_summary` is true and only show outline points when `show_points` is true: `resources/views/sermons/sermon.blade.php:145-166`.
- But `meta_description` automatically incorporates the summary text whenever it exists, regardless of `show_summary`: `app/Models/Sermon.php:678-707`.
- Public sermon pages then emit that meta description into meta tags and JSON-LD: `resources/views/sermons/sermon.blade.php:14-25`, `resources/views/sermons/sermon.blade.php:32-80`.
- The public sermon API always returns `points` and `thumbnail_metadata`: `app/Http/Controllers/Api/SermonApiController.php:28-35`, `app/Http/Resources/SermonResource.php:22-52`.
- `thumbnail_metadata` includes storage paths such as `plain_thumbnail_path` and `overlay_thumbnail_path`: `app/Models/Sermon.php:179-188`, `app/Services/ThumbnailGenerationService.php:167-179`.

Impact:

- A summary that is hidden from the visible page body can still leak in metadata and structured data.
- Outline points hidden from HTML remain visible in the public API.
- Internal file-path metadata is exposed through a public API response.

### 8. Admin verification rules are duplicated and inconsistent

Evidence:

- UI gates require `is_admin` and verified email: `app/Providers/AuthServiceProvider.php:31-41`.
- `MeetingPolicy` and `SermonPolicy` require only `is_admin`: `app/Policies/MeetingPolicy.php:13-64`, `app/Policies/SermonPolicy.php:12-45`.
- The generic `admin` middleware also requires only `is_admin`: `app/Http/Middleware/EnsureUserIsAdmin.php:17-25`.
- The `/admin/*` route group adds `verified`, but legacy meeting and sermon mutation routes do not: `routes/web.php:45-48`, `routes/web.php:85-103`, `routes/web.php:121-180`.
- The shared Livewire admin trait also checks only `is_admin`: `app/Livewire/Traits/WithAdminAuthorization.php:7-12`.

Impact:

- Unverified admins can reach some real mutation paths even though the UI gate system implies they should not.
- The visible admin affordances and the enforceable backend rules do not match.

Concrete examples:

- `meetings.index`, `meetings.update`, and `meetings.destroy` only need `auth + admin`: `routes/web.php:45-48`.
- Legacy sermon delete endpoints only need `auth + admin`: `routes/web.php:85-103`, `app/Http/Controllers/Admin/SermonAdminController.php:100-168`.

### 9. A few admin Livewire components rely only on route middleware instead of self-authorization

Evidence:

- Most admin Livewire components call `authorizeAdmin()`, but `App\Livewire\Admin\CalendarEvents\ListCalendarEvents` and `App\Livewire\Admin\CalendarEvents\EditCalendarEvent` do not: `app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php:14-84`, `app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php:13-88`.
- `App\Livewire\Admin\Components\MediaUploadField` also has no self-authorization even though it mutates media collections: `app/Livewire/Admin/Components/MediaUploadField.php:15-100`.

Impact:

- Today these components are mounted under the verified admin route group, so this is mainly a defense-in-depth gap.
- It still makes the admin surface less consistent and easier to misuse if the components are ever reused elsewhere.

### 10. Page visibility metadata is security-relevant but not exposed in the current page admin UI

Evidence:

- Page access control depends on the `admin` column in `PageController`: `app/Http/Controllers/PageController.php:29-33`, `app/Http/Controllers/PageController.php:59-62`.
- The page repository also consumes `admin`: `app/Repositories/PageRepository.php:23-29`.
- But the Livewire page editor and listing only handle `heading`, `slug`, `area`, `navigation`, `description`, and `markdown`: `app/Livewire/Admin/Pages/PageForm.php:13-47`, `app/Livewire/Admin/Pages/ListPages.php:90-113`.

Impact:

- Administrators cannot clearly see or manage one of the core visibility controls through the current UI.
- That increases the chance of stale restrictions, invisible privileged pages, and mismatches between intended and actual exposure.

## Duplication And Missing-Check Matrix

| Area | Strongest check present | Missing or weaker parallel path |
| --- | --- | --- |
| Page visibility | `PageController` checks `admin=yes` and members auth | `MeetingController`, `SitemapService`, `HeaderComposer`, `PageRepository`, and `PageCardService` consume `Page` records without reapplying the same rules |
| Children's Corner | Route middleware and `SermonAssetController` guard access | Views and model accessors publish direct public URLs instead of using the guarded asset routes |
| Admin verification | `/admin/*` route group and `manage-*` gates require verified email | Policies, `admin` middleware, and `authorizeAdmin()` only require `is_admin` |
| Calendar exposure | Main `/calendar` page uses `confirmed()` | Public `/meetings/{meeting}/events` and `/calendar/uncategorized` use looser or no status filters |
| Livewire admin auth | Most admin components call `authorizeAdmin()` | Calendar event admin components and media upload field rely only on route placement |

## Leak Inventory

| Leak path | What can leak | Why |
| --- | --- | --- |
| Direct storage URLs for section candidates | Unpublished extracted audio/video | Candidate extraction writes to `public` disk before publish and dashboard exposes `Storage::url(...)` |
| Direct storage URLs for Children's Corner media | Non-public talk media after first authorized view | Page renders raw `audio_url`, `video_url`, and `thumbnail_url` |
| Meeting wrapper routes | Members/admin page content and meeting photos | Meeting route reads related `Page` and media directly, without page policy checks |
| Public calendar side routes | Tentative or uncategorized event details | Side routes use broader status filters than the main calendar |
| `sitemap.xml` | Members-page URLs and all meeting URLs | Sitemap excludes only `admin=yes`, not auth-only members pages or meeting-wrapper restrictions |
| Public sermon metadata/API | Hidden summary text, hidden outline points, internal thumbnail paths | Meta description ignores `show_summary`; API ignores `show_points` and returns `thumbnail_metadata` |

## Priority Order

1. Move unpublished section media off the public disk or serve it through signed/private delivery.
2. Stop rendering direct public URLs for non-public Children's Corner assets; route all protected media through a single guarded delivery path.
3. Decide whether auth-only areas are meant to be "members-only" or merely "signed-in user" and align registration/verification rules with that decision.
4. Apply page visibility checks anywhere a `Meeting` or sitemap entry can expose page-backed content.
5. Align calendar side routes with the confirmed-only policy used by the main public calendar.
6. Bring metadata/API exposure in line with `show_summary` and `show_points`.
7. Collapse admin authorization onto one consistent source of truth, including verified-email requirements.
