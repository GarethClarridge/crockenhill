# Automated Lighthouse Testing Plan

## Goal

Add reliable, repeatable Lighthouse audits for key public pages so performance, accessibility, and best-practices regressions are caught before deploy.

## Recommended Approach

Use **Lighthouse CI (LHCI)** with:
- Local runs for quick feedback (`npm run lighthouse:ci`)
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
   - Prefer a CI-started local app (`php artisan serve`) with built assets.
2. Finalize 5-8 URLs for stable auditing (avoid highly dynamic pages initially).
3. Run one manual Lighthouse pass locally to record baseline metrics.

Deliverable:
- Baseline score table saved in `docs/lighthouse-baseline.md`.

## Phase 1: Install LHCI and local command

1. Add dev dependency:
   - `npm i -D @lhci/cli`
2. Add scripts to `package.json`:
   - `lighthouse:ci`: run LHCI autorun
   - optional `lighthouse:collect`: collect only
3. Create `lighthouserc.json` in repo root with:
   - `ci.collect.url` list (phase-1 URLs)
   - `numberOfRuns: 3` for stability
   - desktop settings first (mobile can be phase 2)

Deliverable:
- `npm run lighthouse:ci` works locally against a running local app.

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

Add a new GitHub Actions workflow (for example `.github/workflows/lighthouse.yml`):

1. Trigger on pull requests and push to `master`.
2. Setup Node (same major version policy as current workflows).
3. Install PHP + Composer dependencies.
4. Build frontend assets (`npm ci && npm run build`).
5. Prepare `.env`, generate key, run migrations if needed.
6. Start app server in background (`php artisan serve --host=127.0.0.1 --port=8000`).
7. Wait for health endpoint (`/up`).
8. Run `npm run lighthouse:ci`.
9. Upload LHCI artifacts (`.lighthouseci/`) as workflow artifacts.

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
      "target": "temporary-public-storage"
    }
  }
}
```

Use `warn` for performance initially if desired, then move to `error` once stabilized.

---

## Risks and Mitigations

- CI flakiness from dynamic content or cold starts:
  - Mitigate with `numberOfRuns: 3`, stable URLs, and warm-up request before audits.
- Overly strict thresholds causing noisy failures:
  - Start from baseline + incremental hardening.
- Slow CI runtime:
  - Keep initial URL list small; expand gradually.

---

## Definition of Done

1. `npm run lighthouse:ci` is documented and works locally.
2. Lighthouse runs automatically in GitHub Actions on PRs.
3. CI enforces agreed thresholds for key public routes.
4. Results are available in workflow artifacts for debugging.
5. Threshold updates follow normal code review.

---

## Suggested Task Breakdown (PRs)

1. PR 1: Add LHCI dependency, scripts, and `lighthouserc.json` with baseline URLs.
2. PR 2: Add `.github/workflows/lighthouse.yml` and artifact upload.
3. PR 3: Add/readme docs + tighten assertions based on first stable runs.
