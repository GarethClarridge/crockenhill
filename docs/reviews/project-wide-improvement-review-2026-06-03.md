# Project-Wide Improvement, Modernisation, Standardisation & Simplification Review

Date: 2026-06-03
Reviewer: Claude Code (Opus 4.8)
Branch reviewed: `master` @ `e7f6f1d93`

## How to read this document

This is a *fresh* review. The `docs/april-2026-review/` findings have all been implemented, so
none of them are repeated here. This pass deliberately looks at the **current** shape of the
repository — not bugs, but where structure, conventions, and modern Laravel/Livewire idioms have
drifted as the application has grown.

Each finding is tagged with the requesting categories — **Improvement**, **Modernisation**,
**Standardisation**, **Simplification** — plus a rough effort/impact estimate. **No changes have
been made.** This is a recommendations-only document.

## Headline assessment

The codebase is in strong health. Concrete signals:

- **498 PHP files in `app/`**, only **3** `TODO/FIXME/HACK` markers anywhere.
- **PHPStan level 8** (of 10) with a single ignore rule and an otherwise-empty baseline.
- **653 test files** across Feature / Integration / Unit / Browser / Performance.
- A mature CI estate: `pr.yml`, `nightly.yml`, `deploy.yml`, `rollback.yml`, plus custom guard
  scripts (`check-bootstrap-safety`, `check-schema-dump-current`, `check-typing-baseline`,
  `check-deploy-view-cache-safety`).

The April reviews clearly landed. As a result, the remaining opportunities are overwhelmingly
**organisational** (where code lives, how it is grouped, naming consistency) rather than
**correctness**. The dominant theme is *growth outpacing structure*: the domains are already
visible in the file names, they are just not yet expressed as namespaces or typed contracts.

---

## Priority matrix

| # | Finding | Category | Impact | Effort |
|---|---------|----------|--------|--------|
| 1 | Flat `app/Services/` (128 files in one directory) | Standardisation / Simplification | High | Medium |
| 2 | God-class services & fat `Sermon` model | Simplification | High | High |
| 3 | Raw-array data passing where DTOs exist | Modernisation | Medium | High |
| 4 | Misplaced value objects living in `Services/` | Standardisation | Medium | Low |
| 5 | Inconsistent route naming + inline closures | Standardisation | Medium | Low |
| 6 | Two E2E frameworks (Dusk + Playwright) overlap | Simplification / Standardisation | Medium | Medium |
| 7 | Lock files gitignored but tracked (contradiction) | Improvement | Medium | Trivial |
| 8 | Generated `routes.json` committed to the repo | Improvement | Low | Trivial |
| 9 | Lone leftover `app/Repositories/` directory | Standardisation | Low | Low |
| 10 | Flat `app/Data/` (45 DTOs, no sub-grouping) | Standardisation | Low | Low |
| 11 | `composer.json` PHP constraint lags the platform | Modernisation | Low | Trivial |
| 12 | Working-tree clutter (large stray artifacts) | Improvement | Low | Trivial |
| 13 | Class+view Livewire vs. Livewire 4 SFC (optional) | Modernisation | Low | High |

---

## Findings

### 1. [High] `app/Services/` is a 128-file flat directory — the domains are implicit, not structured

**Category:** Standardisation / Simplification

`app/Services/` holds **131 PHP files, 128 of them at the top level** (only one subdirectory,
`SectionPublication/`, exists). Grouping the file names by domain prefix shows the boundaries are
*already there*, just unexpressed:

