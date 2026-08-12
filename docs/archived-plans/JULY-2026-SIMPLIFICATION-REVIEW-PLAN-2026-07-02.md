# July 2026 Project Simplification Review — Phased Approach

> **ARCHIVED 2026-07-05 — review complete through Phase 8.** The review produced the seven
> findings docs in `docs/reviews/july-2026-simplification/` and the consolidated backlog at
> [the archived July simplification parent](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md),
> which is now the single active tracker. **One piece of this document remains live: the Phase 9
> session brief** (technical code-quality review), which is gated on the backlog's structural work
> substantially landing — run it from the brief below when that gate clears.

Created 2026-07-02. This document defines the structure for a project-wide simplification review, run as **one separate session per phase**. It is the successor to the April 2026 review series (`docs/april-2026-review/`), but sliced differently: by **functional domain** (vertical slices through services, jobs, data, UI, and tests) rather than by cross-cutting concern.

The review exists because the project has grown by incremental addition and is at risk of being overcomplicated — technically *and* in its business logic. The goal is **simple and robust**. The recent LLM-first service-structure work (`docs/plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md`) is the exemplar of what good looks like, and its pattern is codified below as the review doctrine.

## 1. Purpose and principles

### The simplification doctrine

Distilled from the LLM-first service-structure work. Every session applies these as review criteria — both to judge existing code and to shape recommendations:

1. **Collapse multi-step heuristic clusters into one typed call behind a narrow contract.** Where several cooperating services/jobs approximate a judgment (classification, alignment, matching), ask whether a single LLM or single deterministic pass behind an interface would replace the cluster.
2. **Swappable adapters (mock / local / real)** bound in a provider via `match(config(...))`, with CI defaulting to mock so the suite never touches external APIs (the `AiServiceProvider` pattern).
3. **Immutable Data objects as typed boundaries** instead of loose arrays passed between services.
4. **Change at one seam.** Emit the shape the existing downstream already consumes (the `sync()` seam) rather than rewriting the consumers.
5. **Config-driven rollout** (off / shadow / primary) with a deterministic validator as a safety net around anything probabilistic.
6. **Prove, promote, then actually retire the old path.** Offline eval and shadow reporting before promotion — and then delete the superseded implementation. This last step is the one that historically doesn't happen, which is why the codebase carries parallel implementations. Every review should hunt for "promoted but not retired" residue.
7. **Small single-responsibility files grouped in a domain namespace folder**, not god-services.

### The critical-friend test

Applied to every capability, not just every class. For each feature or subsystem, the reviewer asks plainly:

- **What user or operator outcome does this serve?** (Stated in church-operations terms, not code terms.)
- **What would we actually lose if we deleted it?**
- **Is the complexity proportionate to that value?** A 900-line service earning its keep looks different from a 900-line service supporting a nice-to-have.

Business-logic complexity counts as much as technical complexity: workflows with more states than operators use, configurability nobody configures, precision beyond what the domain needs.

### Simplification as an enabler, not just subtraction

Each session must equally ask the inverse question: **what would become easy or possible if this were simpler?** Replacing a bespoke multi-part system with one simple seam often unlocks capability that was previously too expensive. The LLM-structure work is itself the example: one typed call over a transcript didn't just delete heuristics — it made richer section data, new analyses, and fast iteration cheap. Improvements in functionality driven by simplifying carry equal weight with removals in every findings doc (see the "Opportunities unlocked" section of the template).

## 2. Ground rules for every session

- **Findings doc only — no code changes in review sessions.** Implementation happens later, from the consolidated backlog.
- **Removals are flagged, never decided.** Each removal candidate is written up with the cost of keeping vs. the cost/risk of removing. Nothing becomes agreed work until signed off in the Phase 8 wrap-up.
- **Full vertical scope.** Each domain session reviews everything the domain owns: services, jobs, models and their migration history, console commands, config files, Livewire/Blade UI, routes, and tests. Assess whether the domain's tests are proportionate (over-tested trivia, under-tested seams) as part of the vertical.
- **Check prior art before rediscovering.** `docs/plans/SIMPLIFICATION-PLAN.md` (active execution tracker), `docs/architecture/simplification-backlog.md`, and the April backlog (`docs/archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md`, `docs/archived-plans/APRIL-2026-REVIEW-REMAINING-WORK-2026-05-14.md`) already record known items. Findings docs should reference these rather than duplicate them, and note where a known item's status has changed.
- **Out-of-scope observations get parked, not chased.** Record them in the findings doc under "Out of scope, noted for Phase N".
- **Findings docs live in** `docs/reviews/july-2026-simplification/`, named `<area>-review-YYYY-MM-DD.md`.

