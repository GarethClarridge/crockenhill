# Horizon — Staging Smoke Test (one-time)

**Status: ☐ Outstanding** · Owner: maintainer · Environment: **staging**

This is the single verification action carved out of
[docs/archived-plans/JUNE-2026-REVIEW-IMPLEMENTATION-2026-06-03.md](../archived-plans/JUNE-2026-REVIEW-IMPLEMENTATION-2026-06-03.md)
Phase P1. Laravel Horizon (queue dashboard) is installed and live in production
config; this confirms it behaves end-to-end on staging before we fully trust it.
It cannot be run from a repo checkout or CI — it needs a deployed environment
with Redis, the Horizon supervisor, real workers, and admin login.

## Background (what's already in place)

- Horizon replaces the raw `queue:work` supervisor block with a single
  `php artisan horizon` program in `docker/production/supervisord.conf`.
- One supervisor mirrors the old worker exactly: connection `redis`, the six
  queues in priority order
  (`video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default`),
  `balance => false`, `minProcesses = maxProcesses = 2`, `tries => 3`,
  `timeout => 7200`, `memory => 512`, `sleep => 3`, `maxTime => 86400`,
  `maxJobs => 500` (see `config/horizon.php`).
- `horizon:snapshot` runs every 5 minutes (production-only) so the metrics
  graphs populate.
- The dashboard is gated by the existing admin check
  (`HorizonServiceProvider::gate()` → `canAccessAdmin()`); it is **not** in public
  navigation. The 403/200 admin-gate feature test already passes.

## Steps

1. **Deploy** the current branch to staging and confirm Horizon is running:
   visit `/horizon` as an admin — the dashboard loads, and the supervisor shows
   the six queues with 2 processes.
2. **Happy path — one real media-processing job end to end.** Trigger a normal
   sermon upload/process (or dispatch a real processing job). Confirm it:
   - appears in Horizon (Recent/Completed Jobs and on the relevant queue), and
   - **completes exactly once** — the fixed `minProcesses = maxProcesses = 2`
     pinning means no duplicate execution.
3. **Failure path — a deliberately-failed job.** Dispatch a job that throws
   (e.g. via `tinker` on staging, dispatch a job with input guaranteed to fail).
   Confirm it:
   - lands in Horizon's **Failed Jobs** tab with its stack trace, and
   - can be **retried from the dashboard UI**, after which it re-runs.

## Done when

- A real media-processing job ran once and showed up in the dashboard, **and**
- a forced failure appeared in Failed Jobs and was successfully retried from the UI.

On success: tick this box, and note completion against P1 in the archived June
plan if useful.

## Rollback (if Horizon misbehaves on staging)

Revert the `supervisord.conf` block to the old `queue:work` line and remove the
package. No data/schema changes — Horizon stores metrics in Redis only.
