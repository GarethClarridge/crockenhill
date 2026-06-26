# Technical Debt Remediation Plan (2026-06-18)

Created 2026-06-18 from a churn × complexity × security analysis of the codebase (git history over the last 90 days, `composer audit`, `composer outdated --direct`, PHPStan config, file-size complexity proxies, and test-coverage mapping of the hotspots).

This plan turns each finding into an ordered, verifiable phase. Phases are numbered `D1…D6` (D for *debt*) and ordered by priority. Each phase is independently shippable as a single PR.

## Summary of findings

This is a **healthy, high-discipline codebase**, not a debt-laden one. The supporting evidence:

- **PHPStan level 8 with a 0-error baseline** ([phpstan.neon](../../phpstan.neon)) — only one surgical `ignoreErrors` entry for a known Spatie type quirk.
- **Tests outnumber source** (~682 test files vs ~521 app files) and the core domain is saturated with coverage (**135 sermon-related test files**).
- **Zero `TODO`/`FIXME`/`HACK`/`XXX` markers** across `app/`, `resources/`, `routes/`, `config/`.
- Mature CI/CD: `pr.yml`, `nightly.yml`, `deploy.yml`, **and a `rollback.yml`**.

So the debt is **concentrated, not systemic**. It clusters in exactly two places: (1) one transitive security CVE, and (2) the **Sermon presentation/domain layer** — the app's core product surface and its highest-churn region.

