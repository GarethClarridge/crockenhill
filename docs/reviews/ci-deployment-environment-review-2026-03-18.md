# CI, Deployment, and Environment Review

Date: 2026-03-18

Scope reviewed:
- GitHub Actions in `.github/workflows/deploy.yml`
- Production image/runtime in `Dockerfile`, `docker-compose.prod.yml`, `docker/production/*`, and `Caddyfile`
- Bootstrap/runtime config in `bootstrap/app.php` and `config/*.php`
- Queue dispatch and worker assumptions in `app/Services/*`, `app/Jobs/*`, and queue coverage tests
- Operational/deployment docs in `docs/deployment/*`, `docs/operations/*`, and `scripts/server-setup.sh`

Verification notes:
- Repository inspection only, plus a direct run of `./scripts/check-bootstrap-safety.sh`
- I did not run the full Laravel test suite, PHPStan, or Pint because this pass only adds a review document

## Findings

### 1. Critical: Production deploy is not pinned to the artifact that CI smoke-tested

The build job produces a deterministic SHA-tagged image in `.github/workflows/deploy.yml:328-330,354-360`, and the smoke job explicitly tests that SHA in `.github/workflows/deploy.yml:389-417`. But the deploy job never passes that SHA into Compose. Instead it runs `docker compose ... pull` and `up -d` against `docker-compose.prod.yml:23-30`, where the app image defaults to `${IMAGE_TAG:-latest}`.

Why this matters:
- CI can smoke-test image `A` and then deploy a different image `B` if `latest` moves before the SSH deploy runs.
- A manual re-run of the deploy job can silently pick up a newer `latest` tag than the one the earlier jobs validated.
- This breaks the expected safety chain from test -> build -> smoke -> deploy.

### 2. Critical: The production runtime never starts Laravel's scheduler, so recurring work depends on out-of-band tribal knowledge

`bootstrap/app.php:15-23` registers scheduled commands for calendar sync, temp-file cleanup, unpublished-section cleanup, and scripture refresh. The production container only starts `nginx`, `php-fpm`, and queue workers in `docker/production/supervisord.conf:8-46`. `docker-compose.prod.yml:7-89` also defines only `caddy`, `app`, `mysql`, and `redis`; there is no scheduler sidecar, cron service, or `schedule:work` process.

Why this matters:
- `calendar:sync`, `media:cleanup-temp-files`, `media:cleanup-unpublished-section-assets`, and `scripture:refresh-passages` will never run unless the host has an undocumented extra cron/systemd setup.
- Cleanup drift will show up as growing temp storage and stale unpublished assets.
- Data freshness for calendar and scripture enrichment is operationally coupled to someone remembering hidden server setup.

### 3. High: The repo's deployment docs still describe a different queue architecture than the one production actually runs

The real production worker is hard-coded to Redis in `docker/production/supervisord.conf:28-36`. That matches `.env.example:21-26`, which treats Redis as the default cache/session/queue backend. But multiple deployment docs still instruct operators to run database-backed workers:
- `docs/deployment/automated-sermon-processing.md:27,104`
- `docs/deployment/media-processing.md:129,229,253,279,392,401,404`
- `docs/deployment/thumbnail-generation-deployment.md:125`

Why this matters:
- An operator following the docs can ship `.env.production` with `QUEUE_CONNECTION=database` while production workers still listen on `queue:work redis`, which would strand every queued job.
- The mismatch is large enough that "successful" production setup now depends on knowing which repo files to ignore.
- This is exactly the kind of environment drift that causes silent post-deploy failures rather than obvious deploy-time failures.

### 4. High: The dedicated thumbnail queue configuration is effectively dead, but the codebase and tests imply that it is live

`config/thumbnail-generation.php:25-30` exposes a dedicated thumbnail queue name and connection. `tests/Unit/Config/QueueWorkerCoverageTest.php:41-55` even asserts that workers consume that `thumbnails` queue. But `GenerateThumbnail` itself does not set a queue or connection in `app/Jobs/GenerateThumbnail.php:18-48`, and the job is only inserted into larger chains in:
- `app/Services/ProcessingPipelineBuilder.php:57-68`
- `app/Services/ProcessingPipelineBuilder.php:96-113`
- `app/Services/ProcessingPipelineBuilder.php:122-133`
- `app/Services/ProcessingPipelineBuilder.php:143-156`

Those chains are dispatched onto non-thumbnail queues in:
- `app/Services/UnifiedMediaProcessor.php:263-281`
- `app/Services/UnifiedMediaProcessor.php:357-375`
- `app/Services/SermonJobPipelineService.php:41-50`
- `app/Services/LivestreamSegmentationService.php:213-229`
- `app/Actions/ConfirmLivestreamSermonSegment.php:72-74`

Why this matters:
- `THUMBNAIL_QUEUE_CONNECTION` and `THUMBNAIL_QUEUE_NAME` currently do not control where `GenerateThumbnail` runs.
- Operators can waste time debugging or scaling a dedicated `thumbnails` worker that never receives the app's thumbnail jobs.
- The queue coverage test reinforces a false operational model rather than validating real dispatch behaviour.