| Domain cluster | Approx. count | Examples |
|----------------|---------------|----------|
| Sermon | 19 | `SermonCreationService`, `SermonStorageService`, `SermonAnalysisService`, `SermonValidationService` |
| Media processing (Audio/Video/Thumbnail/Frame/RMS) | ~17 | `AudioTranscriptionService`, `VideoSegmentationService`, `ThumbnailGenerationService`, `RmsAnalysisService` |
| Processing pipeline | 9 | `ProcessingPhaseRegistry`, `ProcessingRunOrchestrator`, `ProcessingPipelineBuilder`, `UnifiedMediaProcessor` |
| Song / OpenLP | ~13 | `SongCatalogSyncService`, `SongLyricsMatchingService`, `OpenLpServiceParser` |
| Church service | 8 | `ChurchServiceItemSyncService`, `ChurchServiceStructureMergeService` |
| Public read-side | 5 | `PublicPageReadModelCache`, `PublicSongCatalogService`, `SitemapService`, `PodcastFeedService` |
| Scripture | 3 | `ScriptureReferenceResolver`, `ApiBibleClient`, `ScriptureOperatorService` |
| Preacher / speaker | ~6 | `PreacherResolutionService`, `ResemblyzerSpeakerIdentificationService` |
| Calendar / Email inbound | ~4 | `GoogleCalendarSyncService`, `InboundEmailImportService`, `OosEmailParserService` |

**Why it matters:** a flat directory of this size makes it hard to find collaborators, hides the
true domain boundaries, and gives no signal about which services may depend on which. New
contributors (and agents) cannot tell at a glance whether a class is part of the media pipeline or
the church-service workflow.

**Recommendation:** introduce namespaced subdirectories that mirror the clusters above, e.g.
`App\Services\Sermon\…`, `App\Services\Media\{Audio,Video,Thumbnail}\…`, `App\Services\Processing\…`,
`App\Services\Song\…`, `App\Services\ChurchService\…`, `App\Services\Public\…`,
`App\Services\Scripture\…`. This is a mechanical move (namespace + `use` updates) that PHPStan and
the test suite will guard. It can be done one cluster per PR to keep diffs reviewable.

---

### 2. [High] A handful of god-class services and a fat `Sermon` model carry disproportionate complexity

**Category:** Simplification

Largest units by line count:

| File | Lines | Notes |
|------|------:|-------|
| `Services/SongCatalogSyncService.php` | 1,436 | 41 methods; mixes PDO source reads, parsing, fuzzy matching, persistence, *and* dry-run metric building |
| `Services/HistoricVideoImporter.php` | 1,138 | |
| `Services/ProcessingPhaseRegistry.php` | 972 | |
| `Services/ThumbnailCanvasComposer.php` | 954 | |
| `Presenters/SermonViewPresenter.php` | 922 | |
| `Services/ChurchServiceItemSyncService.php` | 912 | |
| `Services/VisualAnalysisService.php` | 881 | |
| `Models/Sermon.php` | 864 | ~38 fillable columns, 17 query scopes, multiple accessors + business methods |

`SongCatalogSyncService` is the clearest example: a single class owns SQLite ingestion,
canonical-key grouping, legacy reconciliation (by praise number *and* title), author/book pivot
upserts, slug generation, *and* a parallel dry-run path that recomputes much of the same logic for
preview. These are at least 3–4 collaborators wearing one hat.

**Why it matters:** these classes are where future change concentrates risk. The dual real/dry-run
code paths in the sync services in particular are a duplication hazard — a fix to one path can
silently skip the other.

**Recommendation:** extract by responsibility, not by line count. For the sync importers, separate
*source reading* (PDO/CSV ingestion), *matching/reconciliation policy*, and *persistence* into
collaborators, and let a thin orchestrator drive both real and dry-run via the same path with a
"commit vs. preview" flag. For `Sermon`, consider moving the query scopes into a dedicated
builder/scope class and the heavier presentation accessors toward the existing
`SermonViewPresenter`. Note `app/Presenters/` already exists and recent commits (Phase 14) have
been extracting in this direction — this finding is "keep going", not "start".

---

### 3. [Medium] Heavy raw-array data passing despite `spatie/laravel-data` being available

**Category:** Modernisation