| Category | Severity | Phase | Notes |
|---|---|---|---|
| Dependencies (security) | 🔴 Critical | D1 | One transitive CVE on the S3/Spaces storage path. |
| Infrastructure | 🟠 High | D2 | `composer audit` runs nightly-only, not on the PR gate. |
| Design | 🟠 High | D3 | `SermonViewPresenter` god-presenter (737 ln / 43 methods / 51 commits in 90d). |
| Design | 🟡 Medium | D4 | `Sermon` model breadth (validation + processing-state delegation). |
| Design | 🟡 Medium | D5 | `MediaProcessingLog` model size (697 ln). |
| Dependencies (freshness) | 🟢 Low | D6 | All current except intentionally-deferred Symfony 8 majors. |
| Code quality / Tests / Docs / Performance | 🟢 Negligible | — | No action — see [Non-Goals](#non-goals-what-is-not-debt). |

## Goal

Pay down the **concentrated interest** — close the one security gap, close the CI gap that let it linger, and decompose the single god-presenter that taxes every sermon change — **without disturbing the parts that are already healthy**. Hold every existing quality bar (PHPStan 0, real coverage, British-English strings) byte-for-byte while doing so.

## Guardrails (read before starting any phase)

- **Do not refactor stable complexity.** The 1,000+ line files in the media pipeline (`HistoricVideoImporter`, `ProcessingPhaseRegistry`, `ThumbnailCanvasComposer`, …) are large *because the domain is*, and they churn 1–3×/90d. Refactoring stable, tested code is pure cost. See [Non-Goals](#non-goals-what-is-not-debt).
- **PHPStan stays at 0 errors, baseline stays empty.** No new `ignoreErrors`, no growing `phpstan-baseline.neon`. If a refactor surfaces a type issue, fix the type, don't suppress it.
- **Re-shape, don't re-cover.** D3–D5 move behaviour between classes; they must not delete or weaken a single assertion. The existing hotspot tests are the safety net that makes these refactors safe — keep them green at every step.
- **Preserve public facades.** `SermonViewPresenter::present()` / `presentForApi()` / `presentCollection()` are called from controllers, presenters, and Blade. Decomposition happens *behind* those signatures; callers must not change.
- **One phase per PR.** Each phase below is sized to be reviewed and reverted independently. Do **not** bundle D1 (security) with anything else.
- **British English** in any user-facing string or test assertion you touch (`categorised`, `authorised`, …).

## Quality gates (run for every phase that touches the relevant area)

1. `vendor/bin/sail bin pint --dirty --format agent`
2. `vendor/bin/sail composer phpstan` — must stay at **0 errors**.
3. `vendor/bin/sail artisan test --compact --parallel <focused test paths>` for the phase, then a full `--parallel` run before merge.
4. `vendor/bin/sail artisan dusk` only for phases touching public routes or the upload form (D3 touches the public sermon page indirectly via the presenter — run Dusk there).

---

## D1 — Patch CVE-2026-54133 in `mtdowling/jmespath.php`  (Critical)

**Priority: Critical · Risk: Very low · Effort: XS (minutes) · Est. impact: removes the only outstanding security advisory; closes a code-injection vector on the production storage path.**

### Root cause
`composer audit` reports `mtdowling/jmespath.php < 2.9.1` is vulnerable to **CompilerRuntime code injection via unescaped function names** (CVE-2026-54133, reported 2026-06-11). It is pulled in transitively:

```
aws/aws-sdk-php 3.381.2  →  requires mtdowling/jmespath.php (^2.8.0)
```

`aws-sdk-php` backs `league/flysystem-aws-s3-v3`, i.e. the **DigitalOcean Spaces / S3 storage and backup path** — a production-reachable dependency, which is why this is Critical rather than Low.

### Approach
No constraint change is needed: `^2.8.0` already permits the fixed `2.9.1`, and `composer why-not mtdowling/jmespath.php 2.9.1` confirms nothing blocks the bump. A targeted update resolves it with zero breaking changes.

### Target files
- [composer.lock](../../composer.lock) — the only file that changes (transitive pin).

### Tasks
- [ ] `vendor/bin/sail composer update mtdowling/jmespath.php` (add `--with-all-dependencies` only if Composer reports a lock conflict — none expected).
- [ ] `vendor/bin/sail composer audit` → **0 advisories**.
- [ ] Confirm the diff touches `composer.lock` only (no `composer.json` change).

### Verification
- [ ] `vendor/bin/sail composer audit` reports no vulnerabilities.
- [ ] `vendor/bin/sail artisan test --compact --parallel` — full suite green (the S3 disk is exercised by the asset-serving and backup tests).

### Exit criteria
- `composer audit` is clean; `jmespath.php` is pinned `>= 2.9.1` in the lockfile.

---

## D2 — Promote the security audit to the PR gate  (High)

**Priority: High · Risk: Very low · Effort: S · Est. impact: prevents the *next* vulnerable dependency from reaching `master` — this is the root-cause fix for why D1 lingered a week.**

### Root cause
`composer audit` runs only in the nightly workflow — [.github/workflows/nightly.yml:260](../../.github/workflows/nightly.yml#L260) (`npm audit` follows at line 262). The PR gate ([.github/workflows/pr.yml](../../.github/workflows/pr.yml)) enforces Pint ([:68](../../.github/workflows/pr.yml#L68)), PHPStan ([:74](../../.github/workflows/pr.yml#L74)), and the parallel test suite ([:77](../../.github/workflows/pr.yml#L77)) — **but not the security audit**. A vulnerable dependency can therefore merge and sit until a nightly run someone happens to act on. That is precisely how D1's CVE survived for a week.

### Approach
Add `composer audit` and `npm audit` as fast, blocking steps on the PR workflow, right after `setup-laravel` installs dependencies (so a vulnerable dep fails before the test run). **Scope both to production dependencies** — `composer audit --no-dev` and `npm audit --omit=dev` — because dev/CI tooling never ships. This was not merely precautionary: a pre-commit check found the npm tree already carries dev-only advisories (`ws` via `puppeteer-core` = *high*; `js-yaml` via `@lhci/cli` = moderate) that would have red-flagged an unscoped `npm audit --audit-level=high` on day one. `nightly.yml` keeps the full audit (incl. dev) as the backstop. (The only production npm dependency today is `alpinejs`, so the npm gate is narrow but non-vacuous and future-proofs added runtime deps.)

### Target files
- [.github/workflows/pr.yml](../../.github/workflows/pr.yml) — add the audit step after the existing Composer install / before or alongside Pint.

### Tasks
- [x] Add a `Composer audit` step to `pr.yml` running `composer audit --no-dev` (production-path advisories only).
- [x] Add an `npm audit --omit=dev --audit-level=high` step (no `|| true` — it can fail). Scoped to production after confirming the full-tree `--audit-level=high` is already red from dev-tooling advisories.
- [x] Placement is right after `setup-laravel` (which runs `composer install` + `npm ci`), so there is no second install.
- [x] Documented override path: add an accepted Composer advisory to `config.audit.ignore` in `composer.json` (code-reviewed), per the explanatory comment in `pr.yml` — preferred over a CLI `--ignore` buried in CI.

> **Follow-up (not blocking D2):** the dev-only advisories above (`ws` high; `js-yaml`/`@lhci/cli` moderate) are real but ship nowhere and are caught by nightly. `ws` has a non-breaking `npm audit fix`; the `@lhci/cli` chain needs a major bump. Track under D6 / dependabot rather than this gate.

### Verification
- [x] Confirmed locally pre-commit: `composer audit --no-dev` → exit 0, `npm audit --omit=dev --audit-level=high` → exit 0 (`found 0 vulnerabilities`), and `pr.yml` parses as valid YAML (16 steps in the `quality` job).
- [ ] On the first CI run, confirm the two new steps execute green; optionally push a throwaway branch pinning a known-vulnerable **production** package to confirm the gate **fails** as intended, then revert.

### Exit criteria
- Opening a PR with a high/critical advisory on a production dependency fails CI before merge.

---

## D3 — Decompose `SermonViewPresenter`  (High)

**Priority: High · Risk: Low–Medium (mitigated by existing presenter tests) · Effort: M–L · Est. impact: the single highest-churn × highest-complexity file in the codebase; every sermon-presentation change pays a tax navigating it.**

### Root cause
[app/Presenters/SermonViewPresenter.php](../../app/Presenters/SermonViewPresenter.php) is **737 lines, 43 methods, 51 commits in 90 days** — the worst churn × size signature in the repo. Its method inventory reveals ~6–7 distinct responsibilities fused into one class:

| Responsibility cluster | Methods (line refs) |
|---|---|
| URL generation | `audioUrl` ([:126](../../app/Presenters/SermonViewPresenter.php#L126)), `videoUrl` ([:650](../../app/Presenters/SermonViewPresenter.php#L650)), `canonicalUrl` ([:164](../../app/Presenters/SermonViewPresenter.php#L164)), `preacherUrl` ([:232](../../app/Presenters/SermonViewPresenter.php#L232)), `seriesUrl` ([:295](../../app/Presenters/SermonViewPresenter.php#L295)), `publicUrl` ([:412](../../app/Presenters/SermonViewPresenter.php#L412)) |
| Thumbnails | `cardThumbnailUrl` ([:176](../../app/Presenters/SermonViewPresenter.php#L176)), `plainThumbnailUrl` ([:202](../../app/Presenters/SermonViewPresenter.php#L202)), `thumbnailUrl` ([:417](../../app/Presenters/SermonViewPresenter.php#L417)) |
| Dates / duration | `formattedDuration`, `durationIso8601`, `formattedDates`, `humanDate` |
| SEO / meta | `metaDescription` ([:624](../../app/Presenters/SermonViewPresenter.php#L624)), `imageAlt` ([:607](../../app/Presenters/SermonViewPresenter.php#L607)), `childrensTalkImageAlt` |
| Preacher-name resolution | `displayPreacherName` ([:448](../../app/Presenters/SermonViewPresenter.php#L448)), `resolvePreacherAttribute` ([:492](../../app/Presenters/SermonViewPresenter.php#L492)) |
| Presentation shaping (facade) | `present` ([:407](../../app/Presenters/SermonViewPresenter.php#L407)), `presentForApi` ([:322](../../app/Presenters/SermonViewPresenter.php#L322)), `presentCollection` ([:337](../../app/Presenters/SermonViewPresenter.php#L337)), `presentForList` ([:393](../../app/Presenters/SermonViewPresenter.php#L393)), `preWarmForAdminList` ([:371](../../app/Presenters/SermonViewPresenter.php#L371)) |
| Its own caching layer | `memoize` ([:699](../../app/Presenters/SermonViewPresenter.php#L699)), `cacheKey` ([:721](../../app/Presenters/SermonViewPresenter.php#L721)), `clearInternalCaches` ([:117](../../app/Presenters/SermonViewPresenter.php#L117)) |

The embedded memoization is what makes each change riskier than it looks: presentation logic and cache-key management are interleaved, so a formatting tweak can silently change cache behaviour.

### Approach
Extract cohesive collaborators **behind the existing public facade**. `present()` / `presentForApi()` / `presentCollection()` keep their exact signatures; callers (controllers, Blade, API resources) do not change. The presenter becomes a thin orchestrator delegating to focused helpers. Do this in small, individually-green steps — extract one cluster per commit, running the presenter tests after each.

Suggested seams (confirm names against existing conventions in `app/Support`, `app/Seo`):
- `SermonUrlBuilder` — the URL cluster.
- `SermonThumbnailResolver` — the thumbnail cluster (it already coordinates with `Sermon`'s thumbnail attributes).
- `SermonMetaPresenter` — meta description / image alt (or fold into the existing `app/Seo` layer if one fits).
- Lift `memoize`/`cacheKey` into a single caching decorator or a small dedicated cache concern, so presentation methods are pure and the cache wraps them in one place.

### Target files
- [app/Presenters/SermonViewPresenter.php](../../app/Presenters/SermonViewPresenter.php) — shrinks to an orchestrator over the new collaborators.
- New collaborator classes under `app/Presenters/` or `app/Support/` (match sibling conventions).
- **Safety net (must stay green, do not edit assertions):**
  - [tests/Integration/Presenters/SermonViewPresenterTest.php](../../tests/Integration/Presenters/SermonViewPresenterTest.php)
  - [tests/Integration/Presenters/SermonPresentationAssemblerTest.php](../../tests/Integration/Presenters/SermonPresentationAssemblerTest.php)

### Progress (2026-06-26)
First decomposition pass landed (presenter **748 → 651 lines**), extracting three cohesive clusters behind the unchanged public facade, each as its own commit with focused unit coverage:
- `SermonDateFormatter` — the date/duration cluster (owns its date-timestamp memo).
- `SermonMetaPresenter` — the meta-description / image-alt cluster (the named seam above).
- `SermonUrlBuilder` — the URL/thumbnail cluster (the plain-thumbnail fallback still resolves through the presenter, preserving its memoized result).

The `SermonPresentationAssembler` (present/forApi/forList shaping) was extracted in an earlier pass. Caching was deliberately **left as the single `memoize()`/`cacheKey()` seam on the presenter** — collaborators are pure and the presenter wraps them — so cache behaviour is byte-for-byte unchanged; `clearInternalCaches()` now also resets the date formatter's cache.

Still outstanding for this phase: the entangled **preacher-name resolution** cluster (`displayPreacherName`/`preacherUrl`/`preacherImageUrl`/`resolvePreacherAttribute`) and the identity-keyed `displayReference`/`seriesUrl`/`serviceLabel` methods, plus the final push under the ~300-line target.

### Tasks
- [x] Characterise the current caching behaviour first (what `memoize`/`cacheKey` actually key on) so the extracted decorator reproduces it exactly.
- [~] Extract one responsibility cluster per commit; after each, run the two presenter tests above plus `SermonDisplayTest`/`SermonSeoTest`. *(3 clusters done: dates, meta, URLs; preacher-resolution cluster remains.)*
- [~] Move memoization into a single seam; confirm `clearInternalCaches()` still resets all caches (it is called between requests/tests). *(Kept as the single `memoize()` seam on the presenter; `clearInternalCaches()` now also clears the date formatter.)*
- [~] Add focused unit tests for each extracted collaborator (cheaper than the current full-presenter integration tests). *(Added for `SermonDateFormatter`, `SermonMetaPresenter`, `SermonUrlBuilder`.)*
- [ ] Target: `SermonViewPresenter` < ~300 lines, each collaborator single-responsibility. *(651 lines so far.)*

### Verification
- [x] `tests/Integration/Presenters tests/Integration/Models/SermonDisplayTest.php tests/Integration/Models/SermonSeoTest.php tests/Feature/SermonPagesTest.php` — green (plus `tests/Unit/Presenters`, sitemap and API-controller suites: 202 passing across presenter consumers).
- [ ] `vendor/bin/sail artisan dusk` for the public sermon page — not run for this pass; the server-rendered `SermonPagesTest` exercises the same presenter output and is green.
- [x] `composer phpstan` — 0 errors, no new baseline entries.

### Exit criteria
- Public facade unchanged; presenter is an orchestrator under ~300 lines; each extracted collaborator is independently unit-tested; full suite + Dusk green.

---

## D4 — Trim the `Sermon` model  (Medium)

**Priority: Medium · Risk: Low (135 sermon test files de-risk it) · Effort: M · Est. impact: reduces the breadth of the highest-churn model (56 commits/90d); separates processing concerns from the domain model.**

### Root cause
[app/Models/Sermon.php](../../app/Models/Sermon.php) is 622 lines / 39 functions and carries two concerns that sit awkwardly on the domain model:
1. **Static validation** — `validationRules()` ([:237](../../app/Models/Sermon.php#L237)) lives on the model.
2. **Processing-state delegation** — a cluster of methods (`getProcessingStatus` [:528](../../app/Models/Sermon.php#L528), `isProcessingComplete` [:538](../../app/Models/Sermon.php#L538), `isProcessingFailed` [:550](../../app/Models/Sermon.php#L550), `isProcessingInProgress` [:562](../../app/Models/Sermon.php#L562), `getLatestProcessingLog` [:574](../../app/Models/Sermon.php#L574)) that really describe the state of the *related* `MediaProcessingLog`, leaking pipeline concerns into the domain model.

This is **opportunistic trimming, not a big-bang refactor** — the model is heavily tested and changes constantly, so keep moves small and incremental.

### Approach
- Move `validationRules()` toward the existing request layer (e.g. consolidate with [tests/Unit/UpdateSermonRequestTest.php](../../tests/Unit/UpdateSermonRequestTest.php)'s subject, `UpdateSermonRequest`) where validation already lives, leaving the model with data shape only.
- Consider a small `SermonProcessingState` value object (or a thin readonly DTO) derived from the `latestProcessingLog` relationship, so `isProcessing*` reads delegate to one place instead of five model methods. Only do this if it genuinely simplifies callers — otherwise leave it.

### Target files
- [app/Models/Sermon.php](../../app/Models/Sermon.php)
- [app/Http/Requests/](../../app/Http/Requests/) — destination for validation rules.
- **Safety net:** [tests/Integration/Models/SermonTest.php](../../tests/Integration/Models/SermonTest.php), [tests/Unit/UpdateSermonRequestTest.php](../../tests/Unit/UpdateSermonRequestTest.php), [tests/Unit/SermonAnalysisTest.php](../../tests/Unit/SermonAnalysisTest.php).

### Progress (2026-06-26)
Processing-state cluster extracted; validation deliberately left on the model after revisiting the wider codebase.

- **`validationRules()` kept on the model — by design.** The static `validationRules()` method is a deliberate, repo-wide convention (~25 models) documented and enforced by Warden, and `Sermon::validationRules()` is the *shared* source of truth for **three layers that do not share an HTTP request**: `UpdateSermonRequest` (HTTP), `SermonFormData` (Livewire form, which re-keys the snake_case rules to camelCase), and `SermonValidationService` (array-based service validation). Relocating it into the request layer would invert those dependencies (a Livewire form and a service depending on a `FormRequest`) and break the convention for one model only — disturbing a healthy, consistent part of the codebase, which the plan's guardrails explicitly forbid. The model already holds *data shape only* via this shared rule set; that is the correct home.
- **`SermonProcessingState` value object extracted.** The five processing-state reads (`getProcessingStatus`/`isProcessingComplete`/`isProcessingFailed`/`isProcessingInProgress`/`getLatestProcessingLog`) described the related `MediaProcessingLog`, not the sermon. They are replaced by a single `Sermon::processingState()` accessor returning a `final readonly` [app/Support/SermonProcessingState.php](../../app/Support/SermonProcessingState.php), so pipeline-state queries now live in one cohesive collaborator off the domain model. Behaviour is byte-for-byte identical (same `latestProcessingLog` relationship, same `ProcessingStatus` enum predicates). The model drops from 39 to 35 methods and sheds its `ProcessingStatus` import.

### Tasks
- [x] ~~Relocate `validationRules()` to the request layer~~ — **not done, by design** (see Progress: conflicts with the repo-wide convention and three shared non-HTTP consumers; the model holds data shape only via the shared rules).
- [x] Extract the `SermonProcessingState` value object behind a single `processingState()` accessor; the five processing-state methods now flow through one collaborator.
- [x] Single commit; ran the value-object unit test plus `SermonStatusAndMediaTest`/`SermonTest` after the change.

### Verification
- [x] `artisan test tests/Unit/Support/SermonProcessingStateTest.php tests/Integration/Models/SermonTest.php tests/Integration/Models/SermonStatusAndMediaTest.php` — 34 passing.
- [x] `composer phpstan` — 0 errors, no new baseline entries.

### Exit criteria
- Processing-state reads flow through one collaborator (`SermonProcessingState`); validation stays on the model as the deliberate shared convention; full coverage retained.

---

## D5 — Review the `MediaProcessingLog` model  (Medium)

**Priority: Medium · Risk: Low · Effort: M · Est. impact: second-largest model (697 ln, 28 commits/90d); confirm it isn't accreting orchestration the pipeline services should own.**

### Root cause
[app/Models/MediaProcessingLog.php](../../app/Models/MediaProcessingLog.php) is 697 lines and changes often. A model this size on the processing path is a candidate for absorbing orchestration/decision logic that belongs in the `app/Services/Processing/*` services rather than on the Eloquent record.

### Approach
This is an **investigation-first** phase. Read the model, classify its methods (data shape / state queries vs. orchestration), and decide whether anything should move to `SermonProcessingLogger` / `ProcessingPhaseRegistry` or stay. Produce findings *before* moving code; the deliverable of this phase may simply be "it's fine, here's why" — which is a valid outcome.

### Target files
- [app/Models/MediaProcessingLog.php](../../app/Models/MediaProcessingLog.php)
- [app/Services/Processing/SermonProcessingLogger.php](../../app/Services/Processing/SermonProcessingLogger.php) — likely destination for any orchestration.
- **Safety net:** existing `MediaProcessingLog` and processing-job tests (e.g. [tests/Feature/SermonProcessingJobChainTest.php](../../tests/Feature/SermonProcessingJobChainTest.php), [tests/Feature/SermonProcessingErrorHandlingTest.php](../../tests/Feature/SermonProcessingErrorHandlingTest.php)).

### Tasks
- [ ] Classify every method: data/state vs. orchestration/decision.
- [ ] If orchestration is found, move it to the relevant `Processing` service; otherwise document why the size is justified and close the phase.
- [ ] Keep moves small and test-backed.

### Verification
- [ ] `vendor/bin/sail artisan test --compact --parallel tests/Feature/SermonProcessing*` (and any direct model test).
- [ ] `vendor/bin/sail composer phpstan` — 0 errors.

### Exit criteria
- Either orchestration is relocated with coverage retained, or a short written rationale records that the model's size is intrinsic.

---

## D6 — Dependency freshness hygiene  (Low / ongoing)

**Priority: Low · Risk: Low · Effort: S (recurring) · Est. impact: keeps the upgrade surface small so major bumps stay cheap.**

### Root cause
`composer outdated --direct` shows everything is current except routine patch/minor bumps (already flowing via dependabot) and **two intentionally-deferred majors**:
- `symfony/http-client` 7.4 → 8.x and `symfony/mailgun-mailer` 7.4 → 8.x — **blocked by Laravel 13's Symfony-7 constraint**. Correct to wait; revisit at the Laravel 14 upgrade.
- `openai-php/laravel` 0.19 → 0.20 — a `0.x` bump, so treat as potentially breaking; read the changelog before adopting.

### Approach
No urgent action. Let dependabot continue landing patch/minor bumps (gated by D2 once it merges). Pin the Symfony 8 majors to the Laravel 14 upgrade ticket. Schedule the `openai-php` 0.20 bump as a small standalone PR once its changelog is reviewed against `AudioTranscriptionService` / `SermonAnalysisService` usage.

### Tasks
- [ ] Confirm dependabot is open for patch/minor PRs and that they pass the (post-D2) audit gate.
- [ ] Review the `openai-php/laravel` 0.20 changelog; bump in a dedicated PR if non-breaking for our `OpenAI::audio()` / `OpenAI::chat()` / `OpenAI::embeddings()` usage.
- [ ] Note the Symfony 8 majors against the Laravel 14 upgrade plan; do **not** force them on Laravel 13.

### Exit criteria
- Outstanding non-major direct-dependency updates trend toward zero; the deferred majors are tracked, not forgotten.

---

## Non-Goals (what is *not* debt)

Recording what **not** to do is as important as the tasks above — it stops this plan from becoming make-work.

- **Do not refactor the large, stable media-pipeline files.** All are inherently complex (video/audio/AI) *and* low-churn + test-covered, so refactoring them is pure cost with no interest saved:
  - [app/Services/Media/Video/HistoricVideoImporter.php](../../app/Services/Media/Video/HistoricVideoImporter.php) — 1,141 ln, 3 commits/90d.
  - [app/Services/Processing/ProcessingPhaseRegistry.php](../../app/Services/Processing/ProcessingPhaseRegistry.php) — 1,005 ln, 3 commits/90d.
  - [app/Services/Media/Thumbnail/ThumbnailCanvasComposer.php](../../app/Services/Media/Thumbnail/ThumbnailCanvasComposer.php) — 954 ln, 1 commit/90d.
  - [app/Services/ChurchService/ChurchServiceItemSyncService.php](../../app/Services/ChurchService/ChurchServiceItemSyncService.php) — 912 ln, 2 commits/90d.
  - *Early-warning trigger:* if any of these starts appearing in the 90-day churn top-20, re-evaluate then — not before.
- **Do not chase code-quality or documentation debt.** PHPStan is clean at level 8, Pint is enforced on the PR gate, there are zero `TODO`/`FIXME` markers, and `AGENTS.md`/`CLAUDE.md`/`docs/` are rich. There is nothing to remediate.
- **Do not add coverage tooling or a coverage gate.** Coverage is already excellent by file ratio and hotspot saturation; a percentage gate would add noise without signal. (Note: PR tests deliberately `--exclude-group=performance`, so N+1 guards like `SermonListingNPlusOneTest` run nightly — this is an accepted speed tradeoff, not a gap to close.)
- **Do not rewrite anything.** Every phase here is an incremental, test-backed move behind a stable interface. No big-bang rewrites.

## Tracking metrics

| Metric | Baseline (2026-06-18) | Target |
|---|---:|---|
| `composer audit` high/critical advisories | 1 | 0 |
| Security audit enforced on PR gate | No | Yes (D2) |
| `SermonViewPresenter` lines / methods | 737 / 43 | < 300 / facade only (D3) |
| `Sermon` model functions | 39 | 35 — processing-state relocated to `SermonProcessingState`; validation kept on the model by design (D4) |
| PHPStan baseline errors | 0 | 0 (hold the line) |
| Outdated direct deps (non-major) | ~14 | < 5 (dependabot, D6) |
