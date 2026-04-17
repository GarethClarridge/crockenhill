# Architectural Boundary Review

Date: 2026-04-16

Method note:
Laravel Boost / `search-docs` were requested first, but no Boost MCP resources or templates were available in this session, so this pass used direct repository inspection plus the March 2026 review artifacts as background only.

## Findings

### [P1] Private Children's Talk pages still render disk URLs instead of the guarded asset routes

Files:
`app/Http/Controllers/ChildrensCornerController.php:71-85`
`app/Presenters/SermonViewPresenter.php:113-124`
`app/Presenters/SermonViewPresenter.php:280-295`
`app/Presenters/SermonViewPresenter.php:397-408`
`app/Services/SermonStorageService.php:223-228`
`app/Services/SermonStorageService.php:404-413`
`app/Console/Commands/MoveChildrensTalksToPrivateStorage.php:13-18`
`app/Console/Commands/MoveChildrensTalksToPrivateStorage.php:24-29`
`tests/Feature/SermonPrivateAssetTest.php:16-90`

`ChildrensCornerController` renders `SermonViewPresenter::present($sermon)`, and that presenter still derives `audio_url`, `thumbnail_url`, and `video_url` from `SermonStorageService`, which in turn resolves plain disk/CDN URLs. The rendering layer never switches to the guarded `sermons.audio` / `sermons.thumbnail` routes for private assets.

That means the private-storage hardening is only half-finished. The app now has explicit support for moving Children's Talk assets into `private/...` and explicit controller coverage for serving private assets, but the page itself still emits storage URLs rather than the guarded route URLs. Once private-storage migration is in use, authorized users can end up with page/player URLs that bypass the intended controller boundary and are not the canonical delivery path.

### [P2] Media upload deduplication is still explicitly race-prone at the API-to-processing boundary

Files:
`app/Services/UnifiedMediaProcessor.php:65-76`
`app/Services/UnifiedMediaProcessor.php:236-271`
`database/migrations/2026_03_18_000001_add_file_hash_to_media_processing_logs.php:11-13`

The current upload dedupe path hashes the incoming file, checks for an active matching `file_hash`, and reuses that run when it finds one. But the implementation also documents the remaining TOCTOU window: two concurrent identical uploads can both pass the lookup before either processing log exists, and both then create their own run. The schema only adds a non-unique index on `file_hash`, so the database does not close that race.

This is much better than having no duplicate handling, but it is still best-effort rather than idempotent. For API callers or automation that retry aggressively, identical concurrent submissions can still duplicate expensive downstream work and create avoidable operator noise.

### [P2] The effective "members-only" boundary is still "any self-registered account"

Files:
`app/Livewire/Auth/Register.php:69-75`
`routes/web.php:182-189`
`app/Services/SermonExposurePolicy.php:41-46`
`app/Http/Middleware/EnsureChildrensCornerAccess.php:21-28`
`app/Models/User.php:38-39`

The current registration flow creates an account, signs the user in immediately, and sends email verification afterwards. At the same time, the members landing page, song pages, and private Children's Corner access still only require `auth`, and the policy explicitly treats non-public Children's Corner content as viewable by any authenticated user.

If the intended boundary is truly "anyone who can create an account", this is consistent. If the intended boundary is "trusted member", "invited member", or even "verified account", the code is still materially wider than that policy. The code comments themselves describe this as the current behavior, so this remains an architectural decision point rather than an accident that was fully closed in March.

### [P2] Church service item ordering integrity is still enforced in application code, not in the schema

Files:
`database/migrations/2026_02_28_160100_create_church_service_items_table.php:16-27`
`app/Services/ChurchServiceItemSyncService.php:48-55`
`app/Services/ChurchServiceItemSyncService.php:646-679`

`church_service_items` still has only a plain index on `(church_service_id, position)`, not an active-row uniqueness guarantee. The sync service has to reject duplicate incoming positions, resequence active items, and then re-check for duplicates at the end of the sync.

That is a sign the real invariant still lives in PHP, not in MySQL. Under concurrent imports or future write paths that do not go through the same service, duplicate active positions can still land in the database even though downstream logic assumes one canonical ordered list per service.

### [P3] A few deployment and operations docs still carry stale runtime assumptions

Files:
`docs/operations/media-processing-runbook.md:459`
`docs/deployment/media-processing.md:776-779`
`docker/production/supervisord.conf:49-57`
`.github/workflows/deploy.yml:355-363`
`.github/workflows/deploy.yml:391-405`
`.github/workflows/deploy.yml:459-482`
`docker-compose.prod.yml:4-5`
`docker-compose.prod.yml:23-26`

The core deployment path is in much better shape now, but there are still a couple of stale examples around it. The media-processing runbook still shows an optimization example with `--queue=livestream`, while the actual runtime uses the `...livestream-processing...` queue set from Supervisor. The deployment guide also still shows a `your-app:latest` image build example even though the real workflow and production compose file are now centered on a smoke-tested SHA via `IMAGE_TAG`.

These are documentation issues, not runtime bugs, but they matter because they recreate exactly the kind of "tribal knowledge vs. actual production path" drift that the March review called out.

## Open Questions

- Has `sermons:move-childrens-talks-to-private` been run in production yet? If yes, finding 1 is likely user-visible already, because the page presenter still emits storage URLs instead of guarded asset routes.
- Is "members-only means any account" a deliberate product decision, or is it still a temporary policy? That answer changes whether finding 3 is acceptable risk or a real exposure bug.
- For media uploads, is best-effort duplicate suppression considered sufficient, or should the API boundary become strictly idempotent for identical concurrent submissions?

## What Improved Since March

- Bootstrap and provider hygiene is materially better: scheduled tasks are now production-gated and overlap-protected in `bootstrap/app.php`, provider responsibilities are split more cleanly, and the obvious redundant/no-op registrations from March are gone.
- Command architecture is healthier: the highest-risk commands reviewed in March now have much thinner wrappers and dedicated tests, including temp cleanup, unpublished asset cleanup, meeting photo migration, WebP conversion, and the livestream sermon command.
- Exposure boundaries were hardened in several important places: candidate section media is now private/admin-served, meeting pages now reuse page-visibility checks, sitemap generation excludes members pages, and public calendar reads are confirmed-only.
- External integration and schema guardrails both improved: Google sync now separates "seen upstream" from "processed successfully", Mailgun supports failed-message recovery and replay protection, OpenLP parsing now rejects decompression-bomb style archives, speaker/profile uniqueness and processing-log retention constraints were added, and service-section publication/timing checks were tightened.
- CI and deployment assumptions are far clearer than they were in March: deploys are SHA-pinned to the smoke-tested image, the production app container runs the scheduler, and `scripts/post-deploy-smoke.sh` now verifies web, database, Redis, queue workers, and scheduler state after deploy.
