# Code-Quality Remediation Plan — Phase 9 follow-through

> **Comprehensive reconciliation (2026-08-12).** The live code still matches the open mechanical
> findings: `AiServiceProvider` retains its two silent fallbacks, both audio transcription methods
> retain the `'unknown'` processing-id default, all 12 bare Google Calendar PHPStan ignores remain,
> `spatie/laravel-data` still has eight extending DTOs, and PHPStan remains at level 8. The only
> completed slices remain WP2.1 and WP6.1.
>
> Ownership is now narrower. Historic/convergence one-shot retirement is exclusively Gate G9/WP10
> of the two historic-readiness plans; it is not WP5 here and must not be scheduled from this file.
> The archived simplification parent is context only. R9-R11 are complete, so level-9 remediation
> sessions may start at level 8; maintainer Q4 blocks only the final `level: 9` config flip.
>
> **Recommended delivery:** WP2's fail-closed bindings/signature/config fixes plus WP6.2 first;
> WP3 and approved WP4 slices independently; level-9 sessions next; dependency refreshes in their
> own maintenance PR, never mixed with behavioural remediation. WP4a still needs explicit
> dependency-removal approval.

> **WP2.1 and WP6.1 are DONE (2026-07-24).** The `#[Computed]` call-syntax bug is fixed and guarded
> by a regression test that was proven to fail against the old code. See the completion notes in
> WP2.1 and WP6 for what was verified.
>
> WP1 remains a routine update rather than a security emergency; its original premise was a stale
> local vendor tree (see the WP1 correction note). This is the implementation plan for
> every finding in the Phase 9 code-quality review
> ([../reviews/july-2026-simplification/code-quality-review-2026-07-19.md](../reviews/july-2026-simplification/code-quality-review-2026-07-19.md)
> — "the findings doc"; F-numbers below refer to it). It is written to be executed by an agent
> with no prior context: every item names its files, steps, tests, and acceptance check.
>
> **Dependencies:** the simplification closeout plan
> [JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)
> now contains only R13-R15 and executes independently. WP7's former R9-R11 gate is satisfied.
> Read `AGENTS.md` before starting any work package.
>
> **Agents must not, without maintainer input:** (a) start WP4a (`laravel-data` removal) —
> dependency changes need approval per AGENTS.md; (b) enable `Model::shouldBeStrict()` outside
> the WP4b survey procedure; (c) flip PHPStan to level 9 before Q4; (d) delete
> `config/podcast.php`'s `enabled` key before maintainer answers open question Q3; (e) run any
> production command or delete any historic/convergence one-shot.
>
> **Maintainer answers needed** (each blocks only the item that cites it):
> - **Q3:** Should the podcast feed route be gateable by config, or delete the unread
>   `podcast.enabled` key? (Recommendation: delete.) → WP2 step 4.
> - **Q4:** Sign-off on the final level-9 config flip with no baseline. The remediation sessions
>   themselves can proceed first while CI remains pinned at level 8.

## Work-package overview and sequencing

| WP | What | Kind | Blocked by |
|---|---|---|---|
| WP1 | Routine dependency refresh (includes medialibrary and the former WP2.8 list; no security emergency) | mechanical | dependency-change approval/review |
| WP2 | Mechanical sweep: computed-property fix (**2.1 DONE 2026-07-24**), provider bindings, dead config, small idiom residue (F6.1, F2.3, F3.3, F2.6, F2.7, F2.2, F1.3, F5.3) | mechanical | — |
| WP3 | PHPUnit mock-notice sweep, 124 sites (F4.2) | mechanical, wide | — |
| WP4 | Judgment items: laravel-data removal, Eloquent strict-mode survey, test-env branch inversion, thumbnail test speed, js-yaml override, `validationRules()` relocation (F2.4, F6.2, F2.5, F4.3, F5.2, F2.2a) | design | per-item, see below |
| WP5 | **Removed from this plan** — historic readiness G9/WP10 owns one-shot retirement | external | historic G9 |
| WP6 | Regression nets: query-count assertions (**6.1 DONE 2026-07-24**) + computed-call structural test (F6.1 guard, §5 opportunity) | mechanical | WP2 item 1 |
| WP7 | PHPStan level-9 ratchet in clustered sessions (F1.1, F1.3 completion) | design | Q4 blocks only final config flip |