The large sync/import services pass untyped `array $…` structures between most of their private
methods (`SongCatalogSyncService` alone has ~30 `array`-typed parameters carrying "group rows",
"author links", "metrics", "legacy lookup state", etc.). The project already depends on
`spatie/laravel-data` (`^4.17`) and has **45 DTOs** in `app/Data/`, so the idiom and tooling are
established — they just aren't used in the oldest/largest importers.

**Why it matters:** array-shape PHPDoc (`array{...}`) is the current mitigation, but it is
advisory only; the shapes are coordinated by hand across many methods and are easy to drift.
Promoting the high-traffic shapes (e.g. a song group, a reconciliation match, a sync metrics
tally) to `Data` objects would let PHPStan enforce them and make the call sites self-documenting.

**Recommendation:** when these services are next touched (see Finding 2), convert the most
load-bearing array shapes to `spatie/laravel-data` objects rather than expanding the PHPDoc. Treat
it as opportunistic modernisation, not a big-bang rewrite.

---

### 4. [Medium] Value objects / DTOs and an action are living in `app/Services/`

**Category:** Standardisation

Several classes in `Services/` are not services in the behavioural sense:

- `VideoProcessingOptions` — an options value object.
- `ProcessingResult`, `ProcessingReport` — result/DTO objects.
- `CalendarCategorizationResult` — a result DTO.
- `GetMediaProcessingStatus`, `UnifiedMediaProcessor` — read/coordinator *actions* (the repo has a
  dedicated `app/Actions/` directory with 19 files).

**Why it matters:** mixing value objects, results, and actions into `Services/` blurs the meaning
of "Service" and undermines the `Data/` and `Actions/` directories that already exist for exactly
these roles.

**Recommendation:** relocate the pure data/result classes to `app/Data/` and the action-shaped
classes to `app/Actions/`. Low effort, guarded by the test suite. Best folded into the Finding 1
reorganisation.

---

### 5. [Medium] Route naming mixes three conventions, plus inline closures in `web.php`

**Category:** Standardisation

`routes/web.php` route names mostly follow dot-notation (`sermons.show`, `calendar.index`,
`meetings.create`), but there are outliers in three other styles:

- TitleCase: `Home`, `memberHome`
- bare single words with no resource segment: `christ`, `church`, `community`, `christmas`,
  `index`, `dashboard`

There are also **8 inline closures** in `web.php`.

**Why it matters:** `route('Home')` vs `route('sermons.show')` is a small but constant
inconsistency that makes route names unpredictable and harder to grep. Inline closures cannot be
route-cached the way controller/`Route::view` actions can, and they spread request-handling logic
out of the controller layer.

**Recommendation:** standardise on lowercase dot-notation (`home`, `members.home`, `pages.christ`,
…). Because renaming routes is a breaking change for `route()` callers and tests, do it as a
deliberate sweep with a grep for each old name. Convert remaining view-only closures to
`Route::view()` and any logic-bearing closures to single-action controllers.

---

### 6. [Medium] Two browser-test frameworks (Dusk + Playwright) with overlapping coverage

**Category:** Simplification / Standardisation

The repo maintains **both**:

- **Laravel Dusk** — `tests/Browser/` (12 functional specs: Homepage, Navigation, Members,
  PublicPages, PageCards), Selenium-driven, with `.env.dusk.ci` and a dedicated CI path.
- **Playwright** — `tests/Playwright/` (5 visual-regression specs: homepage, sermons,
  meeting-detail, mobile-nav, section-landings), with `playwright.config.ts`, committed snapshot
  baselines, `.env.playwright.ci`, and `test:visual` npm scripts.

There is topical overlap (homepage, navigation, sermons appear in both), and the project carries
two browser stacks, two CI env files, and two sets of fixtures.

**Why it matters:** two E2E toolchains is double the maintenance surface, double the flakiness
budget, and an ongoing "which one do I add the test to?" decision for every UI change.

