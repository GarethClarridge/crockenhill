# June 2026 Review — Implementation Plan

> **Archived 2026-06-29 — all in-scope phases done or consciously deferred.** Every
> structural and correctness phase shipped; deferred phases (R4, P4, P5, the P6 tier)
> carry written verdicts and re-open triggers. The one remaining hands-on item, P1's
> Horizon **staging smoke test**, was carved out into
> [docs/operations/horizon-staging-smoke-test.md](../operations/horizon-staging-smoke-test.md)
> so it can be run on staging independently of this plan. Retained as the execution log.

Created 2026-06-03. **Refreshed 2026-06-10** after a repo-state audit (own review + Codex review).
**Re-verified 2026-06-18** — every completed phase's artifacts still hold against the repo (Phase 0
fixes, R-track moves, Horizon/backup/health adoptions all present); the only stale data point was
P5's `laravel/ai` version, corrected below. No phase verdict changed. The single open in-scope item
remains P1's staging smoke test.
Implements the findings in
`project-wide-improvement-review-2026-06-03.md` (deleted 2026-07-05 — in git history).

**What changed in the refresh.** The original plan was written as a forward checklist; within a week,
its structural phases (R1–R3) had landed and invalidated the file paths, inventories, and premises of
the phases that remained. This version: marks completed phases done with a short execution note;
pulls the production-correctness fixes out of the package phases into an immediate, dependency-free
**Phase 0**; corrects every stale path/inventory; and re-premises the package phases against code
that now exists (bespoke CSP, route-canary monitoring). Verdicts that still hold are reaffirmed, not
re-litigated.

## Scope

- Improve **structure, standardisation, and simplification** without changing behaviour.
- Fix the **latent production bugs** the review surfaced (Phase 0) — these are behaviour *corrections*
  and are exempt from the no-behaviour-change rule.
- Capture the **package-adoption** opportunities as discrete, approval-gated phases (no dependency
  is added without explicit sign-off, per `AGENTS.md`).
- Record the **decision-only** items so non-actions are conscious choices.

## Quality Gates

Run for every phase that touches PHP, before finalising:

- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail composer phpstan` — must stay at 0 errors.
- `vendor/bin/sail artisan test --parallel --compact` (or a focused filter for small phases).
- `vendor/bin/sail artisan dusk` — for any phase that touches public routes, the upload form, or
  admin Livewire screens.

---

## Phase 0 — Immediate correctness fixes (no dependencies, no approval gates)

**Priority: Highest. Status: ✅ Done** (implemented 2026-06-10). Three independent fixes, shipped as
three commits on one PR. None required a package; none waited on anything else in this plan.

Execution note: redis `retry_after` default is now `7260` in [config/queue.php](../../config/queue.php)
with `REDIS_QUEUE_RETRY_AFTER` declared in `.env.example`, guarded by the cross-file invariant test
[tests/Feature/Config/QueueRetryAfterInvariantTest.php](../../tests/Feature/Config/QueueRetryAfterInvariantTest.php).
The upload validator now probes the local temp disk via a new shared helper
[app/Services/Media/TempDiskSpace.php](../../app/Services/Media/TempDiskSpace.php) reading the
`media-processing.storage.temp_disk_min_free_gb` config key (the importer guard and the importer
command default route through the same source), with a test proving a full temp disk rejects an
upload. The stray `\004` OpenPGP key file is removed. Pint clean, PHPStan 0 errors, affected suites
green.

### 0.1 — Fix `retry_after` < worker `--timeout` (duplicate-delivery bug)

[config/queue.php:80](../../config/queue.php#L80) defaults the redis connection's `retry_after` to
`REDIS_QUEUE_RETRY_AFTER, 3600`, while the production worker
([docker/production/supervisord.conf:51–55](../../docker/production/supervisord.conf#L51)) runs
`queue:work redis ... --timeout=7200`. A job running 1–2 h (FFmpeg, transcription) can be released
and **picked up a second time while still running**; with `--tries=3`, phantom re-deliveries also
burn attempts and can fail long jobs spuriously. `.env.production` is not tracked and `.env.example`
only *mentions* the variable in a comment (line 83), so an env-only fix has no repo-visible artifact
and can silently regress.

- [x] Change the **default** in `config/queue.php` to `7260` (worker timeout 7200 + 60 s grace), keep
      the `REDIS_QUEUE_RETRY_AFTER` env override, and declare the variable in `.env.example` with a
      comment stating the invariant (`retry_after` must exceed the worker `--timeout`).
- [x] Add an **invariant test** that parses `docker/production/supervisord.conf` for the `--timeout`
      value and asserts `config('queue.connections.redis.retry_after')` exceeds it. The invariant
      spans two files no single runtime reads together — only a test makes it visible.
- [x] If `TRANSCRIPTION_JOB_TIMEOUT` is ever raised past 7200 (see the `.env.example:83` comment),
      the test fails the build until `retry_after` is raised with it — that is the point.

### 0.2 — Fix the no-op sermon-disk space check (production validation gap)

[app/Services/Processing/SermonValidationService.php:189](../../app/Services/Processing/SermonValidationService.php#L189)
reads `config("filesystems.disks.{$disk}.root")` for the **sermon disk** — in production that is the
Spaces (S3) disk with no `root` key, so `$diskPath` is `null` and the check silently passes. The
genuinely scarce resource is the **local temp disk** (the known pipeline bottleneck — see
`hasTempDiskSpace()` at
[HistoricVideoImporter.php:929](../../app/Services/Media/Video/HistoricVideoImporter.php#L929)).

- [x] Repoint the validation check at the temp/local disk path so it tests the disk uploads actually
      consume. Read the threshold from one shared helper/config key so the importer guard and the
      validator agree (this also pre-bakes the P3 consolidation).
- [x] Add a test proving a full temp disk now **rejects** an upload (the old no-op path silently
      allowed this in production). Confirm existing upload tests still pass.

### 0.3 — Remove the accidentally committed `"\004"` file

`git ls-files` shows a tracked root-level file literally named `\004` (a control character). `file`
identifies it as an *OpenPGP Public Key, RSA 4096, created 2024-04-24* — the Ondřej PHP PPA key,
almost certainly a stray shell-redirect artifact. It entered in commit `d03e50c37` (PR #778, an
unrelated accessibility change) on 2026-06-08.

- [x] `git rm "$(printf '\004')"` — verify nothing references it (nothing should; the Dockerfiles
      fetch their own keys).
- [x] No further action; this is pure hygiene.

Exit criteria: `retry_after` default exceeds the worker timeout with a cross-file invariant test;
upload validation tests the real (temp) disk and a full disk rejects uploads; the `\004` file is gone.

---

## Track 1 — Structural findings (no new dependencies)

### Phase R1: Repository hygiene quick wins — ✅ Done

Findings 7, 8, 11, 12. **Status: Complete** (verified 2026-06-10).

Execution note: `.gitignore` no longer ignores the tracked lock files and now ignores `routes.json`
(untracked); `composer.json` is at `"php": "^8.4"`; `storage/scratch/` exists, is gitignored, and is
documented in `AGENTS.md` (§ Scratch); the repo root holds no stray `.sql`/`.mp4`/`.osz`/`.sqlite`
artifacts. One residual hygiene item R1 missed — the tracked `\004` key file — is now **Phase 0.3**.

### Phase R2: Relocate misplaced value objects and actions — ✅ Done

Finding 4. **Status: Complete** (verified 2026-06-10).

Execution note: the DTOs (`VideoProcessingOptions`, `ProcessingResult`, `ProcessingReport`,
`CalendarCategorizationResult`) live in `app/Data/`; `GetMediaProcessingStatus` lives in
`app/Actions/` alongside nine other actions. **Decision recorded:** `UnifiedMediaProcessor` was
evaluated and **kept as a service** — it now lives at
[app/Services/Processing/UnifiedMediaProcessor.php](../../app/Services/Processing/UnifiedMediaProcessor.php)
as a coordinator with service responsibilities, per the plan's "if it is genuinely a service, leave
it" branch.

### Phase R3: Reorganise `app/Services/` into domain namespaces — ✅ Done

Finding 1 (folds in Finding 9). **Status: Complete** (verified 2026-06-10).

Execution note: `app/Services/` is fully domain-namespaced (`Sermon/`, `Media/Audio|Video|Thumbnail/`,
`Processing/`, `Song/`, `ChurchService/`, `Public/`, `Scripture/`, `Preacher/`, `Calendar/`, `Email/`,
plus a new `Monitoring/` for the route-canary system added since the original plan). **Finding 9
resolved:** `app/Repositories/` is deleted; `SermonRepository` lives at
[app/Services/Public/SermonRepository.php](../../app/Services/Public/SermonRepository.php) in the
read-side cache namespace. Reference-fix lessons are captured in project memory
(r3-namespace-move-reference-cases).

### Phase R4: Sub-group `app/Data/` — ⏸️ Deferred indefinitely

Finding 10. **Status: Deferred — do not schedule.**

`app/Data/` is still flat (~45 DTOs), but with the service namespaces landed, a wholesale move is
mostly import churn for a cosmetic win. **Revised stance:** group DTOs into a domain subdirectory
only when a domain already being changed for other reasons would benefit — never as standalone work.

### Phase R5: Route-name standardisation — ✅ Done

Finding 5. **Status: Complete** (implemented 2026-06-10).

Execution note: the six outliers were renamed to lowercase dot-notation — `Home`→`home`,
`christmas`→`pages.christmas`, `christ`→`pages.christ`, `church`→`pages.church`,
`community`→`pages.community`, `memberHome`→`members.home`. Callers were updated in the only two
production files that referenced them by name ([SitemapService.php](../../app/Services/Public/SitemapService.php),
[AuthenticatedSessionController.php](../../app/Http/Controllers/Auth/AuthenticatedSessionController.php))
plus six test files. Nav links are hardcoded paths (`href="/christ"`) and route canaries probe by URL
path, so neither was affected. `verify-email` was converted from a view closure to `Route::view`;
`reset-password/{token}` stays a closure (it needs the token param), matching the other auth routes.
Pint clean, PHPStan 0 errors, 69 feature tests + 41 Dusk tests green.

Refreshed inventory (verified against [routes/web.php](../../routes/web.php), 2026-06-10):

- **Naming outliers (real):** `Home` (l.59), `christmas` (62), `christ` (65), `church` (68),
  `community` (69), `memberHome` (222). Two names the original plan listed are **not** outliers:
  `dashboard` resolves to `admin.dashboard` via its group prefix, and `index` resolves to
  `church.songs.index` — both conventional; leave them.
- **Logic-bearing closures: 4, not 8** (the rest are route *groups*). Two are trivial view closures —
  `reset-password/{token}` (l.146, needs the token param) and `verify-email` (l.150, convertible to
  `Route::view`). Two are local-only dev routes inside the `isLocal()` block — `500` (l.246) and
  `phpinfo` (l.252) — which should **stay as closures**; converting dev-only throwaways to
  controllers is ceremony.

Tasks:

- [x] Rename the six outliers to lowercase dot-notation (`home`, `pages.christmas`, `pages.christ`,
      `pages.church`, `pages.community`, `members.home` — agree exact names first). For **every**
      rename, grep `route('OldName')` across `app/`, `resources/views/`, `tests/`, and Livewire
      `#[Url]`/redirects.
