# Production Operations

Written 2026-07-05. Sources of truth, in order: `docker-compose.prod.yml`,
`docker/production/supervisord.conf`, `config/horizon.php`, `bootstrap/app.php` (schedule),
`.github/workflows/deploy.yml`. If this document disagrees with any of them, they win.

## Stack

Single-server Docker Compose (`docker-compose.prod.yml`):

- **caddy** — TLS termination / reverse proxy (Let's Encrypt via volume-persisted certs).
- **app** — `ghcr.io/garethclarridge/crockenhill:{git-sha}`. One container runs nginx, php-fpm,
  the scheduler (`schedule:work`), and **Laravel Horizon**, all under supervisord.
- **mysql** (8.0) and **redis** (7, AOF-persisted — it holds the queue).

Named volumes persist storage, temp-upload space (`app-temp` — the disk-pressure bottleneck for
large livestream uploads), logs, MySQL, and Redis data.

## Queues (Horizon)

Workers are managed by Horizon, not raw `queue:work` (adopted June 2026). One supervisor,
`supervisor-media`, runs 2 processes in production with `balance => false`, giving each worker
the full queue list in strict priority order:

`video-processing, audio-processing, sermon-processing, livestream-processing,
speaker-identification, default`

Invariants (enforced in `config/horizon.php` comments — do not "tidy" them away):

- `timeout` (7200 s) must stay below `REDIS_QUEUE_RETRY_AFTER` (7260 s) or jobs run twice.
- supervisord's `stopwaitsecs` for the horizon program stays ≥ 7260 so supervisord-initiated
  restarts let a 2-hour media job drain. **Deploys are different**: a container swap is bounded
  by Docker's ~10 s stop grace, so a job in flight at deploy time is killed and re-released
  after `retry_after`. This is deliberate.

Dashboard: `/horizon`, gated by the `viewHorizon` gate (admin). Failed jobs: inspect and retry
from Horizon, or via the API retry endpoint (`docs/api/media-processing.md`).

A livestream run showing status `Failed` with current step `manual_review_required` is not a
crash — it is parked awaiting segment confirmation (admin review inbox, or the API
`confirm-segment` endpoint). The `awaitingManualSermonReview` scope finds these.

## Scheduler

`schedule:work` under supervisord; tasks defined in `bootstrap/app.php`, production-only.
Highlights: `calendar:sync` (4-hourly), temp-file and unpublished-section-asset cleanup
(6-hourly), `scripture:refresh-passages` (daily), `health:check` (every 5 min),
`horizon:snapshot` (every 5 min), and spatie/laravel-backup (`backup:clean` 01:00,
`backup:run` 01:30, `backup:monitor` 08:00 — to the private `do_spaces_backups` disk).
Schedule execution is monitored by spatie/laravel-schedule-monitor plus a per-minute heartbeat
that the `ScheduleCheck` health check verifies.

## Monitoring

- `/health` — spatie/laravel-health dashboard, admin-only. Checks run via the scheduled
  `health:check` every 5 minutes (results for not-due checks show as `skipped` — expected).
- Error tracking: **not yet installed**; `docs/plans/SENTRY-ERROR-TRACKING.md` is the plan.
- One outstanding one-time task: `docs/operations/horizon-staging-smoke-test.md`.

## Deploy and rollback

Push to `master` → `.github/workflows/deploy.yml`:

1. **master-validation** — Pint, PHPStan, core + dedicated test suites (parallel with **dusk**).
2. **build** — image pushed to GHCR tagged with the git SHA.
3. **smoke** — boots the built image and probes it.
4. **deploy** — SSH to the server, `docker compose pull` + `up -d` with `IMAGE_TAG={sha}`
   (mysql/redis first, health-gated, then the app swap).

Rollback: `rollback.yml` via manual workflow dispatch — supply a previously deployed git-SHA
`image_tag` and type `rollback` to confirm.
