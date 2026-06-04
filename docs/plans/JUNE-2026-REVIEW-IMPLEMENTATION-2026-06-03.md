# June 2026 Review — Implementation Plan

Created 2026-06-03. Implements the findings in
[docs/reviews/project-wide-improvement-review-2026-06-03.md](../reviews/project-wide-improvement-review-2026-06-03.md).

This plan turns that review's 13 structural findings plus the package-opportunities section into
sequenced, checkable work. It is ordered by **risk and dependency**, not by finding number: safe
mechanical work first, then preparation before sweeps, then the higher-churn refactors, with the
package adoptions on a separate approval-gated track.

Where work overlaps an existing plan it is **referenced, not duplicated** — see
[docs/plans/SIMPLIFICATION-PLAN.md](SIMPLIFICATION-PLAN.md) (Phase 14 complexity hotspots, Phase 20
`Repositories/` reframe, Phase 25 legacy importers).

## Scope

- Improve **structure, standardisation, and simplification** without changing behaviour: Services
  organisation, misplaced classes, route naming, repository hygiene, and the complexity hotspots.
- Capture the **package-adoption** opportunities as discrete, approval-gated phases (no dependency
  is added in this plan without explicit sign-off, per `AGENTS.md`).
- Explicitly record the **decision-only** items (E2E framework strategy, Livewire SFC stance) so the
  non-actions are conscious choices.

Out of scope: any production behaviour change, any new feature, and any dependency install before
its phase is approved.

## Quality Gates

Run for every phase that touches PHP, before finalising:

- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail composer phpstan` — must stay at 0 errors.
- `vendor/bin/sail artisan test --parallel --compact` (or a focused filter for small phases).
- `vendor/bin/sail artisan dusk` — for any phase that touches public routes, the upload form, or
  admin Livewire screens.

Phase-specific guard rails are called out per phase below. The CI gates in
[.github/workflows/pr.yml](../../.github/workflows/pr.yml) (Pint, PHPStan, typing baseline, schema
dump freshness, core suite) back-stop every change.

---

## Track 1 — Structural findings (no new dependencies)

### Phase R1: Repository hygiene quick wins

Findings 7, 8, 11, 12. **Priority: High** (near-zero risk, builds momentum). **Status: Pending.**
Ship as one small PR.

Target files:

- [.gitignore](../../.gitignore) — Finding 7.
- [routes.json](../../routes.json) — Finding 8 (tracked generated artifact).
- [composer.json](../../composer.json) — Finding 11.

Tasks:

- [ ] **Finding 7** — Remove the `composer.lock` and `package-lock.json` lines from the `# Lock
      files` block in `.gitignore` (both files are already tracked; the ignore entry is a footgun).
      Confirm with `git check-ignore composer.lock` returning nothing afterward.
- [ ] **Finding 8** — `git rm --cached routes.json`, add `routes.json` to `.gitignore`. Confirm
      nothing in the app reads it (`grep -r routes.json app config` → only tooling).
- [ ] **Finding 11** — Bump `"php"` from `^8.3.0` to `^8.4` in `composer.json` to match the platform
      (`application-info` reports PHP 8.4) and the Boost guidelines. Run `composer update --lock`
      (no package changes — lock platform only) and re-run PHPStan to pick up 8.4 assumptions.
- [ ] **Finding 12** — Create a gitignored `storage/scratch/` (with a `.gitignore` keep-file) and
      document it in `AGENTS.md` as the home for local dumps/media. Move or delete the root-level
      strays (`*.mp4`, `*.osz`, `*.sqlite`, `*.sql`, `TapeIndex.csv`, `test-results/`). These are
      already gitignored — this is tidy-up, not a tracking change.

Exit criteria:

- `.gitignore` no longer contradicts the tracked lock files; `routes.json` is untracked and ignored.
- `composer.json` PHP constraint matches the deployed platform; PHPStan still at 0 errors.
- Repo root contains no large stray artifacts.

### Phase R2: Relocate misplaced value objects and actions (prep for R3)

Finding 4. **Priority: High** (must precede R3 to avoid moving classes twice). **Status: Pending.**

Target files (move out of `app/Services/`):

- [app/Services/VideoProcessingOptions.php](../../app/Services/VideoProcessingOptions.php) → `app/Data/`
- [app/Services/ProcessingResult.php](../../app/Services/ProcessingResult.php) → `app/Data/`
- [app/Services/ProcessingReport.php](../../app/Services/ProcessingReport.php) → `app/Data/`
- [app/Services/CalendarCategorizationResult.php](../../app/Services/CalendarCategorizationResult.php) → `app/Data/`
- [app/Services/GetMediaProcessingStatus.php](../../app/Services/GetMediaProcessingStatus.php) → `app/Actions/` (read action)
- [app/Services/UnifiedMediaProcessor.php](../../app/Services/UnifiedMediaProcessor.php) → `app/Actions/` (coordinator) — **verify** it has no service-locator responsibilities first; if it is genuinely a service, leave it and note why.

Tasks:

- [ ] For each class above, confirm its role (value object / result / action) by reading it before
      moving — do not move on the basis of the name alone.
- [ ] Move file + update namespace (`App\Services\X` → `App\Data\X` / `App\Actions\X`).
- [ ] Update all `use` imports and any string references (`grep -rn 'Services\\ClassName' app tests config`).
- [ ] Run PHPStan + the full suite — these catch every missed reference.

Exit criteria:

- `app/Services/` contains only behavioural service classes; DTOs live in `Data/`, actions in `Actions/`.
- 0 PHPStan errors; full suite green.

### Phase R3: Reorganise `app/Services/` into domain namespaces

Finding 1 (folds in Finding 9). **Priority: Medium-High.** **Status: Pending.**
Do **one domain cluster per PR** to keep diffs reviewable. The clusters below come from the review's
prefix analysis (128 files → domains).

Proposed namespaces:

- `App\Services\Sermon\` (~19)
- `App\Services\Media\Audio\`, `App\Services\Media\Video\`, `App\Services\Media\Thumbnail\` (~17)
- `App\Services\Processing\` (~9)
- `App\Services\Song\` (~13, incl. OpenLP parsers)
- `App\Services\ChurchService\` (~8) — note an existing `App\Services\SectionPublication\` subdir to fold in
- `App\Services\Public\` (~5: sitemap, podcast, read-model caches)
- `App\Services\Scripture\` (~3)
- `App\Services\Preacher\` (~6, incl. speaker identification)
- `App\Services\Calendar\`, `App\Services\Email\` (~4)

Tasks (repeat per cluster):

- [ ] Decide the cluster boundary; list its files.
- [ ] `git mv` each file into the new subdirectory; update its namespace.
- [ ] Update `use` imports across `app/`, `tests/`, `config/`, `routes/`, and any
      `app/Providers/*ServiceProvider.php` bindings (the provider DI bindings are the easiest to
      miss — grep them explicitly).
- [ ] **Finding 9** — fold this in: finish [SIMPLIFICATION-PLAN.md](SIMPLIFICATION-PLAN.md) Phase 20
      by moving [app/Repositories/SermonRepository.php](../../app/Repositories/SermonRepository.php)
      into the read-side cache namespace (it is a query+cache hybrid) and deleting the now-empty
      `app/Repositories/` directory — **or** record an explicit decision to keep the Repository
      pattern. Do not leave it as a lone residual file.
- [ ] PHPStan + full suite after each cluster PR.

Exit criteria:

- `app/Services/` top level holds only orchestration-level classes (or is empty of leaf domain
  services); each domain is a discoverable namespace.
- `app/Repositories/` is resolved (moved or deliberately documented).
- 0 PHPStan errors; full suite green after every cluster.

### Phase R4: Sub-group `app/Data/`

Finding 10. **Priority: Low** (cosmetic; do only if R3 lands). **Status: Pending.**

Target: [app/Data/](../../app/Data/) (45 flat DTOs).

Tasks:

- [ ] Mirror the R3 domain subdirectories under `Data/` (e.g. `Data/Processing/`, `Data/Sermon/`,
      `Data/ChurchService/`) so a domain's services and DTOs share a shape.
- [ ] Move + renamespace + fix imports; PHPStan + suite green.

Exit criteria: `Data/` domain grouping matches `Services/`. 0 PHPStan errors.

### Phase R5: Route-name standardisation

Finding 5. **Priority: Medium** (breaking for `route()` callers — do as a deliberate sweep).
**Status: Pending.**

Target files:

- [routes/web.php](../../routes/web.php) — TitleCase/bare outliers (`Home`, `memberHome`, `christ`,
  `church`, `community`, `christmas`, `index`, `dashboard`) and 8 inline closures.

Tasks:

- [ ] Agree the convention: lowercase dot-notation (`home`, `members.home`, `pages.christ`, …).
- [ ] Rename each outlier route; for **every** rename, grep `route('OldName')` /
      `@route`/`->route(` across `app/`, `resources/views/`, `tests/`, and Livewire `#[Url]`/redirects.
- [ ] Convert view-only closures to `Route::view()`; convert logic-bearing closures to
      single-action controllers in `app/Http/Controllers/`.
- [ ] Run **Dusk** (navigation/links) in addition to the unit/feature suite — route renames most
      often break browser navigation and `wire:navigate` links.

Exit criteria:

- All route names follow one convention; no inline logic closures remain in `web.php`.
- Full suite + Dusk green.

### Phase R6: Complexity hotspot decomposition + typed DTOs

Findings 2 and 3. **Priority: Medium** (high value, high effort — do opportunistically, not as a
big bang). **Status: Pending.** Extends [SIMPLIFICATION-PLAN.md](SIMPLIFICATION-PLAN.md) Phase 14.

Primary targets (largest / highest-churn first):

- [app/Services/SongCatalogSyncService.php](../../app/Services/SongCatalogSyncService.php) (1,436
  lines, 41 methods, dual real/dry-run paths) — the clearest candidate.
- [app/Services/HistoricVideoImporter.php](../../app/Services/HistoricVideoImporter.php) (1,138) —
  coordinate with [SIMPLIFICATION-PLAN.md](SIMPLIFICATION-PLAN.md) Phase 25 (legacy importers).
- [app/Models/Sermon.php](../../app/Models/Sermon.php) (864) — extract query scopes to a dedicated
  builder; push heavy presentation accessors toward `SermonViewPresenter`.
- [app/Http/Controllers/SermonController.php](../../app/Http/Controllers/SermonController.php)
  (~lines 180–250) — extract the inline preacher/series/service archive SEO strings into a presenter.
  **Surfaced by the T3 SEO test re-levelling** (see
  [docs/plans/TESTING-REMEDIATION-PLAN.md](TESTING-REMEDIATION-PLAN.md)). The main `index` action
  already delegates archive title/description/canonical to
  [SermonArchiveSeoPresenter](../../app/Seo/SermonArchiveSeoPresenter.php), but the `preacher`,
  `series`/`seriesShow`, and `service` actions still build their `heading`/`description` strings inline
  (e.g. `'Sermons by '.$preacher->name`, `'Browse all sermons in the "..."'`). Because those strings
  have no producing layer below HTTP, T3 had to keep them as HTTP smoke tests rather than cheap
  presenter unit tests. Extracting them (e.g. onto `SermonArchiveSeoPresenter` or a sibling presenter)
  would make all four archive sub-types symmetric, thin the controller, and let the title/description
  *variants* be unit-tested the way the filtered archive already is — at which point T3's smoke tests
  for those pages can be thinned to one wiring check each.

Tasks (per target, only when the file is next touched for other reasons):

- [ ] Identify the 3–4 distinct responsibilities (e.g. source reading / matching policy /
      persistence / metrics) and extract collaborators.
- [ ] Collapse duplicated real-vs-dry-run logic onto one path with a commit/preview flag.
- [ ] **Finding 3** — promote the most load-bearing `array{...}` shapes to `spatie/laravel-data`
      objects (the project already has 45 such DTOs) rather than expanding PHPDoc.
- [ ] Keep behaviour identical — lean on the existing tests; add focused tests for each extracted
      collaborator.

Exit criteria:

- No single service over ~500 lines without a documented reason; dual-path duplication removed.
- High-traffic array shapes are typed DTOs. 0 PHPStan errors; full suite green.

---

## Track 2 — Tooling & decision-only items

### Phase D1: E2E framework strategy decision

Finding 6. **Priority: Medium.** **Status: Pending — decision required.**

The repo runs **both** Dusk (`tests/Browser/`, functional) and Playwright (`tests/Playwright/`,
visual regression) with overlapping homepage/nav/sermons coverage.

Tasks:

- [ ] Decide the division of labour: either (a) Dusk = functional only, Playwright = pixel snapshots
      only (remove the functional overlap from Playwright specs), or (b) consolidate on Playwright
      for both and retire the overlapping Dusk specs.
- [ ] Record the decision in `AGENTS.md` so future UI tests have one obvious home.
- [ ] If consolidating, remove the redundant specs and the now-unused CI env file
      ([.env.dusk.ci](../../.env.dusk.ci) or [.env.playwright.ci](../../.env.playwright.ci)).

Exit criteria: one documented home per browser-assertion type; no duplicated functional coverage.

### Phase D2: Livewire SFC stance (decision-only — no migration)

Finding 13. **Priority: Low.** **Status: Pending — decision required.**

Tasks:

- [ ] Record an explicit stance in `AGENTS.md`: keep the 47 class+view components as-is; adopt
      Livewire 4 single-file components only for **new small leaf components** if desired.
- [ ] **Do not** mass-migrate existing components (high churn, low payoff).

Exit criteria: the stance is written down so it is a conscious choice, not drift.

---

## Track 3 — Package adoptions (each gated on explicit approval)

> `AGENTS.md` forbids changing dependencies without approval. **Each phase below starts with a
> sign-off task; do not `composer require` until that box is ticked.**

**Recommendation (read this first).** After reviewing the actual code each candidate would touch, the
verdicts are below. Three are worth adopting now; two are real but should wait. Every phase is written
so you can either execute it top-to-bottom or skip it outright — no further investigation required.

| Phase | Package | Verdict | One-line rationale |
|-------|---------|---------|--------------------|
| **P2** | `spatie/laravel-backup` | ✅ **Adopt — do first** | Lowest risk, purely additive, retires the manual `.sql` dumps. No application code changes. |
| **P1** | `laravel/horizon` | ✅ **Adopt** | Strong fit; also forces a fix to a latent `retry_after` < job-`timeout` bug (see P1 step 2). Fully reversible. |
| **P3** | `spatie/laravel-health` (+ `schedule-monitor`) | ✅ **Adopt** | Replaces *dead* and *no-op* bespoke disk-space code with one real check + scheduled-task alerting — a tidy-up that is also a bug fix. |
| **P4** | `spatie/laravel-model-states` | ⏸️ **Defer** | Highest churn; the current transition services are small, tested, and working. Spec retained as an optional pilot. |
| **P5** | `laravel/ai` (AI SDK) | ⏸️ **Defer** | Strong strategic fit, but the package is freshly released and the existing AI seam is isolated and tested. Revisit at a stable (≥ 1.0) release. |

**Suggested adopt-now order: P2 → P1 → P3.** All three are independent of the Track 1 refactors and can
land any time. Do P2 first (it cannot break anything), then P1, then P3. P4/P5 are deferred — their
specs are kept below so the decision is informed, not so they are built now.

### Phase P1 — `laravel/horizon` (queue observability) · ✅ Adopt

**Status: Pending approval.** Strong fit; reversible (Horizon stores metrics in Redis only — no schema).
**Heads-up: this phase also fixes a latent timeout bug — step 2.**

Where the worker actually lives (the plan previously mis-stated this): the production worker is **not**
in `docker-compose.prod.yml`. It is the `[program:queue-worker]` block in
[docker/production/supervisord.conf](../../docker/production/supervisord.conf), running:

```
php artisan queue:work redis \
  --queue=video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default \
  --sleep=3 --tries=3 --timeout=7200 --max-time=86400 --max-jobs=500 --memory=512
process_name=...  numprocs=2
```

Tasks:

- [ ] **Approval gate.**
- [ ] **Fix `retry_after` first (latent bug, worth doing even if Horizon is skipped).**
      [config/queue.php](../../config/queue.php) sets the `redis` connection's `retry_after` to
      `REDIS_QUEUE_RETRY_AFTER` **default 3600**, but the worker `--timeout` is **7200**. A job running
      1–2 h can be released and **picked up a second time while still running** (duplicate FFmpeg /
      transcription). Set `REDIS_QUEUE_RETRY_AFTER=7260` in `.env.production` (and document it in
      `.env.example`) so `retry_after` (7260) > Horizon `timeout` (7200). Horizon refuses to boot if this
      invariant is violated — which is the safety net that makes the bug visible.
- [ ] `composer require laravel/horizon`; `php artisan horizon:install`; commit `config/horizon.php`.
- [ ] In `config/horizon.php`, define **one supervisor** mirroring the current worker exactly so runtime
      behaviour does not change:
      ```php
      'environments' => [
          'production' => [
              'supervisor-media' => [
                  'connection' => 'redis',
                  'queue' => ['video-processing', 'audio-processing', 'sermon-processing',
                              'livestream-processing', 'speaker-identification', 'default'],
                  'balance' => 'simple',  // preserve numprocs=2; no autoscaling surprises
                  'processes' => 2,
                  'tries' => 3,
                  'timeout' => 7200,
                  'memory' => 512,
                  'sleep' => 3,
                  'maxTime' => 86400,
                  'maxJobs' => 500,
              ],
          ],
          'local' => [ /* single process, same queue list */ ],
      ],
      ```
      Queue *priority* is preserved by list order under `balance => 'simple'`. (Local dev currently splits
      video/livestream onto a second worker in [docker-compose.yml](../../docker-compose.yml); you can
      mirror that with a second supervisor later, but start with one to keep the change minimal.)
- [ ] Replace the `[program:queue-worker]` block in
      [docker/production/supervisord.conf](../../docker/production/supervisord.conf) with a single
      `php /var/www/html/artisan horizon` program (Horizon manages its own children — drop
      `numprocs`/`process_name`). Keep `stopwaitsecs` ≥ 7260 and `stopasgroup=true` so a deploy lets an
      in-flight 2 h job drain; Horizon finishes the current job on `SIGTERM` then exits.
- [ ] Gate the dashboard with the **existing admin check**: add a `HorizonServiceProvider` whose `gate()`
      returns `fn ($user) => $user?->canAccessAdmin() === true` (the method used by
      [EnsureUserIsAdmin](../../app/Http/Middleware/EnsureUserIsAdmin.php)). Register the provider. Do not
      add `/horizon` to public navigation.
- [ ] Add `php artisan horizon:terminate` to the deploy hook in
      [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml) (or the container entrypoint) so
      new code is picked up — Supervisor restarts Horizon automatically afterwards.

Tests / verification:

- [ ] Feature test: `/horizon` is **403 for a guest / non-admin** and **200 for an admin** (mirror the
      existing admin-authorisation tests).
- [ ] Staging smoke test: run one media-processing job end to end; confirm it appears in the dashboard,
      completes **once** (no duplicate run), and that a deliberately-failed job lands in **Failed Jobs**
      and can be retried from the UI.

Rollback: revert the supervisord block to `queue:work`, remove the package. No data/schema changes.

Exit criteria: Redis queues run under Horizon with an admin-gated dashboard; `retry_after` > `timeout`;
no raw `queue:work` worker remains in production; admin-gate test green.

### Phase P2 — `spatie/laravel-backup` (automated DB + file backups) · ✅ Adopt (do first)

**Status: Pending approval.** Lowest-risk option in Track 3 — purely additive, touches no application
code, fully reversible by removing the package and the two schedule entries.

Tasks:

- [ ] **Approval gate.**
- [ ] **Container prerequisite (do not skip):** `spatie/laravel-backup` shells out to `mysqldump`. The
      production image ([docker/8.4/Dockerfile](../../docker/8.4/Dockerfile)) installs `sqlite3` but **not**
      a MySQL client, so the DB dump will fail at runtime. Add `default-mysql-client` (or `mariadb-client`)
      to the `apt-get install` line and verify with `which mysqldump` in the container before relying on
      the schedule.
- [ ] `composer require spatie/laravel-backup`; `php artisan vendor:publish --tag=backup-config`; commit
      `config/backup.php`.
- [ ] Point the destination at the **existing Spaces disk**: in `config/backup.php` set
      `'destination.disks' => ['do_spaces']` — the configured DigitalOcean disk in
      [config/filesystems.php](../../config/filesystems.php), **not** the generic unused `s3` stub.
- [ ] **Scope what gets backed up — important.** Sermon media already lives in Spaces, so do **not** let
      the file backup re-upload ~200 GB of media. Set `source.files.include` to the database plus the
      *local persistent* storage only, and exclude `vendor/`, `node_modules/`, `storage/app/temp`,
      `storage/app/livewire-tmp`, and the Spaces-backed sermon paths. The archive should be the DB + local
      `storage/app/public` (pages), nothing large.
- [ ] Set retention (`config/backup.php` → `cleanup.defaultStrategy`: e.g. keep 7 daily / 4 weekly / 3
      monthly) and **encrypt** the archive (`backup.archive.password` from a new `BACKUP_ARCHIVE_PASSWORD`
      env var) since it contains the production database.
- [ ] Wire failure + success notifications to the existing admin mailbox: `notifications.mail.to` →
      `env('LIVESTREAM_ADMIN_EMAIL')` (the address the pipeline already uses, see
      [config/media-processing.php](../../config/media-processing.php)). Keep `BackupHasFailed`,
      `CleanupHasFailed`, and `UnhealthyBackupWasFound` enabled.
- [ ] Schedule in [bootstrap/app.php](../../bootstrap/app.php) `->withSchedule()`, production-only, with
      `withoutOverlapping()` (matching the existing entries' style):
      ```php
      $schedule->command('backup:clean')->daily()->at('01:00')->onOneServer()->environments(['production']);
      $schedule->command('backup:run')->daily()->at('01:30')->withoutOverlapping(120)->environments(['production']);
      ```
- [ ] **Leave `GenerateProdSermonPatchCommand` alone.** It reads a *raw* `storage/app/backup.sql`
      ([app/Console/Commands/GenerateProdSermonPatchCommand.php:434](../../app/Console/Commands/GenerateProdSermonPatchCommand.php#L434));
      `backup:run` produces an *encrypted zip*. Different formats for different purposes — do not try to
      make one feed the other. The manual patch flow stays as-is.
- [ ] Once a real backup is verified in Spaces (download + restore-test the DB dump **once**), delete the
      stray root-level dumps (`backup_2026-05-08.sql`, `prod-20260326.sql`, `sermons_202605072210.sql`).
      This overlaps Phase R1 / Finding 12 clutter cleanup.

Tests / verification:

- [ ] Run `php artisan backup:run` against a non-prod bucket (or a local disk) and assert a zip lands;
      `php artisan backup:list` shows it healthy.
- [ ] Force a failure (bad disk creds) and confirm the admin email fires — a feature test with
      `Mail::fake()` around the failure notification, or a manual staging run.

Exit criteria: encrypted DB + local-files backups land in Spaces on a schedule with retention and
failure alerts to `LIVESTREAM_ADMIN_EMAIL`; `mysqldump` present in the image; manual `.sql` dumps retired.

### Phase P3 — `spatie/laravel-health` (+ `laravel-schedule-monitor`) · ✅ Adopt

**Status: Pending approval.** More than tidy-up: investigation found the bespoke disk-space code this
replaces is partly **dead** and partly a **production no-op**, so consolidating is also a bug fix.

What's actually there (verified, not assumed):

- [app/Mail/DiskSpaceWarning.php](../../app/Mail/DiskSpaceWarning.php), its view
  [resources/views/emails/disk-space-warning.blade.php](../../resources/views/emails/disk-space-warning.blade.php),
  and [tests/Unit/Mail/DiskSpaceWarningTest.php](../../tests/Unit/Mail/DiskSpaceWarningTest.php) all exist
  — but the mailable is **never dispatched anywhere in `app/`**. It is dead code.
- The disk check at [SermonValidationService.php:190](../../app/Services/SermonValidationService.php#L190)
  reads `config("filesystems.disks.{$disk}.root")` for the **sermon disk**, which in production is the
  Spaces (S3) disk with **no `root` key** → `$diskPath` is `null` → the check silently does nothing. It
  only ever fired locally.
- The genuinely meaningful pressure is on the **local temp disk** (the known pipeline bottleneck):
  [VideoStorageService.php:324](../../app/Services/VideoStorageService.php#L324) and
  [HistoricVideoImporter.php:927](../../app/Services/HistoricVideoImporter.php#L927) (`hasTempDiskSpace()`).

Tasks:

- [ ] **Approval gate.**
- [ ] `composer require spatie/laravel-health spatie/laravel-schedule-monitor`; publish config; run the
      `schedule-monitor` migration (adds a `monitored_scheduled_tasks` table).
- [ ] Register checks in a `HealthServiceProvider` (or `AppServiceProvider::boot`):
      - `DiskSpaceCheck` pointed at the **local temp disk** path (`storage/app/temp` — the real
        bottleneck), warning ~75 % / error ~90 %.
      - `DatabaseCheck`, `RedisCheck`, `RedisMemoryUsageCheck`, `CacheCheck`.
      - `QueueCheck` (heartbeat / `queue:monitor` approach) so a stalled queue alerts.
- [ ] Notifications: configure `config/health.php` to mail failing checks to `LIVESTREAM_ADMIN_EMAIL`
      (same notifiable approach as P2).
- [ ] **Delete the dead bespoke path:** remove `app/Mail/DiskSpaceWarning.php`, its Blade view, and
      `tests/Unit/Mail/DiskSpaceWarningTest.php`. Per the AGENTS.md test-file policy this deletion needs
      sign-off — it is covered by this approved phase; call it out explicitly in the PR. Removing a
      never-dispatched mailable changes no runtime behaviour.
- [ ] **Health check becomes the single monitoring surface, but keep the behaviour-gating guards.** The
      upload-time rejection in `SermonValidationService` and the `[skip-low-disk]` guard in
      `HistoricVideoImporter` are *control flow*, not monitoring — leave them, but have them read one
      shared threshold helper so the number lives in one place. **Fix `SermonValidationService` to test
      the temp/local disk** (it currently tests a no-op S3 path), closing the prod gap above.
- [ ] `schedule-monitor`: register monitoring for the four scheduled commands in
      [bootstrap/app.php](../../bootstrap/app.php) (`calendar:sync`, `media:cleanup-temp-files`,
      `media:cleanup-unpublished-section-assets`, `scripture:refresh-passages`) plus the P2 `backup:*`
      tasks, so a silently-stopped schedule alerts. Run `schedule-monitor:sync` in the deploy hook.
- [ ] Expose `/health` (package endpoint) behind the `admin` middleware. The existing `/up` route
      ([bootstrap/app.php:23](../../bootstrap/app.php#L23)) stays as the boot-only load-balancer probe;
      `/health` is the richer admin view.

Tests / verification:

- [ ] Feature test: the health endpoint returns the registered checks; a stubbed over-threshold disk
      usage marks the result unhealthy.
- [ ] After repointing `SermonValidationService` at the temp disk, confirm its upload tests still pass and
      add a case proving a full temp disk now **rejects** an upload (the old no-op path silently allowed
      this in prod).

Exit criteria: one health surface (disk / DB / Redis / queue) with admin-routed alerts; the dead
`DiskSpaceWarning` trio deleted; the sermon-disk no-op check fixed to test the real (temp) disk;
scheduled-task failures (including backups) alert.

### Phase P4 — `spatie/laravel-model-states` (state machines) · ⏸️ Defer

**Status: Deferred — do not build now. Recommendation: skip unless you specifically want the
consolidation.**

Why defer (grounded in the code): the logic this would replace is **small, centralised, and already
tested**. [MediaProcessingRunTransitionService](../../app/Services/MediaProcessingRunTransitionService.php)
is 152 lines of clear `markAsX()` methods with a single cancellation guard;
[SermonProcessingStepTransitions](../../app/Services/SermonProcessingStepTransitions.php) is 95 lines,
[ServiceSectionPublicationTransitionService](../../app/Services/ServiceSectionPublicationTransitionService.php)
74. Model-states would swap **enum casts for state classes** on `MediaProcessingLog` and every call site,
add a class per transition, and touch casts/migrations — high churn for *consolidation*, not new
capability. The payoff (declared `allowedTransitions()`) is marginal when the rules already live in one
guarded service, and the `ProcessingStatus` enum (7 cases, `isRetryable()`/`isInProgress()` helpers) is
doing fine.

**What would flip this verdict:** a *third* place starts enforcing the same transition rules (genuine
rule duplication), or a new lifecycle appears with complex per-transition side effects. Then the pilot
below is the path.

Optional pilot spec (only if you choose to proceed):

- [ ] **Approval gate.**
- [ ] `composer require spatie/laravel-model-states`.
- [ ] Pilot on the media-processing run lifecycle **only** (`MediaProcessingLog` +
      `MediaProcessingRunTransitionService`): model each `ProcessingStatus` as a state class with declared
      `allowedTransitions()`; move the cancellation guard and the field-mutation side effects
      (`completed_at`, `dedup_key` clearing, manual-review metadata) into transition classes.
- [ ] Keep the `ProcessingStatus` *string values* identical (DB compatibility) — consolidate the
      *transition rules*, not the labels. Preserve the manual-review-pending semantics (status `Failed` +
      `current_step=manual_review_required` + cleared `dedup_key`).
- [ ] Re-run the media-processing feature/integration suite unchanged — the pilot must be behaviour-neutral.
- [ ] Record a written go/no-go for the remaining 8 machines before converting any of them.

Exit criteria (if piloted): the media-processing lifecycle uses guarded state transitions with no
behaviour change; a written go/no-go covers the rest. **Otherwise: no action — verdict stands at defer.**

### Phase P5 — `laravel/ai` (Laravel AI SDK) · ⏸️ Defer

**Status: Deferred — do not build now. Revisit when the package reaches a stable (≥ 1.0) release.**

Why defer: the strategic fit is real (the app has hand-rolled a smaller version of the SDK — provider
binding, structured output, a fake layer — in [AiServiceProvider](../../app/Providers/AiServiceProvider.php)
+ `SermonAnalysis*` + `Mock*`), but two facts make *now* the wrong time:

1. **Maturity.** `laravel/ai` is a freshly released first-party package. Rebuilding a **working, tested**
   analysis/transcription pipeline on a v0 dependency trades a known-good system for churn and version risk.
2. **The cost of waiting is near zero.** The AI work is already isolated behind three interfaces
   ([SermonAnalysisInterface](../../app/Contracts/SermonAnalysisInterface.php),
   [TranscriptionServiceInterface](../../app/Contracts/TranscriptionServiceInterface.php),
   [OosEmailItemExtractor](../../app/Contracts/OosEmailItemExtractor.php)) with config-selected
   implementations in `AiServiceProvider`. The seam to swap already exists — deferring loses nothing and
   the swap is no harder later.

**What would flip this verdict:** a stable `laravel/ai` release, *or* a concrete need the current seam
can't meet cheaply (e.g. provider failover because OpenAI outages are halting the pipeline, or a second
provider requirement).

First-seam spec (only when you proceed — start with the **smaller** of the two seams):

- [ ] **Approval gate**; pin the version deliberately.
- [ ] `composer require laravel/ai`; publish config; run conversation migrations only if you use that feature.
- [ ] **First seam = the OOS email extractor** (172 lines, single method, one `Data` return type — smaller
      and lower-traffic than sermon analysis). Reimplement
      [OpenAiOosEmailItemExtractor](../../app/Services/OpenAiOosEmailItemExtractor.php) as a
      `HasStructuredOutput` agent returning the `OosEmailItemExtractionResult` schema, bound to the existing
      [OosEmailItemExtractor](../../app/Contracts/OosEmailItemExtractor.php) contract so nothing else moves.
- [ ] Swap that seam's tests to the SDK `::fake()` / `assertPrompted()` layer; delete its bespoke mock once
      green. (Sermon analysis — `SermonAnalysisService` 502 + `SermonAnalysisPromptBuilder` 117 +
      `SermonAnalysisValidator` 252 + `MockSermonAnalysisService` 462 — is the bigger prize but the riskier
      first move; do it **second**, after the OOS seam proves the pattern.)
- [ ] **Do not** touch: the thumbnail pipeline (frame extraction, not AI generation); the
      Resemblyzer/`SpeakerProfile` voice-fingerprinting (separate investigation); vector search (needs
      PostgreSQL + pgvector — this app is MySQL).

Exit criteria (when done): one AI seam (the OOS extractor) runs on the SDK behind its existing interface,
with the SDK fake layer in tests and that seam's bespoke prompt/validator/mock deleted. **Otherwise: no
action — verdict stands at defer.**

### Phase P6 — Consider / Skip tier (verdicts recorded; no build unless a trigger fires)

These need no further investigation to action — each has a verdict and the specific condition that would
flip it. The point is that non-adoption is a *recorded decision*, not an oversight.

**Consider — currently do nothing; adopt only if the named trigger fires:**

- `spatie/laravel-csp` — **strongest of the three, but defer.** A public site benefits from a Content
  Security Policy, but Livewire 4 + Alpine inject inline scripts/styles, so a strict CSP needs nonce wiring
  through the layout and every inline `<script>`/`@push`. Real work for a site with no current XSS
  incident. **Trigger:** a security review or hosting requirement asks for CSP, or you add third-party
  embeds. When you do, budget the nonce plumbing — do not ship `unsafe-inline`.
- `spatie/laravel-activitylog` — **defer.** An admin audit trail is nice but the admin group is small,
  trusted, and binary. **Trigger:** more than a couple of admins, or a "who changed this sermon?"
  accountability requirement. Then add the `LogsActivity` trait to the CRUD models (sermons, pages,
  meetings, preachers, users) — additive, low risk.
- `laravel/pennant` — **skip (lean against adopting).** The rollout-sensitive pipeline thresholds (e.g.
  the livestream extraction thresholds) live in `config/` today, which is adequate for a single-tenant app
  with no per-user rollout. Pennant earns its keep for *per-user / percentage* rollouts this app doesn't
  do. **Trigger:** a need to flag a feature per-user or ramp a change to a % of traffic. Until then, config
  toggles win on simplicity.

**Skip — confirmed non-adoption; revisit only if the premise changes:**

- `spatie/laravel-permission` — the **binary admin model is deliberate**, defence-in-depth, and pinned by
  a test. RBAC adds complexity for no current need. (Revisit only if real role tiers appear.)
- `spatie/laravel-query-builder` — filtering lives in **stateful Livewire components**, not request-string
  API params. (Revisit only if a public/JSON filtering API appears.)
- `laravel/telescope` — overlaps the existing Debugbar + Pail (and Horizon, once P1 lands). (Revisit only
  if you need request/job telemetry those three don't cover.)

---

## Suggested Order

1. **Phase R1** (hygiene quick wins) — one small PR, immediately.
2. **Phase R2** (relocate misplaced classes) — prep, before any Services move.
3. **Phase R3** (Services namespaces, one cluster per PR) → **R4** (Data sub-grouping) → folds in
   Finding 9.
4. **Phase R5** (route naming) — deliberate sweep with Dusk.
5. **Phase R6** (hotspot decomposition + DTOs) — opportunistic, whenever those files are next touched.
6. **Phases D1 / D2** (decisions) — can happen any time; cheap.
7. **Track 3 packages** (see the Track 3 recommendation table): adopt **P2 → P1 → P3** after approval
   (the three ✅ verdicts — P2 first as it cannot break anything). **Defer P4 and P5** (⏸️) and treat the
   P6 Consider/Skip tier as decisions-only.

Tracks 1, 2, and 3 are independent and can proceed in parallel; within Track 1, R2 must precede R3
and R3 should precede R4.

## Definition of Done

- Every structural phase (R1–R6) leaves the four quality gates green (Pint, PHPStan 0 errors, full
  parallel suite, Dusk where relevant).
- `app/Services/` is domain-namespaced; no DTOs/actions misfiled; `app/Repositories/` resolved;
  route names follow one convention; the lock-file/clutter/PHP-constraint issues are closed.
- Decision-only items (D1, D2) are written into `AGENTS.md`.
- Each *adopted* package phase (P2, P1, P3) is behind its approval gate and replaces — not merely
  supplements — the bespoke code it supersedes. Each *deferred* phase (P4, P5) and the P6 Consider/Skip
  tier carries a written verdict + re-open trigger, so non-adoption is a recorded decision, not drift.
- This plan is moved to [docs/archived-plans/](../archived-plans/) once all in-scope phases are
  either done or consciously deferred, with a short execution log.