### 5. High: CI's bootstrap safety guard is currently self-contradictory and blocks the main test pipeline

The test job runs `./scripts/check-bootstrap-safety.sh` first in `.github/workflows/deploy.yml:30-31`. That script fails whenever `config()` is used inside the `withMiddleware()` bootstrap block in `scripts/check-bootstrap-safety.sh:11-24`. But `bootstrap/app.php:25-33` currently does exactly that with `config('app.trusted_proxies')`.

I verified this locally in the workspace: `./scripts/check-bootstrap-safety.sh` exits `1` against the current tree.

Why this matters:
- The CI pipeline can fail before it reaches the real test, analysis, and build stages.
- A safety guard that no longer matches the code has become a release blocker rather than a protection.
- Proxy/bootstrap changes now depend on maintainers remembering to update both the code and a bespoke shell heuristic.

### 6. High: Release smoke checks only prove that the container boots, not that production is operational

The image smoke job runs the container with `QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite`, and non-production cache/session settings in `.github/workflows/deploy.yml:405-414`, then only checks `/up` in `.github/workflows/deploy.yml:416-417`. The remote deploy ends the same way by curling `/up` in `.github/workflows/deploy.yml:501-503`.

At the same time, the repo's ops docs still point people at health surfaces that do not exist:
- `docs/operations/media-processing-runbook.md:49,498,707,724` refer to `/health`
- `docs/deployment/media-processing.md:415` refers to `php artisan health:check`
- `docs/api/unified-media-processing.md:463` and `docs/api/automated-sermon-processing.md:477` refer to `/api/sermons/processing/health`

But the actual route files only expose `/up` plus media status endpoints in `bootstrap/app.php:9-14` and `routes/api.php:46-87`, and the current artisan command list does not include `health:check`.

Why this matters:
- A deploy can go green while queues are not draining, storage volumes are not writable, Redis is misconfigured, or proxy/URL settings are wrong.
- Operators do not have a single real, documented smoke path for "web up, DB reachable, queue alive, storage writable, scheduler running".
- Documentation promises health commands/endpoints that on-call engineers cannot actually use during an incident.

### 7. Medium: Podcast feed cache invalidation is intentionally skipped, so sermon changes can stay stale in production feeds

Podcast feeds use flexible caching in `app/Services/PodcastFeedService.php:25-33`, with a default TTL/stale window of 1 hour / 2 hours in `config/podcast.php:74-77`. But `app/Observers/SitemapCacheObserver.php:63-64` explicitly avoids clearing podcast feed cache "to prevent test failures".

Why this matters:
- Sermon updates that affect podcast consumers, such as title, summary, audio path, thumbnail, or transcript URL, can remain stale in feeds well after the underlying record changed.
- The current behaviour optimizes around test expectations instead of operational freshness.
- This is a real cache invalidation risk for public syndication, not just an internal browse-page issue.

## Additional observations

- `config/filesystems.php:58-84` accepts non-standard `AWS_KEY` / `AWS_SECRET` / `AWS_REGION` names for the `s3` disk and dual names for DigitalOcean Spaces, while `config/services.php:29-32` uses the standard Laravel AWS names. `.env.example:74-80` only documents one subset of those keys. The result is secret/config sprawl without a clear canonical inventory.
- `config/openai.php:15-48`, `config/services.php:44-46`, and `config/media-processing.php:91-102` all reference OpenAI-related settings. That duplication increases the chance of partial configuration drift.
- `scripts/server-setup.sh:29` tells operators to put the deploy user's SSH public key into the `PROD_SSH_KEY` secret, but `appleboy/ssh-action` needs the private key. The setup script would mislead a fresh deploy.
- `docs/deployment/automated-sermon-processing.md:249` still says `git pull origin main` while the deploy workflow triggers from `master` in `.github/workflows/deploy.yml:4-6`.
- The deploy backup step parses DB credentials with `export $(grep ... | xargs)` in `.github/workflows/deploy.yml:469-478`. That is brittle for shell-sensitive passwords and keeps database backup success dependent on secret formatting, not just correctness.

## Suggested next passes

1. Pin production deploys to the smoke-tested image SHA or digest, and stop relying on mutable `latest`.
2. Add an explicit scheduler process to production and a post-deploy smoke command that verifies web, DB, queue, writable storage, and scheduler presence.
3. Collapse deployment docs to one canonical production story: Docker Compose + Redis, or change the runtime to match the docs.
4. Either wire the thumbnail queue config into real dispatch or remove the dead config/test surface so operators are not debugging ghosts.
5. Replace the phantom `/health` and `health:check` documentation with real supported checks, then automate them in CI/deploy.
6. Decide whether podcast feed freshness or test convenience is the source of truth, and add after-commit cache invalidation coverage accordingly.
