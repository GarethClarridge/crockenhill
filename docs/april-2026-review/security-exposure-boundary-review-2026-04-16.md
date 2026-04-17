# Security and Exposure-Boundary Review

Date: 2026-04-16

Method note:
Laravel Boost / `search-docs` were requested first, but no Boost MCP resources or templates were available in this session, so this pass used direct repository inspection, the March 2026 review artifacts as background only, and current Laravel / Livewire docs for security-convention checks.

## Findings

### [P1] Private Children's Talk pages still emit raw disk URLs instead of the guarded asset routes

Files:
`app/Http/Controllers/ChildrensCornerController.php:71-85`
`resources/views/childrens-corner/show.blade.php:16-21`
`resources/views/childrens-corner/show.blade.php:95-109`
`app/Presenters/SermonViewPresenter.php:113-124`
`app/Presenters/SermonViewPresenter.php:280-298`
`app/Presenters/SermonViewPresenter.php:397-411`
`app/Services/SermonStorageService.php:66-78`
`app/Services/SermonStorageService.php:223-228`
`app/Services/SermonStorageService.php:404-412`
`app/Http/Controllers/SermonAssetController.php:27-61`
`app/Http/Controllers/SermonAssetController.php:67-167`
`tests/Feature/SermonPrivateAssetTest.php:15-90`

`ChildrensCornerController` renders `sermonViewPresenter->present($sermon)`, and the page then consumes `audio_url`, `thumbnail_url`, and `video_url` directly. Those presenter methods still call `SermonStorageService::getPublicUrl()`, `getThumbnailUrl()`, and `getVideoUrl()`, which resolve plain disk / CDN URLs even after `getSermonFileInfo()` has classified `private/...` assets onto the local private disk.

That means the controller hardening added around `SermonAssetController` is not yet the canonical delivery path for private Children's Talk assets. Once an asset has been moved to private storage, the rendered page can either bypass the intended guarded route or emit a broken `/storage/private/...`-style URL, depending on the active disk configuration. The direct private-asset controller path is tested, but there is still no regression test asserting that the rendered Children's Corner page points at the guarded asset routes rather than a raw storage URL.

### [P2] The effective "members-only" boundary is still "any self-registered account"

Files:
`routes/web.php:102-109`
`routes/web.php:182-189`
`app/Livewire/Auth/Register.php:53-75`
`app/Services/PublicPageVisibilityGuard.php:26-30`
`app/Services/SermonExposurePolicy.php:41-45`
`app/Http/Middleware/EnsureChildrensCornerAccess.php:21-28`
`tests/Feature/MembersAreaAccessModelTest.php:25-76`

The current registration flow still allows any guest to create an account, signs that user in immediately, and sends email verification afterwards. At the same time, the members landing page, song pages, members-area pages, and private Children's Corner access still only require `auth`, and both the visibility guard and the Children's Corner policy explicitly document that "any authenticated account" is the current rule.

If that is the intended product boundary, the implementation is internally consistent. If the intended boundary is "verified member", "approved member", or "invited church member", the app is still materially overexposed at the read boundary. This is not an untested accidental edge case either: `MembersAreaAccessModelTest` now codifies the wider trust boundary as expected behavior.

### [P3] The verified-admin boundary is still inconsistent outside the main admin stack, and page / meeting wrappers lack a regression test for the unverified-admin case

Files:
`app/Models/User.php:76-78`
`app/Http/Middleware/EnsureUserIsAdmin.php:17-25`
`app/Livewire/Traits/WithAdminAuthorization.php:9-17`
`app/Services/PublicPageVisibilityGuard.php:22-24`
`app/Http/Controllers/MeetingController.php:54-60`
`app/Http/Requests/CategorizeEventRequest.php:12-15`
`app/Http/Controllers/MemberController.php:16-31`
`database/factories/UserFactory.php:15-22`
`tests/Feature/Admin/AdminLivewireAuthorizationTest.php:95-103`
`tests/Feature/PageSecurityTest.php:17-32`
`tests/Feature/PageSecurityTest.php:62-83`
`tests/Feature/PublicReadSideInvariantsTest.php:64-100`

The app now has a clear canonical admin boundary: `User::canAccessAdmin()` requires both `is_admin` and a verified email, and both the main admin middleware and Livewire trait enforce that rule. The problem is that a few read-side and supporting authorization paths still use raw `is_admin` checks instead of that canonical gate.

`PublicPageVisibilityGuard` still treats `is_admin` as sufficient for admin-marked pages, and `MeetingController@show` reuses that same guard for meeting-backed pages. `CategorizeEventRequest` and the member dashboard's admin-only counters also still key off raw `is_admin`. That leaves the codebase with two competing notions of "admin": the verified-admin boundary that protects `/admin/*` and most hardened admin tooling, and the looser raw-admin boundary that still appears in wrapper pages and a couple of supporting request/controller paths. The current page and meeting tests only cover verified admins via factory defaults, so there is no focused regression test proving that an unverified admin is denied those wrapper surfaces.

## Open Questions

- Has the Children's Talk private-storage migration been run in production yet? If yes, the presenter / page mismatch in finding 1 is likely already user-visible, either as broken playback or as a bypass of the intended guarded route.
- Is "members-only" intentionally defined as "any self-registered account", or should that boundary move to verified, invited, or approved members?
- Should admin-only public pages and meeting-backed admin content use the same `canAccessAdmin()` boundary as `/admin/*`, or is unverified-admin read access on those wrapper paths intentional?
- Do you want formal policies for pages, users, church services, calendar events, and inbound emails, or is the long-term model still "single verified-admin role plus route / middleware / trait checks"? `AuthServiceProvider` still only maps `Meeting` and `Sermon` policies in `app/Providers/AuthServiceProvider.php:18-21`.
- Is `TRUSTED_PROXIES` tightly scoped in production? Client IP, rate limiting, and some logging assumptions now depend on the value wired through `bootstrap/app.php:35-39`.

## What Improved Since March

- I did not find a still-open repeat of the March webhook replay issue, public section-preview media issue, or the meeting / sitemap / calendar read-side exposures in the code paths reviewed here.
- Candidate section preview media now stays on the private local disk and is served through an admin-only controller with no-store headers and traversal checks: `app/Jobs/PrepareSectionPublicationCandidates.php:292-305`, `app/Http/Controllers/Admin/ServiceSectionCandidateMediaController.php:34-69`, `tests/Feature/Admin/ServiceSectionCandidateMediaControllerTest.php:21-114`.
- Mailgun inbound handling now rejects replayed signature tuples and safely handles failed-message redelivery: `app/Http/Middleware/EnsureValidMailgunWebhookSignature.php:20-44`, `tests/Feature/Api/MailgunInboundWebhookControllerTest.php:162-229`.
- Meeting, sitemap, and calendar read boundaries are materially tighter than in March: meeting wrappers now reuse page visibility checks, calendar reads are confirmed-only, and the surrounding public read-side invariants are explicitly tested: `app/Http/Controllers/MeetingController.php:54-60`, `app/Services/CalendarService.php:21-41`, `app/Services/CalendarService.php:69-80`, `tests/Feature/PublicReadSideInvariantsTest.php:64-100`.
- The core admin boundary is much more consistent across routed admin screens and Livewire actions: `app/Http/Middleware/EnsureUserIsAdmin.php:17-25`, `app/Livewire/Traits/WithAdminAuthorization.php:9-17`, `tests/Feature/Admin/AdminLivewireAuthorizationTest.php:50-103`.