### Findings-doc template

Every session's output follows this structure:

1. **Scope reviewed** — directories, files, routes, tables covered.
2. **What this area is for** — the business purpose in plain language; what the church/operators actually get from it.
3. **Complexity inventory** — sizes, counts, parallel implementations, dependency tangles.
4. **Findings** — each with evidence (file paths, line counts, duplication examples) and a simplification direction consistent with the doctrine.
5. **Opportunities unlocked** — functionality improvements that simplification would make cheap. Weighted equally with removals.
6. **Removal candidates (needs decision)** — cost of keeping vs. cost/risk of removing, per candidate.
7. **Quick wins** — low-risk items implementable in under an hour each.
8. **Open questions for the user** — anything requiring domain knowledge the code can't answer (e.g. "does anyone use X?").
9. **Out of scope, noted for Phase N** — parked cross-domain observations.

## 3. Phase overview

| Phase | Area | Depth | Why this order |
|-------|------|-------|----------------|
| 1 | Media processing pipeline | Deep | Largest, most complex subsystem; highest payoff |
| 2 | Church service structure & sections | Deep | Direct continuation of the LLM exemplar; retirement decisions pending |
| 3 | Sermons domain | Deep | Core public value; storage-service sprawl |
| 4 | Songs domain | Light | Contained; legacy importer question |
| 5 | Public site & read path | Medium | Presentation-layer sprawl across four locations |
| 6 | Admin & Livewire surface | Medium | 52 components; consistency and fragmentation |
| 7 | Platform, operations & housekeeping | Medium | The residue no domain owns |
| 8 | Wrap-up: consolidation & decisions | — | Backlog + removal sign-offs |
| 9 | Technical code-quality review | Medium | Gated: runs after the Phase 8 backlog's structural work has landed, so it reviews the surviving code |

Phases 1–3 are the deep ones and each deserves an unhurried session. Phases 4–7 should be lighter. Sessions 1–7 are independent — any order works if priorities shift — but the listed order front-loads the biggest complexity. **Phase 9 is the one gated phase**: it deliberately waits until the agreed structural simplification has been implemented, because quality-polishing code that is about to be deleted or consolidated is wasted effort.

## 4. Session briefs

Each brief below is self-contained and intended to be pasted as the opening prompt of its own session, together with a pointer to this document for the doctrine, ground rules, and template.

---

### Phase 1 — Media processing pipeline

> Review the media processing pipeline of this Laravel church-site project for simplification, as Phase 1 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/media-processing-pipeline-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** `app/Services/Media/` (Audio, Video, Thumbnail subfolders), `app/Services/Processing/`, the pipeline jobs in `app/Jobs/`, `config/media-processing.php`, `config/thumbnail-generation.php`, models `LivestreamSegment`, `MediaProcessingLog`, `SermonProcessingStep`, the media upload API routes, and this domain's tests.
>
> **Known accretion signals:** `HistoricVideoImporter.php` (~1,100 lines), `ProcessingPhaseRegistry.php` (~1,100 lines), `ThumbnailCanvasComposer.php` (~950 lines), `VisualAnalysisService.php` (~880 lines), `ThumbnailGenerationService.php` (~800 lines), `VideoSegmentationService.php` (~760 lines). Three parallel logging paths: `ProcessingLogService`, `SermonProcessingLogger`, and `app/Logging`.
>
> **Critical-friend questions:** Does speaker identification (SpeakerProfile/SpeakerSample) earn its complexity — what operator decision does it inform? Do RMS and visual analysis survive an LLM-first world, or are they exactly the heuristics the LLM structure path replaces? Is a ~950-line thumbnail canvas composer proportionate to the value of sermon thumbnails? How many processing phases does the registry really need, and does its size reflect essential orchestration or accumulated special cases? Why three logging paths?
>
> **Opportunities to look for:** Would an LLM-first pass over full-service transcripts yield better speaker/segment data than the bespoke RMS/visual stack, and unlock things the current stack can't do (e.g. content-aware chaptering, highlight extraction)? Would a simpler phase model make it trivial to add new processing outputs?

---

### Phase 2 — Church service structure & sections

