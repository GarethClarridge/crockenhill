# Platform, Operations & Housekeeping — Simplification Review (Phase 7)

Reviewed 2026-07-05, per `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Medium session. No code changes; findings only.

This phase reviews the residue no domain owns. Several items were formally parked here by earlier phases: `ExportVisualMetricsCommand` and `BootstrapSpeakerProfilesCommand` (media review §out-of-scope), `MeetingPhotoMigrationService` / `SanitizesLogData` / `config/calendar.php` dead keys / `Cache::flexible` helper (public-site review), `config/sermons.php` and the Mock* family (sermons review), the `config/service-tracking.php` merge question and the Data census (church-service review), the `play_date` legacy schema residue (songs review), and the admin `phpinfo` route (admin review). Each is dispositioned below.

## 1. Scope reviewed

- **`app/Support/`** — 17 files, ~2,210 lines.
- **`app/Providers/`** — 11 providers, ~620 lines.
- **Monitoring/health** — `app/Services/Monitoring/` (registry, prober, 3 custom checks, ~385 lines), `HealthCheckServiceProvider` (55), `config/monitoring.php` (32), `config/health.php` (169), `config/schedule-monitor.php` (116), `config/backup.php` (405), the schedule in `bootstrap/app.php`, `scripts/post-deploy-smoke.sh`.
- **Console commands** — all 34 files in `app/Console/Commands/` (~4,770 lines) plus their 27 test files (~4,116 lines); the schedule, CI workflows, and deploy scripts as consumers.
- **Config** — all 38 files in `config/` (4,575 lines, 306 distinct `env()` keys), diffed textually against the Laravel framework and vendor-package defaults.
- **Migrations** — 166 files in `database/migrations/`, `database/schema/mysql-schema.sql` (1,222 lines), the 3 test files that `require` migration files directly, `scripts/check-schema-dump-current.sh`.
- **`app/Data/`** — 55 files, referenced-usage census via grep.
- **Mock* services** — 4 files, 751 lines, and their provider bindings.
- **`app/Enums/`** — 31 files, usage census.
- **Root agent config** — `AGENTS.md` (550 lines), `CLAUDE.md` (246), `GEMINI.md` (224), `.Jules/` (13 mission files + 12 journal files), `.agents/skills/`, `.claude/skills/`, `.codex/config.toml`, `.gemini/settings.json`, `jules-setup.sh`, `.env.jules`.
- **Prior art**: `docs/plans/SIMPLIFICATION-PLAN.md` (Phases 9, 13, 25 directly relevant), `docs/architecture/simplification-backlog.md` (PR 1 precedent, PR 15, PR 19), and all six Phase 1–6 findings docs.

## 2. What this area is for

This layer keeps the site running rather than serving members directly: the scheduler and Horizon queues that drive nightly syncs and media jobs, the `/health` endpoint and backups that tell the operator (one person) when something breaks, the deploy-time smoke checks, the console commands that perform operational tasks, and the configuration that makes local/CI/production behave differently on purpose. The agent-config files exist so a fleet of coding agents (Claude Code, Codex, Gemini, and twelve nightly Jules personas) can work on the repo without re-deriving context.

The operator outcome: the church's one production deployment stays up, gets backed up, and alerts a single mailbox when a scheduled task, queue, disk, or public route fails.

## 3. Complexity inventory

- **34 console commands, of which 19 (56%) are one-shot migrate/backfill/cutover/calibration tools** (~2,855 command lines + companion services + ~2,000 test lines). Only 6 commands are wired to anything automatic (4 scheduled, 2 in deploy). The backlog's PR 1 records that **7 one-time migration commands were already deleted once** — this is the second generation of the same accumulation.
- **166 migrations, 62 of which (37%) are integrity/index/constraint churn** (`fortify_*`, `*_integrity_*`, `formalize_*`, `*_index_*`), heavily concentrated in Feb–Jun 2026 (125 files, 75% of the total). The churn has a specific engine: the nightly **Warden** Jules agent is chartered to ship one additive integrity migration per run. It has produced at least two add-then-revert pairs (`2026_06_14` adds FK indexes → `2026_06_18` drops them as redundant; `2026_06_16` adds an index → `2026_06_18` drops it) and one bulk correction (`2026_04_21_drop_overly_strict_check_constraints`).
- **The schema dump is fully current** — `mysql-schema.sql` records every migration through the newest (`2026_06_28`), and CI has a drift gate (`check-schema-dump-current.sh`, plan Phase 13 ✅). Squashing is therefore already half-done; only pruning is outstanding.
- **38 config files, 306 distinct env keys.** At least 5 files are byte-for-byte or near-stock copies of framework/package defaults; 2 more have drifted *behind* current defaults.
- **55 Data objects — zero orphans** (census below). 31 enums — zero orphans.
- **4 Mock* services (751 lines)**, all bound via config-switched providers; one (`MockSermonAnalysisService`, 463 lines) is 62% of the family by weight.
- **~1,090 lines of timeline/flow assembly in `app/Support/`** across five classes, four of which funnel into a single admin screen.
- **~25 agent-instruction files at the root**, with the generated `<laravel-boost-guidelines>` block held in three copies and the boost-managed skills tree held in two identical copies (`.agents/skills/` and `.claude/skills/`, 9 skills byte-identical).

## 4. Findings

### F1. The command inventory is two-thirds residue; the live surface is 15 commands

Full classification (companion services/tests counted in the owning phase's doc where one exists):

**Wired to automation (6):** `SyncGoogleCalendarCommand`, `CleanupOrphanedTempFiles`, `CleanupUnpublishedSectionAssetsCommand`, `RefreshScripturePassages` (scheduled in `bootstrap/app.php`); `GenerateSitemap` (deploy + rollback workflows); `ListRouteCanariesCommand` (feeds `scripts/post-deploy-smoke.sh:92`).

**Operational, on-demand, recurring value (7):** `CreateApiToken`, `ProcessVideoCommand` (manual sermon creation from segments), `SyncSongsCommand`, `LinkSongsCommand`, `SyncSermonScriptureFilters`, `AuditSchemaGuardrailsCommand` (pre-flight for Warden-style constraint work), `AssessSermonVideoQualityCommand` (single-sermon mode is operational; its backfill mode is spent — sermons review).

**Rollout scaffolding (2):** `StructureEvaluateCommand` (574 lines), `StructureShadowReportCommand` (231) — Phase 2 owns their retirement with the LLM-first promotion; not re-litigated here.

**Spent one-shot candidates (19):** the remaining commands are all migrate/backfill/cutover/calibration tools. Nine are already dispositioned by the sermons review (R1, R3–R6: the five storage-migration commands, `ImportLegacySermonBatchCommand`, `GenerateProdSermonPatchCommand`, `PreacherCutoverCommand`, plus `EnrichSermonsScripture` as a scripture backfill) and one by the songs review (`ImportLegacySongUsageCommand`). **Phase 7 newly dispositions the other nine** — see §6 for the decision table:

| Command | Lines | Evidence of spent-ness |
|---|---|---|
| `ConvertJpgToWebp` | 438 | One-off dev tool that converts images *and rewrites codebase references*; last substantive use predates 2026 |
| `BootstrapSpeakerProfilesCommand` | 240 | "Bootstrap speaker profiles from historical sermon audio" — a one-time seeding of a now-populated table (parked here by media review) |
| `ImportHistoricVideoBatchCommand` | 213 | Historic video import; media review covers its 1,100-line `HistoricVideoImporter` companion |
| `ExtractVideoFrames` | 198 | "for visual analysis debugging" — a dev probe for the heuristic path the LLM-first work replaces |
| `ImportOpenLpDirectoryCommand` | 159 | "Bulk import **historic** OpenLP archives" — one-time adoption backfill (routine sync is `SyncSongsCommand`) |
| `ExportVisualMetricsCommand` | 122 | Calibration exporter for visual song detection — heuristic-path tooling; dies with heuristic retirement regardless (parked here by media review) |
| `BackfillMediaProcessingIdentityCommand` | 109 | Backfills `extracted_date/service` "for historical media logs" — completed data repair from Feb 2026 |
| `FixUploadDirectories` | 53 | mkdir/chmod for storage dirs; deploy provisioning owns this now |
| `MeetingMigratePhotosCommand` + `MeetingPhotoMigrationService` | 37 + 153 | Legacy photo migration to Media Library (Feb–Mar 2026); the service still sits in the `app/Services/` root (parked here by public-site review) |

**Direction** (doctrine 6): confirm production completion per tool (§8 Q1), delete command + companion + tests, and record the git tag in the PR description as the re-run escape hatch. Beyond this sweep, adopt a **retirement convention** so a third generation doesn't accumulate: every new one-shot command declares its expected deletion trigger in its docblock (e.g. "delete after the X backfill is confirmed in prod"), and the weekly tech-debt rollup treats any one-shot older than a quarter as a default-delete.

### F2. Config: five files can die today, two have drifted behind the framework, and four tiny files could be two

Diffing all 38 files against framework/vendor defaults:

**Effectively stock — delete, defaults apply (verified):** `blade-heroicons.php` (0 changed lines), `media-library.php` (1 comment line), `schedule-monitor.php` (import-style only), `view.php` (cosmetic `realpath` wrapper), `broadcasting.php` (pre-Reverb skeleton copy; the app has no broadcasting — `resources/js/bootstrap.js` Echo block is commented out, no events broadcast). Laravel 13 merges framework defaults when a file is absent, and an unmodified published package config is identical to the package fallback. ~590 lines gone with zero behavior change.

**Drifted behind defaults — decide, don't just delete:** `hashing.php` predates two framework improvements (`HASH_VERIFY` now defaults `true`; `rehash_on_login => true` is absent entirely), so deleting it *changes behavior* — almost certainly for the better, but it should be a conscious adoption. `debugbar.php` (334 lines) is published from an older package version (missing `collect_jobs`, the per-collector env wrappers) with one real customization (`hide_empty_tabs => true`); the project already drives Debugbar by env (`DEBUGBAR_ENABLED=false` in `.env.playwright.ci`), so deleting the file and keeping env control is viable. `livewire.php` carries genuinely load-bearing keys (`component_layout => layouts.admin` — the admin-200 smoke test depends on it) but is missing the v4 keys (`component_locations`, `component_namespaces`); regenerate and re-apply the overrides rather than leaving a stale shape. `blade-icons.php` (166 changed lines) needs the same version-drift-vs-customization triage.

**Dead keys in live files:** `config/auth.php` defines an `api` guard (`driver => sanctum`) that nothing uses — every protected route uses `auth:sanctum` middleware, which does not read it; with that block gone, `auth.php` is stock and can itself be deleted. `config/calendar.php` `uncategorized_slug` and `performance.cache_duration` are confirmed unread (public-site review's parked item — verified here).

**Tiny-file consolidation:** `sermons.php` is 7 lines wrapping one flag (`childrens_talks.public`); `opening-hours.php` (28) and `organization.php` (39) are both static church facts for Schema.org. Merge all three into one `church.php` (or fold sermons' flag into `organization.php`) — three files → one, no env changes. `monitoring.php` (32 lines, 3 canary keys) could fold into `health.php` as a top-level custom block, but this is optional polish. **On the church-service review's parked question:** `service-tracking.php` should *not* merge into `media-processing.php` — it is a coherent, live, single-domain file; the sprawl problem runs the other way (`media-processing.php` at 426 lines spans transcription, structure, song-matching, and thumbnails, and Phases 1–2 own its content).

`playwright.php`, `cors.php`, `redirects.php`, `podcast.php`, `horizon.php`, `health.php`, `backup.php`, and the remaining framework files carry genuine customization and earn their keep. `SanitizesLogData` (public-site parking) has 10+ consumers — alive, no action.

### F3. Migrations: squash to the dump — the project is unusually prune-ready

Three facts make this cheap:

1. **The dump is current and guarded.** `mysql-schema.sql` includes every one of the 166 migrations (through `2026_06_28`), and `check-schema-dump-current.sh` fails CI on drift. `schema:dump` alone already saved ~17s on the parallel suite when adopted.
2. **The blocker has shrunk.** `--prune` previously broke 21 tests; today exactly **3 test files (11 test methods)** `require` migration files directly (`FormalizePagesAreaColumnMigrationTest`, `CorrectiveSchemaMigrationsTest`, `AddIsAdminToUsersTableIfMissingMigrationTest`). All three protect *corrective* migrations that have already run in production; once the files are pruned, the dump *is* the encoded outcome and these tests protect nothing reachable. Delete them with the prune.
3. **Data migrations are already inert on fresh databases.** Because the dump loads first and only post-dump migrations run, data-bearing migrations like `2026_01_21_170857_create_pages_for_meetings` already contribute nothing to test databases — pruning changes nothing there. Production has them recorded in its `migrations` table, so deleted files are never re-run.

**Direction:** run `schema:dump --prune`, delete the 3 test files, keep the drift gate. 166 files → 0 (until the next migration lands). The add-then-revert pairs and the 62-file integrity churn collapse into the dump instead of being replayed forever.

**And set a cadence.** Warden alone adds ~2–3 migrations/week, so the count regrows by ~100/year. A quarterly re-squash (one command + one commit, protected by the existing drift gate) keeps the directory permanently small. Consider also tightening Warden's mission to check for an existing covering index before proposing one — the June add-then-revert pair was exactly that failure, and its own migration docblock records the lesson.

### F4. `app/Support/` hides a five-class, ~1,090-line timeline stack whose presentation half serves one admin screen

The utility core of `Support/` is healthy — `Path` (28 consumers), `BibleCanon` (6), `ServiceSectionConfidence` (16), `OpenAiChatPayload` (6), `CancellationChecker`, `MediaProcessingAccess`, `ParallelTestingProcessLimiter` (must live in app bootstrap) are small, multi-consumer, and correctly placed.

The exception is the church-service timeline family: `ChurchServiceProcessingTimeline` (159 — the step registry, legitimately consumed by 12 pipeline jobs), plus a presentation chain of `ProcessingRunTimelineBuilder` (192), `ServiceRecordTimeline` (349), `ServiceFlowBuilder` (337), and `ServiceTimelineBuilder` (49) — ~930 lines whose *only* production consumers funnel into `ChurchServiceShowPresenter` and two admin Blade partials. `ServiceTimelineBuilder` is a 49-line pass-through over the other two. This is church-service domain code filed under the platform junk drawer (doctrine 7), which is why neither Phase 2 (scoped to `app/Services/ChurchService/`) nor Phase 6 (scoped to Livewire) reviewed it.

**Direction:** relocate the four presentation classes to the church-service domain (e.g. `app/Services/ChurchService/Timeline/` or beside `ChurchServiceShowPresenter`), and merge `ServiceTimelineBuilder` into `ServiceFlowBuilder` while moving. Whether the *content* (three distinct timeline shapes for one screen) is proportionate belongs to the Phase 2/6 follow-ups on the show screen — flag for Phase 8 sequencing alongside the heuristic retirement, since `ProcessingRunTimelineBuilder` renders heuristic-pipeline steps that may thin out.

### F5. Monitoring is proportionate — recently rationalized, one surface, reasoning documented

Direct answer to the brief: **yes, the surface fits a single-church deployment, and no cuts are recommended.** The June 2026 P1–P3 work already did this phase's job: one `/health` endpoint aggregates 9 checks (DB, Redis ×2, cache, Horizon-not-QueueCheck, temp disk, scheduled-task outcomes, scheduler heartbeat with external ping, route canaries), schedule-monitor feeds `ScheduledTasksCheck`, the backup trio runs off-hours, and every non-obvious choice carries its rationale in `HealthCheckServiceProvider`'s docblocks (HorizonCheck vs QueueCheck starvation, canary skip under `artisan serve`). The canary machinery (~270 lines across registry/prober/check/command/DTO) is dual-purpose — post-deploy smoke *and* scheduled probing — which justifies its registry indirection.

Residue is minor: `config/monitoring.php` exists for one check (fold into `health.php` if touched anyway, F2); the retired `monitoring:check-canaries` command is referenced only in comments. The admin `phpinfo` route parked by Phase 6 turns out to be inside the `app()->isLocal()` block with `auth`+`admin` middleware — acceptable as-is; delete only as drive-by tidiness.

### F6. `app/Data/`: zero orphans; the census closes clean

Every one of the 55 Data classes has at least one production reference beyond its own file. The weakest (single-reference) cases are all legitimate producer/consumer pairs — `PodcastFeedItemReadModel`→`PodcastFeedService`, `PublicPageReadModel`→its cache, `SongCatalogSyncReport`→the sync service, `ApiBiblePassageResult`→`ApiBibleClient`, and the metadata sub-objects composed inside `ChurchServiceImportMetadata`/`ServiceSectionMetadata`. The same census over `app/Enums/` (31 files) also found zero orphans; `ChurchServiceItemSource` and the metadata casts specifically (church-service review's parked question) are alive at 14 and multiple references respectively. The flat 55-file namespace spans six domains and would read better under domain subfolders, but that is Phase 9 shape-polish, not existence — parked.

### F7. The Mock* family is the doctrine done right, with one known outlier

Four mocks (`MockServiceStructureService` 150, `MockTranscriptionService` 76, `MockServiceTranscriptionService` 62, `MockSermonAnalysisService` 463), all bound through config-switched provider closures (`AiServiceProvider`, `MediaProcessingServiceProvider`) with CI defaulting to mock — this *is* doctrine 2, and `MockServiceStructureService` is the right template (small, deterministic, fixture-driven). The group-level observations: (a) `MockSermonAnalysisService` at 463 lines is a heuristic simulator, not a stub — already sermons review R7, endorsed here as the family's one fix; (b) the `SermonAnalysisInterface` binding is the only one using if/else rather than the `match` idiom, and the only one without an explicit unknown-value exception — trivial consistency item for Phase 9; (c) `NullSpeakerIdentificationService` (backlog PR 19) is effectively a fifth family member and should adopt whatever convention the family settles on when its feature gates retire.

### F8. Agent-config: the canonical doc is stale where it matters most, and one skills tree is a byte-identical duplicate

The layered design is sound — `AGENTS.md` as cross-tool truth, thin tool addenda, per-persona Jules missions with separate journals (`.Jules/agents/*.md` vs `.Jules/*.md` — missions vs learning logs, not duplicates), and tiny MCP wiring in `.codex`/`.gemini`. Three concrete problems:

1. **`AGENTS.md`'s "Key Services" section lists three services that no longer exist** — `ProcessingRouter`, `VideoProcessingService`, and `SermonProcessingService` (the last inlined by backlog PR 11) — and its "Processing Pipelines" narrative describes the pre-LLM-first RMS flow as current. This file is read *first* by every agent, including the twelve autonomous nightly personas; stale architecture here actively misleads the fleet that generates ~40% of the migration churn (F3). Doc drift in the source-of-truth file is the highest-leverage doc fix in the repo.
2. **The generated `<laravel-boost-guidelines>` block exists in three copies** (AGENTS.md, CLAUDE.md, GEMINI.md) that age independently between `boost:install` runs. Tool-imposed, so acceptance is reasonable — but the refresh step in the CLAUDE.md notes should say "refreshes all three" so no copy is regenerated alone.
3. **`.agents/skills/` and `.claude/skills/` hold nine byte-identical skill trees**, and `.claude/skills/` additionally contains a stray `frontend-design.md` file duplicating `frontend-design/SKILL.md`. The two trees serve different tools' discovery paths (boost syncs both), so the duplication itself is tool-imposed; the stray file is just clutter to delete.

## 5. Opportunities unlocked

1. **Squash → absorbing the agent fleet becomes free (F3).** A quarterly one-command re-squash means Warden-style autonomous integrity work no longer has a compounding cost; the operator can keep the nightly fleet without the migrations directory becoming an archaeology site. Fresh CI/local setup loads one 1,222-line dump instead of replaying 166 files, and the 11 corrective-migration tests stop running on every CI pass.
2. **Pruned commands → `artisan list` becomes an honest operations menu (F1).** Fifteen live commands, each of which does something an operator might actually want today. That is real onboarding and incident-response value: no more deciding mid-incident whether `sermons:migrate-storage` is safe to run. The retirement-trigger convention keeps it honest permanently.
3. **Deleted stock config → framework improvements arrive automatically (F2).** `hashing.php` is the proof case: the project silently missed `rehash_on_login` because a stale published copy shadowed the default. Every deleted stock file is a class of upgrade-drift that can never happen again, and 306 env keys becomes easier to reason about with ~35 fewer stock-file keys shadowing defaults.
4. **An accurate `AGENTS.md` → better output from every agent (F8).** The autonomous personas act on what the doc says exists. Fixing the Key Services section is a 15-minute change that improves every nightly run's grounding — the cheapest quality lever found in this review.

## 6. Removal candidates (needs decision)

Sermons R1–R7 and songs F4 candidates are not re-tabled; decisions below are Phase 7's own.

| # | Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|---|
| P1 | Nine unowned one-shot commands (F1 table: `ConvertJpgToWebp`, `BootstrapSpeakerProfiles`, `ImportHistoricVideoBatch`, `ExtractVideoFrames`, `ImportOpenLpDirectory`, `ExportVisualMetrics`, `BackfillMediaProcessingIdentity`, `FixUploadDirectories`, `MeetingMigratePhotos`+service) + tests (~1,720 prod lines + tests) | Second-generation dead weight; CI runs their tests forever; `artisan list` noise | Per-command prod confirmation needed (§8 Q1); git history is the re-run path. `ExportVisualMetrics`/`ExtractVideoFrames` are additionally gated on Phase 2 heuristic retirement if kept for calibration |
| P2 | All 166 migration files + the 3 corrective-migration test files (11 tests) via `schema:dump --prune` | 62-file churn replayed on every fresh DB; add-then-revert pairs preserved forever; regrows ~100/year | Near-zero: dump is current + CI-gated; prod `migrations` table already records all files. Only cost: `git log` on a migration file needs the pre-prune history |
| P3 | Stock config files: `blade-heroicons`, `media-library`, `schedule-monitor`, `view`, `broadcasting` (~590 lines) | Shadowed defaults; upgrade drift risk (F2) | None measurable — verified byte-/semantics-identical to defaults; `config:show` spot-check per file on the PR |
| P4 | `hashing.php` (adopting `HASH_VERIFY=true` + `rehash_on_login`) and `debugbar.php` (env-only control, losing `hide_empty_tabs`) | Behavioral drift persists silently | Small, *positive* behavior changes — but they are behavior changes; needs a conscious yes |
| P5 | `auth.php` `api` guard block; `calendar.php` `uncategorized_slug` + `performance.cache_duration` | Dead keys imply capabilities that don't exist | None — grep-verified unread (both) |
| P6 | Merge `sermons.php` + `opening-hours.php` + `organization.php` → one file; optionally fold `monitoring.php` into `health.php` | Four files for ~100 lines of static facts | Trivial; touch points are a handful of `config()` call sites |

## 7. Quick wins

1. Delete the stray `.claude/skills/frontend-design.md` (duplicate of `frontend-design/SKILL.md`) — 5 min.
2. Fix `AGENTS.md` Key Services + pipeline narrative (F8.1) — 15 min, highest leverage per minute in this review.
3. Delete `blade-heroicons.php` and `schedule-monitor.php` (the two zero-risk stock files) with a `config:show` check — 20 min. (`media-library`/`view`/`broadcasting` ride P3.)
4. Remove the two dead `calendar.php` keys and the `auth.php` `api` guard block — 15 min.
5. Add the retirement-trigger convention line for one-shot commands to `AGENTS.md` — 10 min.
6. Note in `CLAUDE.md`'s boost section that `boost:install` must refresh all three guideline copies together — 5 min.

## 8. Open questions for the user

1. **Per-command production status (P1)** — for each of the nine: has it been run to completion in production, and is a re-run plausible? Specifically: (a) was the WebP conversion (`ConvertJpgToWebp`) finished? (b) were speaker profiles bootstrapped (`speaker-profiles:bootstrap`)? (c) is the historic video import done, or are more batches expected? (d) are more historic OpenLP archives expected (`service-tracking:import-openlp-services`), or is `sync-songs` the only live path now? (e) was the media-log identity backfill run? (f) were meeting photos migrated? (g) does server provisioning still rely on `upload:fix-directories`?
2. **Squash sign-off (P2)** — comfortable deleting the 3 corrective-migration test files with the prune, and adopting a quarterly re-squash?
3. **Warden's charter** — keep the nightly integrity agent running as-is, tighten its mission (require checking existing covering indexes), or reduce cadence now that the schema is heavily fortified? This decides how fast F3's churn regrows.
4. **`hashing.php`/`debugbar.php` (P4)** — adopt current framework/package defaults as described?
5. **Is anything besides `component_layout` and `class_namespace` intentionally customized in `livewire.php`?** Determines whether regenerating it to the v4 shape is mechanical.

## 9. Out of scope, noted for other phases

- **Phase 2 / Phase 8 sequencing:** `StructureEvaluateCommand` / `StructureShadowReportCommand` retirement; `ExtractVideoFrames` + `ExportVisualMetricsCommand` also die with the heuristic path if not deleted sooner; `ProcessingRunTimelineBuilder`'s heuristic-step rendering (F4) thins after retirement.
- **Phases 1/2 backlogs:** `media-processing.php`'s internal multi-domain sprawl (the *content* of the biggest config file); this phase only rules on the file-level merge question (keep `service-tracking.php` separate).
- **Phase 8 roll-up:** the spent-tooling test weight (~2,000 lines here + ~2,070 in sermons) as a suite-wide pattern; the `Cache::flexible` shared-helper idea (8 call sites, public-site parking) alongside the `AdminAttentionCounts` caching contract from the admin review.
- **Phase 9 (line-level):** `app/Data/` domain-subfolder reorganization (F6); the `SermonAnalysisInterface` if/else→`match` binding consistency (F7); `blade-icons.php` drift triage if not resolved under P3/P4.
- **Songs domain (already tabled there):** `play_date` table and legacy song columns in the schema dump — the squash (P2) does not affect that decision either way, since they live in the dump itself.