**Recommendation:** decide on an explicit division of labour and document it, *or* consolidate.
Playwright can cover both functional and visual-regression needs; if the team prefers Dusk's
in-Laravel ergonomics for functional flows, keep Dusk strictly for functional and Playwright
strictly for pixel snapshots, and remove the functional overlap from the Playwright specs (or vice
versa). The goal is one obvious home for each kind of browser assertion.

---

### 7. [Medium] `composer.lock` and `package-lock.json` are gitignored *and* tracked — a contradiction

**Category:** Improvement (build reproducibility)

`.gitignore` contains:

```
# Lock files
composer.lock
package-lock.json
```

…yet both files are currently **tracked** in git. For an *application* (not a library), lock files
should absolutely be committed for reproducible installs — so the tracked state is correct and the
`.gitignore` entry is wrong/misleading.

**Why it matters:** the entry is a latent footgun. A future `git rm --cached composer.lock` (or a
contributor "tidying" ignored files) would silently drop the lock files from version control and
break build reproducibility, with the `.gitignore` appearing to bless it.

**Recommendation:** delete those two lines from `.gitignore`. Trivial change, removes a real risk.

---

### 8. [Low] A generated `routes.json` is committed to the repo

**Category:** Improvement

`routes.json` (≈40 KB) is a tracked `route:list --json` dump. It is a generated artifact that will
drift from the actual routes the moment any route changes, and nothing reads it at runtime.

**Recommendation:** remove it from version control and (if anything needs it) regenerate on demand
via `artisan route:list --json`. Add to `.gitignore` if a local copy is convenient.

---

### 9. [Low] `app/Repositories/` is a one-file leftover from the "Caches" reframe

**Category:** Standardisation / Simplification

The recent commit *"Reframe Repositories/ as Caches (or fold into Services/)"* moved most of that
directory, but `app/Repositories/SermonRepository.php` remains as the **only** file in its own
top-level namespace. The class itself is a query-builder + in-process memoisation + `Cache` facade
hybrid — i.e. it behaves like the new "Cache"/read-model services elsewhere, not a classic
repository.

**Recommendation:** finish the reframe — either move `SermonRepository` alongside the other
read-side cache services (and delete the empty `Repositories/` directory), or, if the Repository
pattern is intentionally retained for the sermon read-path, document that decision so the lone
directory reads as deliberate rather than residual.

---

### 10. [Low] `app/Data/` is a 45-file flat directory (same smell as Services, smaller)

**Category:** Standardisation

`app/Data/` holds 45 DTOs with no sub-grouping. It is far more navigable than `Services/`, but the
same domain clusters (processing, church-service, sermon, thumbnail) apply.

**Recommendation:** if Finding 1 proceeds, mirror the same domain subdirectories under `Data/` so
a domain's services and DTOs share a structure. Optional / cosmetic on its own.

---

### 11. [Low] `composer.json` requires PHP `^8.3.0` while the platform targets 8.4

**Category:** Modernisation

`composer.json` declares `"php": "^8.3.0"`, but `AGENTS.md`, the Boost guidelines, and the Docker
image all target **PHP 8.4**.

**Why it matters:** the looser constraint means static analysis and CI may not assume 8.4-only
features, and there's a small risk of merging code that wouldn't install on the declared floor.

**Recommendation:** either bump to `"php": "^8.4"` to match production and unlock 8.4 assumptions
in tooling, or keep 8.3 deliberately (e.g. to preserve a downgrade path) and note *why* so it
doesn't read as drift.

---

### 12. [Low] Working-tree clutter: large stray artifacts in the repo root

**Category:** Improvement (hygiene)

All correctly gitignored, but present in the working tree and easy to fat-finger into a commit:

- `Easter Sunday 5th April 2026.mp4` (**218 MB**)
- `2024-11-17 AM.osz` / `2024-11-17 PM.osz` (**51 MB**)
- `songs (1).sqlite` (3.1 MB), multiple `*.sql` dumps (`backup_2026-05-08.sql`,
  `prod-20260326.sql`, `sermons_202605072210.sql` — ~14 MB combined)
- `TapeIndex.csv` (116 KB), `test-results/` (**65 MB**), assorted `.DS_Store` files

**Recommendation:** add a developer scratch directory (e.g. `storage/scratch/`, gitignored) and
keep these out of the project root, or clear them when no longer needed. Purely about reducing the
chance of an accidental large-file commit and keeping the tree tidy — no production impact.

---

### 13. [Low / Optional] Livewire uses the class+separate-view pattern; Livewire 4 SFCs are available

**Category:** Modernisation

47 of the 54 Livewire components are classic class-based components (`extends Component` +
`render()`) paired with separate Blade views under `resources/views/livewire/`. The project is on
**Livewire 4**, which supports single-file components (SFC). Largest components: `MediaUpload.php`
(650), `ServiceReviewDashboard.php` (334), `BrowseSermons.php` (327).

**Why it matters / why it's optional:** the class+view split is fully supported and perfectly
valid in Livewire 4 — there is no correctness reason to migrate. SFCs would mainly help small,
single-purpose leaf components by keeping logic and markup together. Migrating 47 components is
high-churn for low payoff.

**Recommendation:** do *not* mass-migrate. If the team likes SFCs, adopt them for *new* small
components and convert opportunistically; leave the large stateful components as class+view. Listed
here only so the choice is explicit rather than accidental.

---

## Package opportunities

Finding 3 noted that `spatie/laravel-data` is under-used. The same lens — *where is the project
hand-rolling a solved problem?* — surfaces several other well-supported packages. Per `AGENTS.md`,
dependency changes need approval, so these are **discussion candidates, not decisions**. Each is
grounded in code that already exists in the repository.

| Package | Category | Fit | Evidence in repo |
|---------|----------|-----|------------------|
| `laravel/horizon` | Improvement / Modernisation | **Strong** | `QUEUE_CONNECTION=redis`, Redis + Supervisor workers in `docker-compose.prod.yml`, 34 jobs in `app/Jobs/`, `QUEUE_WORKER_MEMORY=2048` + retry tuning |
| `spatie/laravel-backup` | Improvement | **Strong** | Manual `backup_2026-05-08.sql` / `prod-20260326.sql` dumps in repo root; `GenerateProdSermonPatchCommand` expects a hand-placed `storage/app/backup.sql`; Spaces (S3) disk already configured |
| `spatie/laravel-health` (+ `laravel-schedule-monitor`) | Improvement | **Strong** | `DiskSpaceWarning` mailable + disk checks duplicated across `SermonValidationService`, `VideoStorageService`, `HistoricVideoImporter`; temp disk is the known pipeline bottleneck; scheduled tasks (calendar sync, sitemap, cleanup) have no run-failure alerting |
| `spatie/laravel-model-states` | Simplification / Modernisation | **Strong (pilot first)** | 9 status enums + 6 `*TransitionService` / `*StateService` classes enforcing legal transitions imperatively |
| `laravel/ai` (Laravel AI SDK) | Modernisation / Simplification | **Strong (adopt incrementally)** | `AiServiceProvider` + `SermonAnalysis*` + `Mock*` services already hand-roll the SDK's provider abstraction, structured output, and fake layer — for OpenAI only |
| `spatie/laravel-activitylog` | Improvement | Consider | Full admin CRUD area (sermons, pages, meetings, preachers, users) with no general audit trail |
| `laravel/pennant` | Modernisation | Consider | Rollout-sensitive pipeline behaviour (e.g. livestream extraction thresholds) governed by static `config/` toggles today |
| `spatie/laravel-csp` | Improvement (security) | Consider | Public-facing site; prior security-hardening work (exception path sanitisation) |
| `spatie/laravel-permission` | — | **Skip** | Deliberate *binary* admin model with defence-in-depth, pinned by a test — RBAC would add complexity for no current need |
| `spatie/laravel-query-builder` | — | **Skip** | Filtering lives in stateful Livewire components, not request-string API params |
| `laravel/telescope` | — | **Skip** | Overlaps existing Debugbar + Pail (and Horizon, if adopted) |