> Review the church-service structure and section domain for simplification, as Phase 2 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/church-service-structure-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** `app/Services/ChurchService/` including the new `Structure/` LLM path, the order-of-service email import (`app/Actions/InboundEmail`, the Mailgun webhook route), the service review workflow and its Livewire components, `config/service-tracking.php`, models `ChurchService`, `ChurchServiceItem`, `ServiceSection`, and this domain's tests. Read `docs/plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` first — this domain is the exemplar's home and its phase 6 (promote LLM path, retire heuristics) is still pending.
>
> **Known accretion signals:** `ChurchServiceItemSyncService.php` (~910 lines), `StructuralSectionAligner.php` (~780), `SpeechSectionClassificationService.php` (~620), `SongSectionAligner.php` (~550), `StructureMergePolicy.php` (~380) — multiple aligners/mergers doing similar section-matching work.
>
> **Critical-friend questions:** The big one: **what is the concrete path to retiring the heuristic classifier**, and which aligners/mergers/policies become dead code once LLM-first is primary? Map the dependency graph: which of the ~35 files serve only the heuristic path? Is the shadow/eval tooling (`StructureEvaluateCommand`, `StructureShadowReportCommand`) permanent infrastructure or scaffolding to delete after promotion? Does the review workflow have more states/steps than the operators actually use?
>
> **Opportunities to look for:** Once structure comes from one typed LLM call, what becomes cheap — richer section metadata, better song matching, automatic service summaries? Does simplifying the sync seam make the OOS email import simpler too?

---

### Phase 3 — Sermons domain