- [x] Convert `verify-email` to `Route::view`; keep `reset-password/{token}` as a closure or move the
      token-passing into a view composer — either is fine, just be consistent.
- [x] Run **Dusk** in addition to the unit/feature suite — route renames most often break browser
      navigation and `wire:navigate` links.

Exit criteria: the six outliers follow the convention; no avoidable logic closures remain; full
suite + Dusk green.

### Phase R6: Complexity hotspot decomposition + typed DTOs — ✅ Done (importer target deferred)

Findings 2 and 3. **Priority: Medium** (high value, high effort — do opportunistically when each file
is next touched, not as a big bang). Extends SIMPLIFICATION-PLAN Phase 14.

Execution note (2026-06-10, three independent PRs):

- **SermonController** (PR #784) — the inline archive SEO strings (preacher, series, service) moved
  onto `SermonArchiveSeoPresenter`, including the service-label `match`; all four archive sub-types
  are now presenter-sourced and the strings have presenter-level tests.
- **Sermon model** (PR #785) — all 19 query scopes extracted to
  [app/Models/Builders/SermonBuilder.php](../../app/Models/Builders/SermonBuilder.php) (the
  project's first dedicated Eloquent builder, attached via `#[UseEloquentBuilder]`); the model
  dropped 875 → 634 lines. No heavy presentation accessors remained to push to the presenter —
  earlier phases had already moved them.
- **SongCatalogSyncService** (PR #786) — decomposed 1,436 → 410 lines plus three collaborators in
  `app/Services/Song/Sync/` (`OpenLpSongSourceReader`, `LegacySongReconciler`,
  `SongAuthorBookSyncer`). The dry-run/real duplication collapsed onto one pivot-row path (preview
  mode passes identity ID maps, preserving the historical dry-run counts exactly). **Finding 3:**
  the 15-key metrics array became the `SongCatalogSyncReport` spatie/laravel-data object; the
  five-table source shape became `OpenLpSongSourceData`.
- **HistoricVideoImporter — deferred, documented reason:** its fate is tied to
  SIMPLIFICATION-PLAN Phase 25 (legacy one-shot importers), which is awaiting the maintainer
  decision on whether the historic imports are complete. Decomposing a file that may be deleted
  outright is wasted effort; revisit only if Phase 25 resolves to *keep* it.

Each PR ran the four quality gates (Pint, PHPStan 0 errors, full parallel suite, Dusk) green.

Refreshed targets (post-R3 paths, sizes verified 2026-06-10):

- [app/Services/Song/SongCatalogSyncService.php](../../app/Services/Song/SongCatalogSyncService.php)
  (1,436 lines, dual real/dry-run paths) — still the clearest candidate.
- [app/Services/Media/Video/HistoricVideoImporter.php](../../app/Services/Media/Video/HistoricVideoImporter.php)
  — coordinate with SIMPLIFICATION-PLAN Phase 25 (legacy importers).
- [app/Models/Sermon.php](../../app/Models/Sermon.php) (875 lines) — extract query scopes to a
  dedicated builder; push heavy presentation accessors toward `SermonViewPresenter`.
- [app/Http/Controllers/SermonController.php](../../app/Http/Controllers/SermonController.php) —
  the inline archive SEO strings are **still present** (`'Sermons by '.$preacher->name` l.185–186,
  series description l.234). Extracting them onto `SermonArchiveSeoPresenter` (or a sibling) makes
  all four archive sub-types symmetric and lets T3's HTTP smoke tests for those pages thin to one
  wiring check each (see TESTING-REMEDIATION-PLAN / seo-assembly-layers memory).

Tasks (per target, only when the file is next touched for other reasons):

- [x] Identify the 3–4 distinct responsibilities and extract collaborators.
- [x] Collapse duplicated real-vs-dry-run logic onto one path with a commit/preview flag.
- [x] **Finding 3** — promote the most load-bearing `array{...}` shapes to `spatie/laravel-data`
      objects rather than expanding PHPDoc.
- [x] Keep behaviour identical; add focused tests for each extracted collaborator.

Exit criteria: no single service over ~500 lines without a documented reason; high-traffic array
shapes are typed DTOs; 0 PHPStan errors; full suite green.

---

## Track 2 — Tooling & decision-only items

### Phase D1: E2E framework strategy — ✅ Done

Finding 6. **Status: Complete** (implemented 2026-06-10).

The division of labour already exists in
[tests/Playwright/README.md](../../tests/Playwright/README.md): *"Dusk verifies interaction,
Playwright verifies visual output"* — option (a) from the original plan, chosen.

Execution note: the stance is now a "Browser-test division of labour: Dusk vs Playwright"
subsection under Testing Rules in `AGENTS.md`. **Trim audit result: nothing to trim.** All five
Playwright specs were reviewed against the Dusk suite; their only non-screenshot assertions are
navigation preconditions (finding a seeded link to capture) and the `aria-expanded` check in
`mobile-nav.spec.ts`, which doubles as the synchronisation wait before the screenshot — removing
it would just mean replacing it with an equivalent wait. No maintenance-costing duplication exists.

- [x] Copy that stance into `AGENTS.md` (testing section) so future UI tests have one obvious home
      without reading the Playwright README.
- [x] Trim functional assertions from Playwright specs where they duplicate Dusk coverage — only
      where the overlap actually costs maintenance; don't churn specs for symmetry.

Exit criteria: the stance is in `AGENTS.md`; no duplicated functional coverage that hurts.

### Phase D2: Livewire SFC stance — ✅ Done

Finding 13. **Status: Complete** (implemented 2026-06-10).

Execution note: the stance is now a "Livewire component format (deliberate stance)" subsection
under Frontend Conventions in `AGENTS.md` — existing components stay class + view; SFCs only for
new small leaf components; admin components default to class + view because of the
`WithAdminAuthorization` requirement.

- [x] Record an explicit stance in `AGENTS.md`: keep the existing class+view components as-is; adopt
      Livewire 4 single-file components only for **new small leaf components** if desired.
- [x] **Do not** mass-migrate existing components (high churn, low payoff).

Exit criteria: the stance is written down so it is a conscious choice, not drift.

---

## Track 3 — Package adoptions (each gated on explicit approval)

> `AGENTS.md` forbids changing dependencies without approval. **Each phase starts with a sign-off
> task; do not `composer require` until that box is ticked.**

Refreshed verdicts (the production-correctness fixes formerly buried in P1/P3 are now **Phase 0**
and do not wait for any approval):

| Phase | Package | Verdict | One-line rationale |
|-------|---------|---------|--------------------|
| **P2** | `spatie/laravel-backup` | ✅ **Done** (2026-06-10) | Lowest risk, purely additive. `mysqldump` is already in the image — prerequisite resolved. |
| **P1** | `laravel/horizon` | ✅ **Adopt** | Queue observability; its boot check also enforces the Phase 0.1 invariant permanently. |
| **P3** | `spatie/laravel-health` (+ `schedule-monitor`) | ✅ **Done** (2026-06-10) | Canaries folded in as a custom check (option a); `/health` is the single monitoring surface. |
| **P4** | `spatie/laravel-model-states` | ⏸️ **Defer** | Unchanged verdict; references updated to post-R3 paths. |
| **P5** | `laravel/ai` | ⏸️ **Defer** | Capability-gated (2026-06-18): tiny replaceable surface, single provider, no unmet capability — not the version. |

**Suggested adopt-now order: P2 → P1 → P3**, all independent of Track 1.

### Phase P1 — `laravel/horizon` (queue observability) · ✅ Adopt

**Status: Implemented 2026-06-10** (Horizon 5.47); the staging smoke test below is still
outstanding. Reversible (Horizon stores metrics in Redis only — no schema).
**Precondition: Phase 0.1 (`retry_after`) must land first** — Horizon refuses to boot when
`retry_after` ≤ `timeout`, so doing 0.1 independently keeps the deploy of this phase boring.

> **Execution note (2026-06-10):** the supervisor uses `balance => false` with
> `minProcesses = maxProcesses`, **not** `balance => 'simple'` as originally sketched. Current
> Horizon docs/source confirm `simple` splits the fixed process count evenly across queues
> (one pool per queue — `floor(2/6) = 0` workers each), while `false` passes the full queue
> list to every worker in strict priority order, exactly like the old
> `queue:work --queue=a,b,…`. Pinning min = max disables autoscaling. Also added
> `horizon:snapshot` every five minutes (production-only) so the metrics graphs populate, and
> re-pointed the `QueueWorkerCoverageTest` / `QueueRetryAfterInvariantTest` guards at
> `config/horizon.php` instead of parsing `queue:work` flags out of `supervisord.conf`.

The production worker is the `[program:queue-worker]` block in
[docker/production/supervisord.conf](../../docker/production/supervisord.conf):
`queue:work redis --queue=video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default
--sleep=3 --tries=3 --timeout=7200 --max-time=86400 --max-jobs=500 --memory=512`, `numprocs=2`.

Tasks:

- [x] **Approval gate.**
- [x] `composer require laravel/horizon`; `php artisan horizon:install`; commit `config/horizon.php`.
- [x] Define **one supervisor** mirroring the current worker exactly (connection `redis`, the six
      queues in priority order, `balance => false` + `minProcesses = maxProcesses = 2` — see
      execution note above, `tries => 3`,
      `timeout => 7200`, `memory => 512`, `sleep => 3`, `maxTime => 86400`, `maxJobs => 500`) so
      runtime behaviour does not change. Local dev currently splits video/livestream onto a second
      worker in `docker-compose.yml`; mirror that with a second supervisor later if wanted — start
      with one.
- [x] Replace the supervisord `queue:work` block with a single `php /var/www/html/artisan horizon`
      program (drop `numprocs`/`process_name` — Horizon manages its own children). Keep
      `stopwaitsecs` ≥ 7260 and `stopasgroup=true` so a deploy lets an in-flight 2 h job drain.
- [x] Gate the dashboard with the **existing admin check**: `HorizonServiceProvider::gate()` returning
      `fn ($user) => $user?->canAccessAdmin() === true` (the method
      [EnsureUserIsAdmin](../../app/Http/Middleware/EnsureUserIsAdmin.php) uses). Do not add
      `/horizon` to public navigation.
- [x] Add `php artisan horizon:terminate` to the deploy hook so new code is picked up.

Tests / verification:

- [x] Feature test: `/horizon` is 403 for guest/non-admin, 200 for admin.
- [→] Staging smoke test: one media-processing job end to end — appears in the dashboard, completes
      **once**, and a deliberately-failed job lands in Failed Jobs and is retryable from the UI.
      **Carved out (2026-06-29) into [docs/operations/horizon-staging-smoke-test.md](../operations/horizon-staging-smoke-test.md)** as a standalone ops task so this plan could be archived.

Rollback: revert the supervisord block to `queue:work`, remove the package. No data/schema changes.

Exit criteria: Redis queues run under Horizon with an admin-gated dashboard; no raw `queue:work`
worker remains in production; admin-gate test green.

### Phase P2 — `spatie/laravel-backup` (automated DB + file backups) · ✅ Done

**Status: ✅ Done** (approved and implemented 2026-06-10).

Execution note: `spatie/laravel-backup` v10.3 is installed with `config/backup.php` committed
(`mysqldump` 8.0.45 verified in the container). **One deliberate deviation from the plan:** backups
land on a new `do_spaces_backups` disk in [config/filesystems.php](../../config/filesystems.php) —
same bucket/credentials as `do_spaces`, but `private` visibility, a `backups/` root prefix, and
`throw => true` — because the sermon-serving disk is public-visibility/CDN-fronted and backup
archives must not inherit that. Scope: `storage/app/public`, `storage/app/private` (the *only*
copy of member-only sermon assets once `MoveSermonToPrivateStorage` pulls them off Spaces),
`google-calendar` credentials, and the spelling word list; `public/temp` and
`private/section-publications` (48 h-transient) excluded; DB dump gzipped with
`useSingleTransaction` so the nightly run does not lock live tables. Retention 7 all / 7 daily /
4 weekly / 3 monthly; AES encryption via `BACKUP_ARCHIVE_PASSWORD` (declared in `.env.example`);
failure-only mail to `LIVESTREAM_ADMIN_EMAIL`. Scheduled production-only with `onOneServer()`:
`backup:clean` 01:00, `backup:run` 01:30 `withoutOverlapping(120)`, `backup:monitor` 08:00.
Tests: [tests/Feature/Console/BackupRunCommandTest.php](../../tests/Feature/Console/BackupRunCommandTest.php)
(a real `backup:run --only-db` lands an **encrypted** zip on the backups disk; a failed run sends
`BackupHasFailedNotification`) plus a `SchedulerRegistrationTest` case for the three commands.
`GenerateProdSermonPatchCommand` untouched. **Remaining manual steps:** set
`BACKUP_ARCHIVE_PASSWORD` in production (and store a copy outside the backups), then restore-test
one dump from Spaces before trusting the schedule.

Refresh notes: the original "add default-mysql-client to the Dockerfile" task is **already done** —
[docker/8.4/Dockerfile](../../docker/8.4/Dockerfile) declares `ARG MYSQL_CLIENT="mysql-client"` (l.7)
and installs it (l.52). The stray root-level `.sql` dumps named in the original plan are already
gone (R1).

Tasks:

- [x] **Approval gate.**
- [x] Verify `which mysqldump` succeeds inside the production container (one-line check; the package
      shells out to it).
- [x] `composer require spatie/laravel-backup`; publish and commit `config/backup.php`.
- [x] Destination: the **existing Spaces disk** (`'destination.disks' => ['do_spaces']` from
      [config/filesystems.php](../../config/filesystems.php)), not the generic unused `s3` stub.
- [x] **Scope what gets backed up.** Sermon media already lives in Spaces — do **not** re-upload
      ~200 GB. Include the database plus *local persistent* storage only; exclude `vendor/`,
      `node_modules/`, `storage/app/temp`, `storage/app/livewire-tmp`, and Spaces-backed sermon paths.
- [x] Retention (e.g. 7 daily / 4 weekly / 3 monthly) and **encrypt** the archive
      (`backup.archive.password` from a new `BACKUP_ARCHIVE_PASSWORD` env var).
- [x] Notifications to the existing admin mailbox: `notifications.mail.to` →
      `env('LIVESTREAM_ADMIN_EMAIL')`. Keep `BackupHasFailed`, `CleanupHasFailed`,
      `UnhealthyBackupWasFound` enabled.
- [x] Schedule in [bootstrap/app.php](../../bootstrap/app.php) `->withSchedule()`, production-only:
      `backup:clean` daily 01:00, `backup:run` daily 01:30 `withoutOverlapping(120)`, both
      `onOneServer()->environments(['production'])`, matching existing entries' style.
- [x] **Leave `GenerateProdSermonPatchCommand` alone.** It reads a *raw* `storage/app/backup.sql`
      ([GenerateProdSermonPatchCommand.php:434](../../app/Console/Commands/GenerateProdSermonPatchCommand.php#L434));
      `backup:run` produces an *encrypted zip*. Different formats for different purposes.
- [x] Restore-test the DB dump from Spaces **once** before trusting the schedule.

Tests / verification:

- [x] `backup:run` against a non-prod bucket (or local disk) → zip lands; `backup:list` shows healthy.
- [x] Force a failure (bad disk creds) → admin email fires (`Mail::fake()` feature test or staging run).

Exit criteria: encrypted DB + local-files backups land in Spaces on a schedule with retention and
failure alerts; manual `.sql` dump workflow retired.

### Phase P3 — `spatie/laravel-health` (+ `laravel-schedule-monitor`) · ✅ Done

**Status: ✅ Done** (approved and implemented 2026-06-10).

Execution note: `spatie/laravel-health` v1.40 + `spatie/laravel-schedule-monitor` v4.3 installed,
with both packages' migrations (health result history + monitored-task tables). **Canary decision:
(a) taken** — the probes moved to `app/Services/Monitoring/RouteCanaryProber.php` and are wrapped by
`RouteCanariesCheck`, so `/health` is the single surface; `CheckRouteCanariesCommand`, the
`RouteCanaryFailure` mailable, and the per-URL cooldown logic are deleted (health throttles
notifications at 60 min), while `monitoring:canaries` (the deploy-time manifest) and the registry
stay. Checks live in `app/Services/Monitoring/Checks/` and are registered in
`HealthCheckServiceProvider`: Database, Redis, RedisMemoryUsage, Cache, **Horizon — a deliberate
deviation from the planned QueueCheck**, whose default-queue heartbeat would false-alarm whenever
two long video jobs occupy both strict-priority workers; `TempDiskSpaceCheck` (reads the shared
`TempDiskSpace` helper — fails at the floor where uploads are rejected, warns at twice it); a custom
`ScheduledTasksCheck` (schedule-monitor ships no health check — reports failed-last-run and
overdue-past-grace tasks, so a dead scheduler alerts); and `RouteCanariesCheck` (skipped in local
dev, where its self-directed probes would deadlock `artisan serve`). `health:check` is scheduled
**every five minutes** — the canaries' old cadence — rather than per-check frequencies, because
not-due checks store an explicit `skipped` result instead of carrying their last status.
`model:prune` runs daily for both history models. Failure-only mail goes to `HEALTH_TO_ADDRESS`
(defaults to `LIVESTREAM_ADMIN_EMAIL`); `/health` sits behind `auth`+`verified`+`admin`; `/up` is
untouched. The deploy hook runs `schedule-monitor:sync` after `up -d`, and the post-deploy smoke
script now greps for `health:check`/`model:prune` instead of the retired command. The dead
`DiskSpaceWarning` trio is deleted as approved. All four quality gates green (5,455 tests + 41 Dusk).

The premise had shifted twice since 2026-06-03:

1. The **temp-disk validation bug fix is no longer part of this phase** — it is Phase 0.2 and ships
   without any package. What remains here is consolidation of *monitoring*, not the bug fix.
2. The project has since built a **bespoke route-canary monitoring system**:
   [app/Services/Monitoring/RouteCanaryRegistry.php](../../app/Services/Monitoring/RouteCanaryRegistry.php),
   `CheckRouteCanariesCommand` / `ListRouteCanariesCommand`,
   [app/Data/RouteCanary.php](../../app/Data/RouteCanary.php), and a
   [RouteCanaryFailure](../../app/Mail/RouteCanaryFailure.php) mailable, scheduled every five minutes
   ([bootstrap/app.php:41](../../bootstrap/app.php#L41)). Adopting laravel-health while leaving
   canaries bespoke would create exactly the two-monitoring-surfaces fragmentation this phase set
   out to fix — so the phase now starts with that decision.

Still true (re-verified 2026-06-10): [app/Mail/DiskSpaceWarning.php](../../app/Mail/DiskSpaceWarning.php)
and its view/test are **dead code** — the mailable is never dispatched anywhere in `app/`.

Tasks:

- [x] **Approval gate.**
- [x] **Decide the canary relationship first:** either (a) wrap the route canaries as a custom
      laravel-health check so `/health` is the single surface and `RouteCanaryFailure` mail is
      replaced by health notifications, or (b) keep canaries separate (they are *external-behaviour*
      probes, health checks are *internal-resource* probes) and document the boundary. Default
      recommendation: **(a)** — one alerting pipeline, one notification target.
- [x] `composer require spatie/laravel-health spatie/laravel-schedule-monitor`; publish config; run
      the `schedule-monitor` migration.
- [x] Register checks: `DiskSpaceCheck` on the **local temp disk** (reusing the Phase 0.2 shared
      threshold helper), `DatabaseCheck`, `RedisCheck`, `RedisMemoryUsageCheck`, `CacheCheck`,
      `QueueCheck`.
- [x] Notifications: mail failing checks to `LIVESTREAM_ADMIN_EMAIL` (same notifiable approach as P2).
- [x] **Delete the dead bespoke trio:** `app/Mail/DiskSpaceWarning.php`, its Blade view, and
      `tests/Unit/Mail/DiskSpaceWarningTest.php`. Per the AGENTS.md test-file policy this deletion
      needs sign-off — covered by this approved phase; call it out explicitly in the PR.
- [x] Keep the behaviour-gating guards (upload-time rejection, importer `[skip-low-disk]`) — they are
      control flow, not monitoring — reading the shared threshold helper.
- [x] `schedule-monitor`: register the **five** scheduled commands in `bootstrap/app.php`
      (`calendar:sync`, `media:cleanup-temp-files`, `media:cleanup-unpublished-section-assets`,
      `scripture:refresh-passages`, **`monitoring:check-canaries`**) plus the P2 `backup:*` tasks.
      Run `schedule-monitor:sync` in the deploy hook.
- [x] Expose `/health` behind the `admin` middleware; `/up` stays as the boot-only load-balancer probe.

Tests / verification:

- [x] Feature test: the health endpoint returns the registered checks; a stubbed over-threshold disk
      marks the result unhealthy.

Exit criteria: one health surface (disk / DB / Redis / queue, and canaries if folded in) with
admin-routed alerts; the dead `DiskSpaceWarning` trio deleted; scheduled-task failures (including
backups and canary checks) alert.

### Phase P4 — `spatie/laravel-model-states` (state machines) · ⏸️ Defer

**Status: Deferred — verdict unchanged; file references updated to post-R3 paths.**

The logic this would replace is small, centralised, and tested:
[app/Services/Processing/MediaProcessingRunTransitionService.php](../../app/Services/Processing/MediaProcessingRunTransitionService.php)
(clear `markAsX()` methods with a single cancellation guard),
[app/Services/Processing/SermonProcessingStepTransitions.php](../../app/Services/Processing/SermonProcessingStepTransitions.php),
[app/Services/ChurchService/ServiceSectionPublicationTransitionService.php](../../app/Services/ChurchService/ServiceSectionPublicationTransitionService.php).
Model-states would swap enum casts for state classes across every call site — high churn for
consolidation, not capability.

**Trigger to revisit:** a *third* place starts enforcing the same transition rules, or a new
lifecycle appears with complex per-transition side effects. If triggered, pilot on the
media-processing run lifecycle only (`MediaProcessingLog` + its transition service), keep the
`ProcessingStatus` string values identical for DB compatibility, preserve the manual-review-pending
semantics (status `Failed` + `current_step=manual_review_required` + cleared `dedup_key`), and
require the media-processing suite to pass unchanged. Record a written go/no-go before converting
any of the other machines.

### Phase P5 — `laravel/ai` (Laravel AI SDK) · ⏸️ Defer

**Status: Deferred — capability-gated, not version-gated.**

The deferral does **not** hinge on the version number. The previous framing ("revisit at ≥ 1.0")
was a weak proxy: Laravel's docs encourage `laravel/ai` while Packagist still lists a pre-1.0
release (v0.8.1, 2026-06-10), so the version signal contradicts itself and shouldn't drive the call.
What actually decides it is **replaceable surface vs. capability gained**, and today both point to
*wait*:

- **The surface it would replace is tiny.** The hand-rolled "SDK" is essentially the 44-line
  [AiServiceProvider](../../app/Providers/AiServiceProvider.php) (provider binding + fake layer).
  The bulk of the AI code — `SermonAnalysisPromptBuilder`, `OpenAIResponseLogger`,
  `SongLyricOcrService`, `SpeechSectionClassificationService`, the JSON-schema/response handling —
  is **domain logic that stays whichever way we go**; `laravel/ai` does not replace it.
- **Its headline value is the provider abstraction, and we have one provider.** Every AI call site
  is OpenAI. An abstraction over a single provider abstracts nothing yet.
- **Pre-1.0 means no backward-compat promise** (breaking changes land in *minor* releases), so
  adopting now means riding churn to delete ~44 lines — risk without capability.

The work is isolated behind three contracts
([SermonAnalysisInterface](../../app/Contracts/SermonAnalysisInterface.php),
[TranscriptionServiceInterface](../../app/Contracts/TranscriptionServiceInterface.php),
[OosEmailItemExtractor](../../app/Contracts/OosEmailItemExtractor.php)), so deferring costs nothing.

**Triggers to revisit (any one):**

1. A **second AI provider or failover** requirement appears — the provider abstraction finally has a
   job to do.
2. A capability we'd otherwise **hand-build** is needed (agent/tool-call orchestration, streaming).
3. The **bespoke fake layer** becomes a genuine test-maintenance burden.

A stable (≥ 1.0) release is a *nice-to-have precondition* once one of the above fires — not a trigger
on its own. First seam when triggered: reimplement
[app/Services/Email/OpenAiOosEmailItemExtractor.php](../../app/Services/Email/OpenAiOosEmailItemExtractor.php)
(the smaller, lower-traffic seam) as a structured-output agent behind its existing contract; swap its
tests to the SDK fake layer; do sermon analysis second once the pattern is proven. Do not touch the
thumbnail pipeline, voice fingerprinting, or vector search (MySQL, no pgvector).

### Phase P6 — Consider / Skip tier (one verdict re-framed)

- `spatie/laravel-csp` — **re-framed: the question is no longer "adopt a CSP?" — a bespoke CSP
  already exists.** [SecurityHeaders.php:65–85](../../app/Http/Middleware/SecurityHeaders.php#L65)
  ships `Content-Security-Policy` with `'unsafe-inline' 'unsafe-eval'` in `script-src` (required by
  Livewire/Alpine without nonce wiring). The real decision is **harden the existing middleware with
  nonces vs replace it with spatie/laravel-csp** (which provides the nonce plumbing). **Trigger:** a
  security review or hosting requirement demands removing `unsafe-inline`/`unsafe-eval`, or
  third-party embeds are added. When triggered, prefer the package — hand-rolling nonce propagation
  through Livewire/Alpine is exactly the work it exists to do.
- `spatie/laravel-activitylog` — **defer.** Trigger: more than a couple of admins, or a "who changed
  this sermon?" accountability requirement. Then add `LogsActivity` to the CRUD models — additive,
  low risk.
- `laravel/pennant` — **skip.** Config toggles win for a single-tenant app with no per-user rollout.
  Trigger: per-user flags or percentage ramps.
- `spatie/laravel-permission` — **skip.** The binary admin model is deliberate and pinned by a test.
- `spatie/laravel-query-builder` — **skip.** Filtering lives in stateful Livewire components, not
  request-string APIs.
- `laravel/telescope` — **skip.** Overlaps Debugbar + Pail (and Horizon once P1 lands).

---

## Suggested Order

1. **Phase 0** (correctness fixes) — immediately; no approvals needed.
2. **Phase D1 residual + D2** (write the two stances into `AGENTS.md`) — cheap, any time.
3. **Phase R5** (route naming) — deliberate sweep with Dusk.
4. **Phase R6** (hotspot decomposition + DTOs) — opportunistic, whenever those files are next touched.
5. **Track 3**: P2 → P1 → P3 after approval. P4/P5 deferred with named triggers; P6 is decisions-only.

R1–R3, R5, R6, D1, D2, P1, P2, and P3 are done (R6's importer target deferred behind
SIMPLIFICATION-PLAN Phase 25); R4 is deferred indefinitely. The only open item in Track 3 is P1's
outstanding staging smoke test; P4/P5 stay deferred with named triggers.

## Definition of Done

- Phase 0 fixes are live with their tests (the `retry_after` invariant test is the lasting artifact).
- Remaining structural phases (R5, R6) leave the four quality gates green.
- Decision items (D1 stance copy, D2) are written into `AGENTS.md`.
- Each *adopted* package phase (P2, P1, P3) is behind its approval gate and **replaces** the bespoke
  code it supersedes (for P3 that now explicitly includes deciding the route-canary relationship).
  Each *deferred* phase (R4, P4, P5) and the P6 tier carries a written verdict + re-open trigger.
- This plan moves to [docs/archived-plans/](../archived-plans/) once all in-scope phases are done or
  consciously deferred, with a short execution log (the ✅ execution notes above are its start).
  **Archived 2026-06-29** — the only residual action, P1's staging smoke test, is now tracked
  separately in [docs/operations/horizon-staging-smoke-test.md](../operations/horizon-staging-smoke-test.md).