### The four strong-fit candidates, in detail

1. **`laravel/horizon`** — you already run Redis queues under raw Supervisor with zero queue
   observability, for a pipeline whose jobs do heavy FFmpeg/Whisper work. Horizon adds a real-time
   dashboard, per-job runtime/throughput metrics, failed-job retry UI, and wait-time alerts. Small
   deployment change: Supervisor runs `artisan horizon` instead of `queue:work`. First-party, free.

2. **`spatie/laravel-backup`** — automates DB + file backups to the DigitalOcean Spaces disk you
   already have, with retention, integrity checks, and failure notifications, replacing the manual
   `.sql` dumps. Lowest-risk option: purely additive, touches no existing code.

3. **`spatie/laravel-health` (+ `laravel-schedule-monitor`)** — centralises disk / Redis / database
   / queue / scheduled-task checks into one configurable place with notifications, absorbing the
   bespoke `DiskSpaceWarning` path and the disk-space logic currently duplicated across three
   services. Schedule-monitor alerts if a scheduled task silently stops firing.

4. **`spatie/laravel-model-states`** — makes states first-class classes with declared
   `allowedTransitions()` and transition classes for side effects, folding the enum +
   transition-service pairs into one guarded contract. **Highest churn of the four** (it swaps enum
   casts for state classes), so pilot it on a single machine — the media-processing run lifecycle is
   the best candidate — before deciding whether to convert the rest. The enums themselves are fine;
   this is about consolidating the *transition rules*, not the labels.

### `laravel/ai` (Laravel AI SDK) — strong strategic fit, adopt incrementally

Called out separately because it is the most nuanced of the strong-fit candidates. The Laravel AI
SDK (`composer require laravel/ai`) is a first-party package with Laravel 13 documentation that
provides a unified API over 14 AI providers plus agents, tools, structured outputs, transcription,
embeddings, streaming, conversation persistence, and a built-in fake/testing layer.

**Why the fit is unusually strong:** this project has *already* hand-built a smaller version of the
SDK's core design. `AiServiceProvider` binds `SermonAnalysisInterface`,
`TranscriptionServiceInterface`, and `OosEmailItemExtractor` to config-selected implementations with
`mock` / `local` / `openai` swapping — i.e. the SDK's Agent abstraction + provider enum + `::fake()`
layer, reinvented for OpenAI only. Adoption mostly means *deleting bespoke scaffolding*.

Direct mappings:

| Bespoke piece | SDK equivalent | Effect |
|---------------|----------------|--------|
| `AiServiceProvider` provider binding (OpenAI only) | `Agent` + `Lab` provider enum, per-call override, automatic failover (14 providers) | Less glue; provider outage no longer halts the pipeline |
| `SermonAnalysisService` + `SermonAnalysisPromptBuilder` + `SermonAnalysisValidator` | `HasStructuredOutput::schema(JsonSchema)` returns a typed, schema-validated array | Deletes the prompt builder *and* the hand-rolled JSON validator |
| `OpenAiOosEmailItemExtractor` | Anonymous `agent(schema: …)` or a small structured agent | Collapses to a schema declaration |
| `MockSermonAnalysisService`, `MockTranscriptionService`, `NullSpeakerIdentificationService` | `::fake()`, `Transcription::fake()`, `assertPrompted()`, `preventStrayPrompts()` | Deletes the `Mock*` service classes |
| `AudioTranscriptionService` + chunking + `OpenAIResponseLogger` | `Transcription::fromStorage()->generate()`, `->queue()`, agent middleware for logging | Thinner; queue + logging become first-class |