WP2+WP6 as one PR stack; WP3 and WP4 items independently as answers/approvals arrive; WP7 can
start after that stack; WP1 is a separate maintenance PR and need not block the quality fixes.

## Quality gates (every PR, from AGENTS.md)

`vendor/bin/sail artisan test --compact --parallel` (focused per change, full before merge, first
run captured with `tee`) · `vendor/bin/sail composer phpstan` at 0 errors ·
`vendor/bin/sail bin pint --dirty --format agent` · `vendor/bin/sail artisan dusk` only where
noted (WP2 item 1 touches the public sermons page). All commands run through Sail; if a
full-suite run drowns in `getaddrinfo for mysql failed` errors, that is Docker container DNS
breakage — fix with `vendor/bin/sail down && vendor/bin/sail up -d`, do not debug the tests.

---

## WP1 — spatie/laravel-medialibrary version bump (F5.1) — downgraded from urgent 2026-07-20

> **Correction (2026-07-20):** F5.1's premise was wrong. `composer.lock` has carried
> **11.23.1** — which includes both CVE fixes (patched in 11.23.0 per the GitHub advisories) —
> since commit `ac2ced40f` (2026-07-03), so production (which installs from the lock) was never
> exposed during the review period. The review's `composer audit` read 11.22.1 from a **stale
> local vendor tree**; `composer audit` audits installed packages, not the lock. Fixed
> 2026-07-20 by running `vendor/bin/sail composer install` — audit is now clean with no code or
> lock change. Lesson: verify version claims against `composer.lock` (and prod), not local
> `composer show`/`composer audit` output.

What remains is routine drift. Consolidate it with the former WP2.8 dependency sweep so there is
one dependency-only PR, not two plans for the same lockfile change:

1. Re-resolve the approved patch/minor Composer and npm packages, including
   `spatie/laravel-medialibrary`; no new major and no new package.
2. Focused tests: `vendor/bin/sail artisan test --compact --parallel --filter=Media` plus the
   meeting/page media suites (`--filter=Meeting`).
3. Run the frontend build, relevant tool smoke checks and full project gates; `composer audit`
   stays clean. Record any deliberately deferred major separately.

## WP2 — Mechanical sweep (one PR stack; commits in this order)

### 2.1 Livewire `#[Computed]` call-syntax fix (F6.1) — the perf bug — **DONE 2026-07-24**

