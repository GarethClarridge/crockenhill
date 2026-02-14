# Automated Lighthouse Testing Plan

## Goal

Add reliable, repeatable Lighthouse audits for key public pages so performance, accessibility, and best-practices regressions are caught before deploy.

## Recommended Approach

Use **Lighthouse CI (LHCI)** with:
- Local runs for quick feedback (`vendor/bin/sail npm run lighthouse:ci`)
- GitHub Actions runs on pull requests and `master`
- Assertion-based quality gates (fail CI on regressions)
- Optional historical reporting in a lightweight server later

This fits the current stack (Laravel + Vite + GitHub Actions) with minimal operational overhead.

---

## Scope (Phase 1)

Audit high-traffic, public, unauthenticated routes first:
- `/`
- `/christ`
- `/church`
- `/community`
- `/calendar`
- `/christ/sermons`

Keep admin/authenticated pages out of phase 1 to avoid login/session complexity.

---

## Implementation Plan

## Phase 0: Baseline and route selection

1. Confirm production-like test target:
   - In CI: `php artisan serve` with built assets (runs directly on Ubuntu runner, not Sail).
   - Locally: run against the Sail app at `http://localhost`.
2. Finalize 5-8 URLs for stable auditing (avoid highly dynamic pages initially).
3. Run one manual Lighthouse pass locally to record baseline metrics:
   ```bash
   vendor/bin/sail npx @lhci/cli collect --url=http://localhost/ --numberOfRuns=1
   vendor/bin/sail npx @lhci/cli upload --target=filesystem --outputDir=./baseline
   ```
4. Note: most phase-1 pages require seeded data to render correctly (View Composers
   query `Page::all()`, SermonController queries sermons, CalendarController queries
   events). Ensure `vendor/bin/sail artisan db:seed` has been run before collecting baselines.

Deliverable:
- Baseline score table saved in `docs/lighthouse-baseline.md`.

## Phase 1: Install LHCI and local command

1. Add dev dependency:
   - `vendor/bin/sail npm i -D @lhci/cli`
2. Add scripts to `package.json`:
   - `lighthouse:ci`: run LHCI autorun
   - optional `lighthouse:collect`: collect only
3. Create `lighthouserc.json` in repo root with:
   - `ci.collect.url` list (phase-1 URLs)
   - `numberOfRuns: 3` for stability
   - `settings.preset: "desktop"` (mobile can be added in phase 5)
   - `settings.chromeFlags: ["--no-sandbox"]` for CI compatibility
4. Add `.lighthouseci/` to `.gitignore` (LHCI generates report artifacts in this directory).

Deliverable:
- `vendor/bin/sail npm run lighthouse:ci` works locally against a running Sail app.

## Phase 2: Add quality gates (assertions)

Define initial pragmatic thresholds in `lighthouserc.json`:
- performance >= 0.80
- accessibility >= 0.90
- best-practices >= 0.90
- seo >= 0.90

Add targeted audit thresholds for critical items:
- `first-contentful-paint`
- `largest-contentful-paint`
- `cumulative-layout-shift`
- `total-blocking-time`

Guidelines:
- Start with realistic thresholds based on baseline, then tighten.
- Prefer category + a few key audit assertions rather than too many brittle checks.

Deliverable:
- CI fails when thresholds are violated.

## Phase 3: CI workflow integration

**Option A (recommended):** Add a `lighthouse` job to the existing `deploy.yml` workflow,
running after the `test` job. This avoids duplicating the PHP/Node/Composer/build setup
that the `test` job already performs. The lighthouse job can reuse the same checkout,
dependency cache, and build steps.

**Option B:** Create a standalone `.github/workflows/lighthouse.yml`. Simpler to reason
about, but duplicates all setup steps from `deploy.yml`. Better suited if Lighthouse
should also run on PRs (the current `deploy.yml` only triggers on push to `master`).

Whichever option is chosen:

1. Trigger on pull requests and push to `master`.
2. Setup Node 22 and PHP 8.4 (matching existing workflow).
3. Install PHP + Composer dependencies.
4. Build frontend assets (`npm ci && npm run build`).
5. Prepare `.env`, generate key, run migrations.
6. **Seed the database** (`php artisan db:seed`) — most phase-1 pages depend on
   Page, Sermon, and Meeting records to render correctly.
7. Set `TRANSCRIPTION_SERVICE_TYPE=mock` in `.env` to prevent the `/up` health
   check from failing on missing OpenAI credentials.
