# Automated Lighthouse Testing Plan

## Goal

Add reliable, repeatable Lighthouse audits for key public pages so performance, accessibility, and best-practices regressions are caught on deploy.

## Recommended Approach

Use **Lighthouse CI (LHCI)** with:
- Local runs for quick feedback (`vendor/bin/sail npm run lighthouse:ci`)
- GitHub Actions runs on push to `master` (as a job in the existing `deploy.yml`)
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
3. Run one manual Lighthouse pass locally to set initial thresholds from the first run.
   ```bash
   vendor/bin/sail npx @lhci/cli collect --url=http://localhost/ --numberOfRuns=1
   vendor/bin/sail npx @lhci/cli upload --target=filesystem --outputDir=./.lighthouseci
   ```
4. Note: most phase-1 pages require seeded data to render correctly (View Composers
   query `Page::all()`, SermonController queries sermons, CalendarController queries
   events). Ensure `vendor/bin/sail artisan db:seed` has been run before collecting baselines.

Deliverable:
- Initial thresholds set in `lighthouserc.json` based on first run scores.

## Phase 1: Install LHCI and local command

1. Add dev dependency:
   - `vendor/bin/sail npm i -D @lhci/cli`
2. Add scripts to `package.json`:
   - `lighthouse:ci`: run LHCI autorun
   - optional `lighthouse:collect`: collect only
3. Create `lighthouserc.json` in repo root with:
   - `ci.collect.url` list (phase-1 URLs)
   - `ci.collect.numberOfRuns: 3` for local stability, `1` for CI (controlled via env override)
   - `settings.preset: "desktop"` (mobile can be added in phase 5)
   - `settings.chromeFlags: ["--no-sandbox", "--headless"]`
   - `settings.maxWaitForLoad: 45000` (cold Laravel apps in CI can be slow)
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
- Start with realistic thresholds based on first run, then tighten.
- Prefer category + a few key audit assertions rather than too many brittle checks.

Deliverable:
- CI fails when thresholds are violated.

## Phase 3: CI workflow integration

Add a `lighthouse` job to the existing `deploy.yml` workflow. This runs on push to
`master` only, matching the existing trigger. It avoids duplicating setup steps and
keeps Lighthouse alongside the other quality gates. The Dusk job provides a good
template for the "start server and wait" pattern.

Steps:

1. Setup Node 22 and PHP 8.4 (matching existing workflow).
2. Install PHP + Composer dependencies.
3. Build frontend assets (`npm install && npm run build`).
4. Prepare `.env`, generate key, run migrations.
5. **Seed the database** (`php artisan db:seed`) — most phase-1 pages depend on
   Page, Sermon, and Meeting records to render correctly.
6. Set `TRANSCRIPTION_SERVICE_TYPE=mock` in `.env` to prevent the `/up` health
   check from failing on missing OpenAI credentials.
7. Start app server in background (`php artisan serve --host=127.0.0.1 --port=8000 &`).
8. Wait for health endpoint (use Dusk job's pattern):
   ```bash
   for i in $(seq 1 30); do
     STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/up 2>/dev/null || echo "000")
     if [ "$STATUS" = "200" ]; then echo "App server ready"; break; fi
     echo "Waiting for app server ($i/30)..."; sleep 2
   done
   [ "$STATUS" = "200" ] || { echo "App server failed to start"; exit 1; }
   ```
9. **Warm-up requests** to all audited URLs (prime OPcache, config, and View Composers):
   ```bash
   for url in / /christ /church /community /calendar /christ/sermons; do
     curl -sf "http://127.0.0.1:8000${url}" > /dev/null
   done
   ```
10. Run `npx @lhci/cli autorun --collect.numberOfRuns=1` (override to 1 run in CI for speed).
11. Upload LHCI artifacts (`.lighthouseci/`) as workflow artifacts with `if: always()`
    so reports are available even on failure.

Note: LHCI requires Chrome/Chromium. GitHub's `ubuntu-latest` runners include Chrome,
but the `--no-sandbox` and `--headless` flags are needed (configured in `lighthouserc.json`).

Deliverable:
- Lighthouse runs as part of the deploy pipeline on every push to `master`.

## Phase 4: Regression workflow and developer UX

1. Add a short section to `readme.md`:
   - How to run Lighthouse locally
   - How to update thresholds intentionally
2. Keep thresholds in version control and review changes like code.

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
        "chromeFlags": ["--no-sandbox", "--headless"],
        "maxWaitForLoad": 45000
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
- `--headless` is explicit to avoid surprises (LHCI defaults to headless, but being explicit is safer).
- `maxWaitForLoad: 45000` gives cold Laravel apps extra time on first load in CI.
- `filesystem` upload keeps reports local (uploaded as workflow artifacts). Avoids `temporary-public-storage` which publishes reports to a publicly accessible Google endpoint.
- Use `warn` for performance initially if desired, then move to `error` once stabilized.
- In CI, override `numberOfRuns` to 1 via CLI flag for speed. Locally, the config default of 3 provides more stable results.

---

## Risks and Mitigations

- CI flakiness from dynamic content or cold starts:
  - Mitigate with warm-up requests to all audited URLs, `maxWaitForLoad: 45000`, and stable URLs.
  - Use `numberOfRuns: 1` in CI to keep runtime reasonable; increase if flakiness is observed.
- Pages rendering empty or erroring without seed data:
  - Always run `db:seed` in CI before Lighthouse collection. The homepage, church,
    community, sermons, and calendar pages all depend on database records.
- `/up` health check failing on missing external services:
  - Set `TRANSCRIPTION_SERVICE_TYPE=mock` in CI to disable the OpenAI health check.
  - Storage health check should pass as long as `storage/` directories exist.
- Chrome not available or sandboxing issues in CI:
  - GitHub `ubuntu-latest` includes Chrome. Use `--no-sandbox` and `--headless` chrome flags in config.
- Overly strict thresholds causing noisy failures:
  - Start from first-run scores + incremental hardening.
- Slow CI runtime:
  - `numberOfRuns: 1` in CI. Keep initial URL list small; expand gradually.

---

## Definition of Done

1. `vendor/bin/sail npm run lighthouse:ci` is documented and works locally.
2. Lighthouse runs automatically in GitHub Actions on push to `master`.
3. CI enforces agreed thresholds for key public routes.
4. Results are available in workflow artifacts for debugging (uploaded with `if: always()`).
5. `.lighthouseci/` is in `.gitignore`.
6. Threshold updates follow normal code review.

---

## Suggested Task Breakdown (PRs)

1. PR 1: Add LHCI dependency, scripts, `lighthouserc.json` with desktop preset, and `.gitignore` entry.
2. PR 2: Add Lighthouse CI job to `deploy.yml` with seeding, warm-up, and artifact upload.
3. PR 3: Readme docs + tighten assertions based on first stable runs.