> **Completion note (2026-07-24).** All ten call sites converted to property access; ten was the
> true count (the plan's prose said "nine"). The 3× claim was **confirmed empirically**, not just
> asserted: with the bug temporarily reintroduced, the new WP6.1 guard reported
> `select count(*) … from sermons where content_type = ?` → 3 and the 24-row browse `SELECT` → 3.
> With the fix, every query in a browse render runs exactly once.
>
> Three things the plan did not anticipate:
>
> 1. **The staleness check found a real hazard, and it was fixed.** `dispatchMetadataUpdate()` reads
>    `seoTitle`/`seoDescription`/`seoCanonical` *before* render, and all five mutating hooks call
>    it. Livewire can batch several `wire:model.live` updates into one request, in which case an
>    earlier hook would memoize the SEO strings and a later hook would dispatch that stale copy.
>    Fixed by `unset($this->seoTitle, $this->seoDescription, $this->seoCanonical)` at the top of
>    `dispatchMetadataUpdate()` — one place rather than five, and it covers the Blade
>    `@js($this->seoTitle)` read too. `sermons` needs no unset: nothing reads it before render.
> 2. **The `@property-read` docblocks became load-bearing.** Property access resolves types through
>    the class docblock, so PHPStan level 8 immediately failed on `BrowseSermons`' two option
>    properties (declared `array<int, …>`, methods return `list<…>`). Fixed by tightening the
>    annotations, not by casting.
> 3. **`ShowChurchService`'s two computeds live in the `ReviewsServiceSections` trait**, which had
>    no `@property-read` block at all. Added there rather than on the component, so future consumers
>    inherit it. `preacherOptions()` genuinely was not a `list` — it `filter()`s before `map()`, so
>    its keys were gapped (a JSON-object-not-array hazard in the Livewire payload). Both methods
>    rewritten to `array_map` over the underlying array, matching `BrowseSermons`' existing idiom.
>
> Gates: pint clean · PHPStan 0 errors · full parallel suite 5552 passed / 17203 assertions / 0
> failures (the 124 PHPUnit notices are WP3's pre-existing backlog, unchanged) · Dusk 47 passed.

Livewire memoizes computed properties only on **property access** (`$this->sermons`); method
calls (`$this->sermons()`) re-execute the body. Measured effect: `/christ/sermons` runs its
count/rows/preachers/scripture-passages query block 3× per request.

Sites to convert from `$this->name()` to `$this->name` (all confirmed `#[Computed]`):

- `app/Livewire/Sermons/BrowseSermons.php` lines 139 (`sermons()`), 142 (`enabledBooks()`),
  145 (`enabledChapters()`), 146–148 (`preacherOptions()`, `seriesOptions()` — line 148 calls
  both again), 222 and 238 (`sermons()` inside `presentedSermons()`/the JSON-LD presenter).
- `app/Livewire/Admin/ChurchServices/ShowChurchService.php` lines 67–68
  (`sectionTypeOptions()`, `preacherOptions()`).

Re-grep before editing — line numbers drift:
`grep -rnE '\$this->(sermons|enabledBooks|enabledChapters|preacherOptions|seriesOptions|sectionTypeOptions)\(\)' app/Livewire resources/views/livewire`

**Staleness check (do this, don't skip):** `BrowseSermons`'s `updated*` hooks (lines ~65–93)
mutate filter properties and call `resetPage()` before render. With memoization now effective,
verify no computed property is *read* earlier in the same request cycle than a mutation that
should invalidate it — in particular trace `dispatchMetadataUpdate()`: if it reads `seoTitle`/
`seoDescription`/`seoCanonical`/`sermons` before `resetPage()` has taken effect, add
`unset($this->sermons, $this->seoTitle, …)` at the top of the mutating hooks (Livewire's
documented cache-bust idiom). If nothing reads computeds before render, no unsets are needed.

Tests: extend the existing browse-page feature coverage with a query-duplication assertion (see
WP6.1, which lands in the same PR). Run Dusk (`vendor/bin/sail artisan dusk`) — this touches the
public sermons page render path.

### 2.2 `AiServiceProvider` binding consistency (F2.3)

`app/Providers/AiServiceProvider.php`: two bindings diverge from the family idiom
(`match` + `InvalidArgumentException` on unknown config value, as used by
`ServiceTranscriptionInterface` and `ServiceStructureInterface` at lines 50–73):

- `SermonAnalysisInterface` (lines 30–38): if/else, silent fallback to the paid
  `SermonAnalysisService` on typos.
- `TranscriptionServiceInterface` (lines 40–48): `match` but with a silent `default =>` arm.

Convert both to explicit `match` arms (`'mock'`, `'local'` where applicable, `'openai'`) with a
throwing default, message style copied from line 57–59. **Behaviour note:** this makes an
unrecognised `media-processing.analysis.service` / `media-processing.transcription.service`
value throw instead of silently billing OpenAI — that is the point. Check `.env.example` and
`config/media-processing.php` defaults name a valid value (`openai`), and update any provider
test that asserted the fallback (`grep -rn "AiServiceProvider" tests`).

### 2.3 Dead config keys (F3.3)

Delete, then re-verify each with the grep shown:

- `config/calendar.php`: the whole `performance` block (~line 63) and whole `google` block
  (~line 75). The live `GOOGLE_CALENDAR_ID` read is `services.google.calendar_id` in
  `config/services.php` — do not touch that. Verify: `grep -rn "calendar.performance\|calendar.google" app tests resources routes` → 0 hits.
- `config/thumbnail-generation.php`: the `ffmpeg` block (lines ~11–13). The live ffmpeg path is
  `media-processing.video.ffmpeg_path`. Verify: `grep -rn "thumbnail-generation.ffmpeg" app tests` → 0.
- `config/podcast.php`: `enabled` — **only after maintainer answers Q3** (recommendation:
  delete; the feed route has never been gated). If the answer is "make it gate", instead add the
  check in `PodcastFeedController` + a feature test for the disabled state.

### 2.4 `SermonRepository::getExistingSeries()` exception narrowing (F2.6)

`app/Services/Public/SermonRepository.php` (catch at ~line 237): narrow
`catch (\Exception)` to `catch (\Illuminate\Database\QueryException)` so only DB-level failures
degrade to an empty series list; anything else propagates. Keep the `Log::warning`. Do **not**
sweep the other ~100 broad catches — the media-pipeline ones are deliberate resilience.

### 2.5 Drop `$processingId = 'unknown'` defaults (F2.7)

`app/Services/Media/Audio/AudioTranscriptionService.php` lines 71 and 189: make `$processingId`
a required `string` parameter. Verified callers all pass it explicitly
(`MatchSongsFromTranscript:488`, `TranscribeSpeechSegments:214`, `TranscribeAudio:74`) — re-grep
`-e "transcribe("` before editing. Update any test that relied on the default.

### 2.6 `Sermon::$fillable` stale comments (F2.2)

`app/Models/Sermon.php` lines ~118–154: delete the historical comments of the form
`// Renamed from 'filename' for consistency` and similar migration narration. Keep any comment
stating a *current* constraint (e.g. the integer-bounding security comment in
`validationRules()` stays).

### 2.7 `GoogleCalendarSyncService` ignore identifiers (F1.3, minimal form)

`app/Services/Calendar/GoogleCalendarSyncService.php` has 12 bare
`/** @phpstan-ignore-next-line */` comments (lines ~57–191). For each, run phpstan without the
ignore to learn the identifier, then rewrite as
`@phpstan-ignore-next-line <identifier> (<one-clause reason>)`. Do **not** attempt full typing
of the Google payloads here — that belongs to WP7 session 3.

### 2.8 Routine dependency bumps (F5.3)

Owned by WP1. Do not create a second lockfile PR from this section.

## WP3 — PHPUnit mock-notice sweep (F4.2)

124 PHPUnit 13 "mock without expectations" notices. Enumerate per directory (compact mode hides
them): `vendor/bin/sail php vendor/bin/phpunit tests/<dir> --display-all-issues --no-progress`
for `tests/Unit`, `tests/Integration`, `tests/Feature` (the last is large — run per subfolder if
slow). For each flagged test:

1. If the double never gets `shouldReceive`/`expects` → replace `createMock`/`Mockery::mock`
   with `createStub()` (PHPUnit) or keep Mockery but add
   `#[AllowMockObjectsWithoutExpectations]` only where a shared fixture genuinely serves both
   styles.
2. While there, delete or strengthen constructor-only tests
   (`it_can_be_instantiated_in_test_environment` in `AudioCompressionServiceTest` is the known
   example — a test asserting only "no exception on new" adds nothing; PHPUnit-guidelines rule
   "do not remove tests without approval" applies to files, so *strengthen* rather than delete
   where in doubt, and list any outright deletions in the PR description).
3. Skip the five legacy flat suites owned by active R14 and any one-shot tests explicitly owned by
   historic G9—port useful assertions to their surviving homes rather than polishing doomed tests.

Acceptance: full-suite notice count reported in the PR (target ≤ a handful of justified
`#[AllowMockObjectsWithoutExpectations]` sites); zero behaviour changes.

## WP4 — Judgment items (independent; each its own small PR)

### 4a. Drop `spatie/laravel-data` (F2.4) — **needs dependency-change approval first**

Evidence: 8/56 `app/Data/` classes extend `Spatie\LaravelData\Data` (`SermonAnalysis`,
`SongTitleMatch`, `LivestreamSegment`, `LivestreamProcessingResult`, `SpeakerMatchResult`,
`SpeakerEmbeddingResult`, `SongCatalogSyncReport`, `SermonMetadata`); zero `::from()` calls,
zero validation calls, four validation attributes total. Procedure:

1. For each of the 8: `grep -rn "<Class>" app tests` and check instance usage for inherited
   behaviour — chiefly `->toArray()` / `->all()` / array-casting. Add a hand-written
   `toArray()` (match current output shape exactly — write a characterisation test per class
   first: instantiate, `toArray()`, assert snapshot) and remove `extends Data`; convert the four
   `Required`/`Max` attributes to plain PHPDoc (they were never enforced — nothing calls
   validation).
2. `composer remove spatie/laravel-data`; delete `config/data.php`.
3. Watch `LivestreamSegment` specifically (cast-adjacent) and confirm its Eloquent serialization
   does not rely on laravel-data behaviour.
4. Full gates. R10 has already removed the song-cluster casts; do not restore or account for them.
5. Afterwards, optionally do the `app/Data/` domain-subfolder reorganisation (platform review
   F6) in a separate PR so files move once. Apply the namespace-move checklist from AGENTS.md /
   the R5 instructions (explicit `use`, external siblings, moved files' own siblings, Blade
   inline FQCNs, then full suite).

### 4b. `Model::shouldBeStrict()` survey (F6.2) — survey first, decide second

1. Branch; add `Model::shouldBeStrict(! app()->isProduction());` to
   `AppServiceProvider::boot()`.
2. Full parallel suite with `tee`; collect every `LazyLoadingViolationException` /
   missing-attribute / silently-discarded error.
3. Outcome A (clean or a handful of fixable violations): fix them, keep the flag, note each fix.
   Outcome B (violations are widespread): write the list into a short decision note appended to
   this plan and ask the maintainer whether to fix incrementally (flag on in `testing` env only
   first) or drop the idea. Do not merge a red suite.

### 4c. Invert the two `environment('testing')` branches (F2.5)

- `app/Services/Processing/StorageAdapterHelper.php:234` and
  `app/Services/Public/SitemapService.php:229`: replace the env check with an injected/config
  seam — read the surrounding code to pick the natural one (a config key the test sets, or a
  constructor collaborator the test fakes). The pattern to copy is whatever Phase 18 used when
  it removed the same smell elsewhere (`git log --grep="environment" --oneline` to find it).
- R10 has landed but `VideoSegmentationService` still contains a testing-environment branch. Include
  it in the same survey: replace it only if a natural injected/config seam exists; otherwise record
  why it remains. Leave the three documented `AppServiceProvider` hooks alone.

### 4d. Thumbnail test-render speed (F4.3)

The 11 slowest tests (5–12 s each) are full-resolution GD renders in
`ThumbnailGenerationServiceTest`, `ThumbnailGenerationServiceCandidateTest`, and
`Tests\Unit\Services\ThumbnailCanvasComposerTest`. Two independent moves:

1. Re-home `ThumbnailCanvasComposerTest` from `tests/Unit` to `tests/Integration` (it renders
   canvases; "Unit" is a lie the suite layout tells).
2. Introduce a test-profile canvas size: a `thumbnail-generation.canvas` config the tests set to
   a proportionally-scaled small geometry, with pixel assertions rewritten as ratios. **Only do
   this if the assertions scale cleanly** — if the composer's layout maths has absolute-pixel
   branches, record that in the PR and keep full-size renders (accepting the ~80 s serial cost)
   rather than weakening assertions.

### 4e. js-yaml override (F5.2)

Dev-only moderate DoS via `@lhci/cli → @lhci/utils → js-yaml@3.14.2`. Check
`npm view @lhci/cli versions` for a release bumping js-yaml; if none, add a `package.json`
`overrides` entry pinning `js-yaml` ≥ 3.15 **under the @lhci scope only**, then run the
Lighthouse CI workflow once (or its local equivalent) to prove @lhci still parses its config.
If it breaks, revert and record "accepted risk: dev-only tooling" here.

### 4f. `Sermon::validationRules()` relocation (F2.2a) — lowest priority

Optional finish of the model slim-down: move the 56-line static into a dedicated
`App\Models\Rules\SermonValidationRules` (or a Form Request if all consumers are HTTP — check
callers: `grep -rn "validationRules" app`). Skip entirely if consumers span Livewire + API +
jobs and the move adds a class without removing complexity — record "keep" here if so.

## WP5 — Historic/convergence one-shot retirement — external ownership

No executable work remains here. The final-readiness and readiness-remediation plans own the
operation, exact closeout, rollback window and G9/WP10 retirement release. This plan neither names
deletion gates nor duplicates their checklist. Revisit the code-quality finding only after those
plans report the tools deleted.

## WP6 — Regression nets (lands with WP2.1)

1. **Query-duplication assertion** — **DONE 2026-07-24**.
   `BrowseSermonsTest::browsing_runs_each_listing_query_only_once` captures every query of a
   `Livewire::test(BrowseSermons::class)` render via `DB::listen()`, keys them by SQL + bindings,
   and asserts no key repeats. The "no identical query more than once" form was chosen over a
   pinned count, as the plan allowed — the count is not stable across filter combinations. The
   guard was verified by temporarily reverting the two `$this->sermons()` call sites: it failed
   with both offending queries at count 3, which is the F6.1 measurement reproduced as a test.
2. **Computed-call structural test** — still open. Alongside
   `tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php`, add a test that
   reflects over `app/Livewire` classes, collects method names carrying
   `Livewire\Attributes\Computed`, and asserts no `$this-><name>()` call syntax appears in the
   component source or its Blade view (simple token/regex scan of the files is fine — the goal
   is preventing regression of the whole class of bug, and a string scan catches it).

## WP7 — PHPStan level-9 ratchet (F1.1) — sessions unblocked; flip gated on Q4

Current state: level 8, 0 errors, empty `phpstan-baseline.neon` (keep it empty — no baseline
entries at any point; that is the ratchet's value). The July count is obsolete after R9-R11 and
the historic programme's large additions. Re-run the trial and plan from the current clusters;
do not quote or target the old estimate:

`vendor/bin/sail php vendor/bin/phpstan analyse --level=9 --memory-limit=2G --no-progress --error-format=raw > /tmp/l9.txt`
then aggregate: `cut -d: -f1 /tmp/l9.txt | sort | uniq -c | sort -rn`.

Fix in bounded sessions, each ending with the full quality gates at level 8. The clusters below
are starting points, not a promise that the current report still has four equal batches. Q4 is
needed only before the final config flip:

1. **Session 1 — typed job payloads** (the bug-adjacent cluster): `SendCompletionNotification`
   (~36 errors: give the notification payload a `@phpstan-type` array shape or a small DTO at
   the dispatch site), `SubmitToProcessing`, `MoveSermonToPrivateStorage`,
   `SermonMetadataIntegrationService`, `MetadataExtractionService`. Rule from the phpstan
   preamble: fix underlying types, no `@var` overrides, no casts-to-silence.
2. **Session 2 — Livewire + read path**: typed URL-bound properties in `BrowseSongs` (~14) and
   siblings; `SermonRepository` pluck generics; `PodcastFeedService`; `SermonApiController`.
3. **Session 3 — external payload boundaries**: `GoogleCalendarSyncService` full typing (delete
   the 12 ignores WP2.7 annotated); `ApiBibleClient`; `StructureEvaluateCommand` (44) and
   `StructureShadowReportCommand` (34) — these two **stay** (successor regression mechanism per
   the remainder plan) so they must be typed, mostly `config()`/JSON casts.
4. **Session 4 — media-services remainder + the flip**: whatever survives in
   `app/Services/Media/**` post-R10/R11 (ffmpeg param casts, `file_exists` on mixed, etc.);
   then set `level: 9` in `phpstan.neon`, run `vendor/bin/sail composer phpstan` → must be 0,
   and update the AGENTS.md quality-gate line if it names the level.

Level 10 (1,248 errors at trial): **do not attempt**; reassess after level 9 has held for a few
weeks of normal development.

## Closure

When WP1-WP4 and WP6 are done and WP7 is either done or explicitly parked at the Q4 flip: append a dated
completion note per WP here, update the findings doc's header with "remediated by
CODE-QUALITY-REMEDIATION-2026-07-19", and archive this plan to `docs/archived-plans/` with a
pointer header once WP7 lands. WP5 closes by reference to historic G9; it does not keep this plan
open. Report any rejected/kept-as-is item (4b outcome B, 4d fallback,
4f "keep") in the note rather than deleting its section.