> Review the sermons domain for simplification, as Phase 3 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/sermons-domain-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** `app/Services/Sermon/`, `app/Services/Public/SermonRepository.php`, the podcast feed, sermon analysis (scripture/series/keypoints) and its adapters, sermon admin Livewire components, `config/sermons.php`, `config/podcast.php`, models `Sermon`, `SermonProcessingStep`, `SermonScriptureFilter`, `Preacher`/`PreacherAlias`, and this domain's tests. Check `docs/plans/SIMPLIFICATION-PLAN.md` Phase 9 first — the legacy-storage migration status is already tracked there.
>
> **Known accretion signals:** three storage-related services (`SermonStorageService` ~650 lines, `SermonStorageMaintenanceService` ~660, plus the `MoveSermonToPrivateStorage` job), `SermonCreationService.php` (~750), `LegacySermonImporter.php` (~530), `MockSermonAnalysisService.php` (~460, the codebase's heaviest commented-out-code file), and spent-looking commands (`MigrateSermonStorageCommand`, `GenerateProdSermonPatchCommand`, `PreacherCutoverCommand`).
>
> **Critical-friend questions:** What is the actual sermon storage lifecycle, and can one service own it end to end? Is the legacy import path spent — has the historical import finished for good? Is the processing-step granularity serving operators (do they act on individual steps?) or is it accumulated instrumentation? Is `SermonRepository` (~650 lines) a read model or a second service layer?
>
> **Opportunities to look for:** With one storage service and clean typed boundaries, what gets easier — semantic sermon search (see `docs/plans/SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md`), richer podcast metadata, faster publication?

---

### Phase 4 — Songs domain

> Review the songs domain for simplification, as Phase 4 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/songs-domain-review-2026-07-02.md` (adjust date). No code changes. This is a lighter session than Phases 1–3.
>
> **Scope:** `app/Services/Song/` including the `Sync/` reconcilers, OpenLP parsing, song clustering and usage sync, song admin/public Livewire components, models `Song`, `SongAuthor`, `SongBook`, `SongVideo`, and this domain's tests.
>
> **Known accretion signals:** `LegacyPlayDateSongUsageImporter.php` (~430 lines), `LegacySongReconciler.php` (~420).
>
> **Critical-friend questions:** Is song clustering / usage analytics actually consumed by anyone — which page or decision uses it? Are the legacy reconcilers and importers spent one-offs? Does the song-matching logic here duplicate the section/song alignment work reviewed in Phase 2, and should there be one matcher?
>
> **Opportunities to look for:** If song matching became one shared, simple service, what improves — better "songs we sang" pages, usage-informed song suggestions for service planning?

---

### Phase 5 — Public site & read path

> Review the public site and its read path for simplification, as Phase 5 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/public-site-read-path-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** `app/Services/Public/`, the Pages CMS (model `Page`, page routes/views), meetings and calendar (models `Meeting`, `CalendarEvent`, Google Calendar sync in `app/Services/Calendar/`), the members area, sitemap/SEO (`app/Services/Public/SitemapService.php`, `app/Seo/`), and — the central item — the **presentation-layer sprawl**: `app/Presenters/`, `app/Seo/`, `app/View/Presenters/`, `app/View/Composers/`, and the read-model Data objects in `app/Data/` (e.g. `PublicPageReadModel`, `PublicMeetingReadModel`, `ChurchServiceShowReadModel`, `PodcastFeedItemReadModel`). Include this domain's tests. The April review's public-read-side doc (`docs/april-2026-review/public-read-side-and-read-path-review-2026-04-16.md`) covers earlier findings — check what was and wasn't actioned.
>
> **Critical-friend questions:** Can four presentation layers become one convention — what would a single rule ("view data comes from X") delete? Is the Pages CMS earning its flexibility, or are the areas (christ/church/community/members) effectively static and better served by plain Blade? Is a ~520-line `SitemapService` proportionate? Is the Google Calendar sync bidirectional complexity matched by actual use?
>
> **Opportunities to look for:** Would one presentation convention make site-wide improvements trivial — consistent SEO/metadata, structured data, Open Graph images, a design-system pass? Would a simpler read path make page-speed work cheap?

---

### Phase 6 — Admin & Livewire surface

> Review the admin area and Livewire component architecture for simplification, as Phase 6 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/admin-livewire-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** `app/Livewire/` (all ~52 components, `Admin/` especially), the shared `Traits/` (WithAdminAuthorization/Delete/Save, WithFilterableListing, WithSortableListing, WithNotifications, WithPageOptions), `Forms/`, admin Blade views and partials (`resources/views/livewire/`, note church-services partials), and Livewire tests. The April Livewire-responsibility review (`docs/april-2026-review/livewire-view-responsibility-review-2026-04-16.md`) is prior art.
>
> **Known accretion signals:** `MediaUpload.php` (~680 lines) plus sibling components `MediaUploadProgress` and `MediaUploadStatus` — one feature possibly split three ways; ChurchServices admin has 9 components and 13 view partials.
>
> **Critical-friend questions:** Do the admin CRUD screens actually share structure through the traits, or is each a snowflake with the traits as a fig leaf? Is the upload component trio one cohesive feature fragmented, and would one component with clear states be simpler? Are there admin screens/filters/options that operators never use?
>
> **Opportunities to look for:** Would a consistent CRUD component pattern make new admin screens near-free? Would consolidating the upload flow enable better operator feedback (progress, retry, validation) than the current split allows?

---

### Phase 7 — Platform, operations & housekeeping

> Review the platform/operations layer and cross-cutting housekeeping for simplification, as Phase 7 of the July 2026 simplification review. Follow the doctrine, ground rules, and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/platform-operations-review-2026-07-02.md` (adjust date). No code changes.
>
> **Scope:** the shared residue no domain owns — `app/Support/`, `app/Providers/`, monitoring/health (`app/Services/Monitoring/`, `config/monitoring.php`, Spatie health/backup), the console command inventory (`app/Console/Commands/`, ~34 commands of which roughly half look like spent migrate/backfill/cutover scripts), **config sprawl** (~38 files in `config/`), **migration churn** (~166 migrations including add-then-revert pairs — assess squashing), the `app/Data/` DTO population (~55 files) for orphans, the Mock* service family as a group, `app/Enums/`, and root-level agent-config file proliferation (`AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `.Jules`, `.agents`, `.gemini`, `.codex`).
>
> **Critical-friend questions:** Which commands are spent — for each migrate/backfill/cutover command, has its migration completed in production? Which config files could merge or die? Should migrations be squashed to a schema dump (Laravel `schema:dump`)? Is the monitoring surface proportionate for a single-church deployment? Are all 55 Data objects referenced?
>
> **Opportunities to look for:** Would a squashed schema and pruned command list make onboarding and CI faster? Would consolidated config make environment differences (local/CI/prod) easier to reason about and safer to change?

---

### Phase 8 — Wrap-up: consolidation & decisions

> Consolidate the July 2026 simplification review, as its wrap-up phase (Phase 9, the code-quality review, follows separately once the resulting structural work has landed). Read every findings doc in `docs/reviews/july-2026-simplification/` plus the active trackers (`docs/plans/SIMPLIFICATION-PLAN.md`, `docs/architecture/simplification-backlog.md`).
>
> Produce **one prioritized backlog** at `docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-<date>.md` (successor in style to `docs/archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md`), reconciling with and superseding overlapping items in the existing trackers rather than duplicating them.
>
> Then: (1) walk the user through **every flagged removal candidate** for an accept/reject decision — use AskUserQuestion, batched by domain; (2) give "opportunities unlocked" items equal billing to removals when prioritizing; (3) sequence agreed work into implementation-sized chunks with dependencies noted (e.g. "retire heuristic classifiers" gates "delete aligners"; "one storage service" gates "delete legacy fallbacks"); (4) roll up any suite-wide test-architecture pattern observed across the per-domain assessments.

---

### Phase 9 — Technical code-quality review (gated: after structural work lands)

> Run a technical code-quality review of this Laravel church-site project, as Phase 9 of the July 2026 simplification review. Follow the ground rules and findings-doc template in `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Output a findings doc at `docs/reviews/july-2026-simplification/code-quality-review-<date>.md`. No code changes in this session.
>
> **Precondition — check before starting:** the structural simplification work agreed in the Phase 8 backlog (`docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-*.md`) should have substantially landed. If large deletions/consolidations are still pending, flag this to the user before proceeding — reviewing code that is scheduled for removal is wasted effort.
>
> **This phase is deliberately different in kind** from Phases 1–7: those asked "should this exist, and what's its simplest shape?"; this one asks "is the surviving code well made?" Line-level and idiom-level attention is in scope here and only here.
>
> **Axes:**
>
> 1. **Static-analysis ratchet.** Larastan currently runs at level 8 (`phpstan.neon`) with an effectively empty baseline. Trial level 9 (`vendor/bin/sail composer phpstan` after bumping `level:`), triage the findings, and recommend: fix, or consciously baseline with per-entry justification. Assess whether level 10 is realistic. The near-zero baseline is an asset — keep it that way.
> 2. **Standards & idioms.** Spatie guideline conformance, PHP 8.4 idioms (constructor promotion, readonly properties, enums over constants), framework modernization. Reconcile with `docs/architecture/laravel-12-modernization-backlog.md` — update/supersede it rather than duplicating it.
> 3. **Dead code.** Unreferenced classes and methods, orphaned `app/Data/` objects, unused config keys, views no route renders, dead routes. Grep-based reference checks are fine; note confidence per item.
> 4. **Test quality.** Slow or flaky tests, over-mocking, weak assertions (asserting no exception vs. asserting behavior), duplicate coverage across Unit/Integration/Feature layers. Build on the per-domain test-proportionality notes from Phases 1–7 and Phase 8's roll-up — don't re-derive them.
> 5. **Dependency hygiene.** Unused or outdated composer and npm packages; open security advisories (there is at least one open Dependabot alert on the default branch).
> 6. **Performance smells.** N+1 queries and hot read paths — the in-repo Debugbar tooling (`debug-using-debugbar` workflow) can capture real request profiles.
>
> **Output:** the standard findings-doc template, **plus a distinguished "Mechanical fixes" section** — items so safe and rote (formatting, dead imports, obvious dead files, baseline-free level-9 fixes) that they can be executed wholesale in a single implementation session without per-item sign-off. Judgment calls and anything behavior-adjacent stay in the normal findings/removal sections.
>
> **Quality gates:** `vendor/bin/sail composer phpstan` (must stay at 0 errors), `vendor/bin/sail bin pint --dirty`, `vendor/bin/sail artisan test --compact`.

---

## 5. Practical notes

- **One session per phase.** Each brief is self-contained; a fresh session needs only the brief and this document.
- **The reviewer sets the date** in findings-doc filenames to the actual session date.
- **Depth calibration:** Phases 1–3 deep, 4 light, 5–7 and 9 medium. If a light phase turns out to be deeper than expected, finish the inventory, record the surprise, and recommend a follow-up rather than overrunning.
- **Phases 1–7 do not chase line-level quality.** Idiom, style, and static-analysis issues that aren't egregious get parked for Phase 9 (note them under "Out of scope, noted for Phase 9" if worth recording at all). This keeps the domain sessions focused on existence and shape, not polish.
- **Test-suite architecture is not a separate phase** (April covered it); each domain session assesses its own tests' proportionality and Phase 8 rolls up anything suite-wide.
- **Weekly change reviews continue independently** — this review is a point-in-time structural pass, not a replacement for the ongoing tech-debt rollups.
