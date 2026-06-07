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
> sign-off task; do not `composer require` until that box is ticked.** Ordered by value/risk.

### Phase P1: `laravel/horizon` — queue observability

**Priority: High (strong fit).** **Status: Pending approval.**

Tasks:

- [ ] **Approval gate** — confirm adoption with the maintainer.
- [ ] `composer require laravel/horizon`; publish config; configure supervisors in `config/horizon.php`.
- [ ] Switch the prod Supervisor entry in
      [docker-compose.prod.yml](../../docker-compose.prod.yml) from `queue:work` to `artisan horizon`.
- [ ] Gate the Horizon dashboard behind the existing `admin` middleware/`HorizonServiceProvider` gate.
- [ ] Smoke-test a media-processing job end to end; confirm metrics/failed-job retry work.

Exit criteria: Redis queues run under Horizon with a dashboard; no `queue:work` worker remains.

### Phase P2: `spatie/laravel-backup` — automated backups

**Priority: High (lowest-risk, additive).** **Status: Pending approval.**

Tasks:

- [ ] **Approval gate.**
- [ ] `composer require spatie/laravel-backup`; publish config; point the backup disk at the existing
      DigitalOcean Spaces (S3) filesystem.
- [ ] Schedule `backup:run` / `backup:clean` in [bootstrap/app.php](../../bootstrap/app.php)
      `->withSchedule()`; set retention and failure notifications (reuse `LIVESTREAM_ADMIN_EMAIL`).
- [ ] Remove the manual `.sql` dumps once automated backups are verified in the target environment.

Exit criteria: scheduled DB+file backups land in Spaces with retention + failure alerts; manual
dumps retired.

### Phase P3: `spatie/laravel-health` (+ `laravel-schedule-monitor`)

**Priority: Medium-High.** **Status: Pending approval.**

Tasks:

- [ ] **Approval gate.**
- [ ] `composer require spatie/laravel-health spatie/laravel-schedule-monitor`.
- [ ] Register disk-usage, Redis, database, and queue checks; wire notifications to
      `LIVESTREAM_ADMIN_EMAIL`.
- [ ] Migrate the bespoke disk-space logic ([app/Mail/DiskSpaceWarning.php](../../app/Mail/DiskSpaceWarning.php)
      and the duplicated checks in `SermonValidationService` / `VideoStorageService` /
      `HistoricVideoImporter`) onto the health check; delete the duplicates.
- [ ] Add schedule-monitor coverage for the calendar sync, sitemap, and cleanup tasks.

Exit criteria: one health surface; disk-space duplication removed; scheduled-task failures alert.

### Phase P4: `spatie/laravel-model-states` — pilot one machine

**Priority: Medium (highest churn — pilot first).** **Status: Pending approval.**

Tasks:

- [ ] **Approval gate.**
- [ ] `composer require spatie/laravel-model-states`.
- [ ] Pilot on the **media-processing run lifecycle only** (`MediaProcessingLog` + its
      `*TransitionService`). Model states as classes with declared `allowedTransitions()`; move legal-
      transition guards into the state classes.
- [ ] Keep the existing status enum labels; this consolidates the *transition rules*, not the labels.
- [ ] Evaluate before converting any of the other 8 status machines.

Exit criteria: the media-processing lifecycle uses guarded state transitions; a go/no-go decision is
recorded for the remaining machines.

### Phase P5: `laravel/ai` (Laravel AI SDK) — first seam, behind existing interfaces

**Priority: Medium (strong fit, incremental).** **Status: Pending approval.**

Tasks:

- [ ] **Approval gate** (and pin the version deliberately — it is a new package).
- [ ] `composer require laravel/ai`; publish config + run the conversation migrations only if needed.
- [ ] **First seam:** reimplement the OOS email extractor *or* sermon-analysis structured output as a
      `HasStructuredOutput` agent, and bind it **to the existing `SermonAnalysisInterface` /
      `OosEmailItemExtractor` contract** so the rest of the app does not move.
- [ ] Replace the corresponding `Mock*` service with the SDK `::fake()` layer in tests; delete the
      mock class once green.
- [ ] **Do not** touch: the thumbnail pipeline (frame extraction, not AI generation), the
      Resemblyzer/`SpeakerProfile` voice-fingerprinting (separate investigation), or attempt vector
      search (needs PostgreSQL+pgvector; this app is MySQL).
- [ ] Only after the first seam proves out, expand to transcription and the remaining analysis paths.

Exit criteria: one AI seam runs on the SDK behind its existing interface, with the SDK fake layer in
tests and the bespoke prompt-builder/validator/mock for that seam deleted.

### Package phases — Consider / Skip (no action unless a need emerges)

- **Consider:** `spatie/laravel-activitylog` (admin audit trail), `laravel/pennant` (pipeline feature
  flags), `spatie/laravel-csp` (CSP hardening). Each is product-decision-dependent.
- **Skip (documented non-adoption):** `spatie/laravel-permission` (binary admin model is deliberate),
  `spatie/laravel-query-builder` (filtering is in Livewire, not request params), `laravel/telescope`
  (overlaps Debugbar/Pail/Horizon).

---

## Suggested Order

1. **Phase R1** (hygiene quick wins) — one small PR, immediately.
2. **Phase R2** (relocate misplaced classes) — prep, before any Services move.
3. **Phase R3** (Services namespaces, one cluster per PR) → **R4** (Data sub-grouping) → folds in
   Finding 9.
4. **Phase R5** (route naming) — deliberate sweep with Dusk.
5. **Phase R6** (hotspot decomposition + DTOs) — opportunistic, whenever those files are next touched.
6. **Phases D1 / D2** (decisions) — can happen any time; cheap.
7. **Track 3 packages**, each after approval: **P1 → P2 → P3 → P4 (pilot) → P5 (incremental)**.
   P1 and P2 are the low-risk, high-value starting points.

Tracks 1, 2, and 3 are independent and can proceed in parallel; within Track 1, R2 must precede R3
and R3 should precede R4.

## Definition of Done

- Every structural phase (R1–R6) leaves the four quality gates green (Pint, PHPStan 0 errors, full
  parallel suite, Dusk where relevant).
- `app/Services/` is domain-namespaced; no DTOs/actions misfiled; `app/Repositories/` resolved;
  route names follow one convention; the lock-file/clutter/PHP-constraint issues are closed.
- Decision-only items (D1, D2) are written into `AGENTS.md`.
- Each adopted package phase is behind its approval gate and replaces — not merely supplements — the
  bespoke code it supersedes.
- This plan is moved to [docs/archived-plans/](../archived-plans/) once all in-scope phases are
  either done or consciously deferred, with a short execution log.