**Caveats (why incremental, not wholesale):**

1. **Vector search needs PostgreSQL + pgvector — this app is on MySQL** (`application-info` confirms
   `mysql`). The SDK's `whereVectorSimilarTo()` / `Schema::vector()` features are Postgres-bound.
   Embedding *generation* works on any DB; only the vector query builder requires pgvector. Do not
   adopt expecting to retire the song/speaker matching logic.
2. **Native `->diarize()` is not a drop-in for the speaker pipeline.** It overlaps
   `ResemblyzerSpeakerIdentificationService` (the `env/` Python venv + `scripts/extract_embedding.py`)
   superficially, but the `SpeakerProfile` / `SpeakerSample` voice-*fingerprinting* (identifying
   *which named preacher* is speaking) is a bespoke capability diarization alone won't replace.
   Treat retiring the Python pipeline as a separate investigation.
3. **Maturity.** It is a freshly released first-party package. For a working, tested pipeline, pin
   the version carefully and prove one seam before expanding.
4. **Not a fit for the thumbnail pipeline.** `ThumbnailGenerationService` / `PixianClient` extract
   and brand *video frames*; the SDK's image features *generate* images from prompts. Different
   problem — keep it out of scope.

**Recommended first seam:** the OOS email extractor or the sermon-analysis structured output — both
are self-contained, both have a mock to delete, and structured output is where the SDK most clearly
beats hand-rolled prompt+validate. Bind the SDK agent *to* the existing `SermonAnalysisInterface` so
the rest of the app does not move, prove it, then expand.

### Suggested order

`laravel/horizon` and `spatie/laravel-backup` first (high value, low risk, no churn), then
`laravel-health` + `schedule-monitor`, then `model-states` as a deliberate single-machine pilot
rather than a sweep. `laravel/ai` runs on its own incremental track, starting from one structured-
output seam behind the existing interfaces. The "Consider" tier is product-decision-dependent; the
"Skip" tier is listed explicitly so the *non*-adoption is a conscious choice rather than an oversight.

---

## What is already good (and should be protected)

- **Static analysis discipline:** PHPStan level 8, near-empty baseline, plus a "typing baseline"
  CI guard that blocks regressions on changed files.
- **Defence-in-depth admin authorisation:** route middleware *and* per-component
  `WithAdminAuthorization`, pinned by a test that fails if a new admin component omits the trait.
- **Bespoke CI guard scripts:** bootstrap-safety, schema-dump freshness, deploy view-cache safety —
  these encode hard-won operational lessons and should be kept.
- **Enum-first domain modelling:** 27 enums for services, page areas, processing statuses, etc.
- **British-English convention** enforced in user-facing strings and test assertions.

These are not findings — they are strengths to avoid regressing while addressing the above.

---

## Suggested sequencing

1. **Quick wins first (trivial, high signal):** Findings 7, 8, 11, 12 — gitignore/lock-file fix,
   drop `routes.json`, PHP constraint, root clutter. A single small PR.
2. **Standardisation sweep:** Finding 4 (relocate misplaced DTOs/actions), then Finding 1
   (Services subdirectories) one domain cluster per PR, then Finding 10 (Data subdirectories) to
   match. Finding 9 folds in naturally here.
3. **Naming:** Finding 5 route-name standardisation as a deliberate grep-driven sweep with test
   updates.
4. **Targeted simplification:** Finding 2 + Finding 3 together, opportunistically, whenever a
   god-class service is next modified — extract collaborators *and* introduce DTOs in the same
   pass rather than touching those files twice.
5. **Tooling decision:** Finding 6 — agree the Dusk/Playwright division of labour and document it.
6. **Leave optional:** Finding 13 (Livewire SFCs) — explicit "new components only" stance.

---

*No code was changed in producing this review. All findings are recommendations for the
maintainers to triage.*