8. Start app server in background (`php artisan serve --host=127.0.0.1 --port=8000 &`).
9. Wait for health endpoint: `until curl -sf http://127.0.0.1:8000/up; do sleep 2; done`.
10. **Warm-up request** to prime OPcache and config: `curl -sf http://127.0.0.1:8000/ > /dev/null`.
11. Run `npm run lighthouse:ci`.
12. Upload LHCI artifacts (`.lighthouseci/`) as workflow artifacts.

Note: LHCI requires Chrome/Chromium. GitHub's `ubuntu-latest` runners include Chrome,
but the `--no-sandbox` flag is needed (configured in `lighthouserc.json`).

Deliverable:
- Lighthouse status check appears in PRs.

## Phase 4: Regression workflow and developer UX

1. Add a short section to `readme.md`:
   - How to run Lighthouse locally
   - How to update thresholds intentionally
2. Add PR guidance:
   - If Lighthouse fails, include reason and mitigation in PR notes.
3. Keep thresholds in version control and review changes like code.

Deliverable:
- Team can run and interpret audits consistently.

## Phase 5: Expand coverage

After 2-3 weeks of stable runs:
- Add mobile preset runs.
- Add more route coverage (e.g., representative sermon detail page).
- Add optional authenticated/admin audits via scripted login flow (Playwright + Lighthouse) if needed.

Deliverable:
- Broader coverage with manageable flake rate.

---

## Example Config Shape

`lighthouserc.json` (illustrative):

```json
{
  "ci": {
    "collect": {
      "numberOfRuns": 3,
      "settings": {
        "preset": "desktop",
        "chromeFlags": ["--no-sandbox"]
      },
      "url": [
        "http://127.0.0.1:8000/",
        "http://127.0.0.1:8000/christ",
        "http://127.0.0.1:8000/church",
        "http://127.0.0.1:8000/community",
        "http://127.0.0.1:8000/calendar",
        "http://127.0.0.1:8000/christ/sermons"
      ]
    },
    "assert": {
      "assertions": {
        "categories:performance": ["warn", { "minScore": 0.8 }],
        "categories:accessibility": ["error", { "minScore": 0.9 }],
        "categories:best-practices": ["error", { "minScore": 0.9 }],
        "categories:seo": ["error", { "minScore": 0.9 }]
      }
    },
    "upload": {
      "target": "filesystem",
      "outputDir": ".lighthouseci"
    }
  }
}
```

Notes:
- `preset: "desktop"` disables mobile throttling for stable CI results. Mobile can be added in phase 5.
- `--no-sandbox` is required for Chrome in CI runners (GitHub Actions runs as root-like user).
- `filesystem` upload keeps reports local (uploaded as workflow artifacts). Avoids `temporary-public-storage` which publishes reports to a publicly accessible Google endpoint.
- Use `warn` for performance initially if desired, then move to `error` once stabilized.

---

## Risks and Mitigations

- CI flakiness from dynamic content or cold starts:
  - Mitigate with `numberOfRuns: 3`, stable URLs, and warm-up request before audits.
- Pages rendering empty or erroring without seed data:
  - Always run `db:seed` in CI before Lighthouse collection. The homepage, church,
    community, sermons, and calendar pages all depend on database records.
- `/up` health check failing on missing external services:
  - Set `TRANSCRIPTION_SERVICE_TYPE=mock` in CI to disable the OpenAI health check.
  - Storage health check should pass as long as `storage/` directories exist.
- Chrome not available or sandboxing issues in CI:
  - GitHub `ubuntu-latest` includes Chrome. Use `--no-sandbox` chrome flag in config.
- Overly strict thresholds causing noisy failures:
  - Start from baseline + incremental hardening.
- Slow CI runtime:
  - Keep initial URL list small; expand gradually.
- Workflow duplication if using a standalone lighthouse workflow:
  - Consider adding Lighthouse as a job in the existing `deploy.yml` to share setup steps.

---

## Definition of Done

1. `vendor/bin/sail npm run lighthouse:ci` is documented and works locally.
2. Lighthouse runs automatically in GitHub Actions on PRs and `master` pushes.
3. CI enforces agreed thresholds for key public routes.
4. Results are available in workflow artifacts for debugging.
5. `.lighthouseci/` is in `.gitignore`.
6. Threshold updates follow normal code review.

---

## Suggested Task Breakdown (PRs)

1. PR 1: Add LHCI dependency, scripts, `lighthouserc.json` with desktop preset, and `.gitignore` entry.
2. PR 2: Add Lighthouse CI job/workflow with seeding, warm-up, and artifact upload.
3. PR 3: Readme docs + tighten assertions based on first stable runs.
