# Playwright Visual Regression Tests

These tests take pixel-level screenshots of key public pages and diff them against
committed baselines under `tests/Playwright/snapshots/`. Any styling regression
(broken navbar background, layout shift, font fallback) will fail the
`playwright` job in CI and block deploy.

This sits alongside Dusk — Dusk verifies *interaction*, Playwright verifies
*visual output*.

## Running locally

Visual snapshots are sensitive to font rendering, so the **only supported way**
to update or verify baselines is inside the official Playwright Docker image
that CI uses. Otherwise baselines generated on macOS will fail on Linux CI.

### 1. Start the Sail stack

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan migrate:fresh --seed
```

Confirm the site is reachable at `http://localhost`.

### 2. Run Playwright in its Docker image

The image is pinned to the same version as `@playwright/test` in `package.json`.

```bash
docker run --rm -it \
  -v "$(pwd):/work" -w /work \
  --network host \
  -e PLAYWRIGHT_BASE_URL=http://localhost \
  mcr.microsoft.com/playwright:v1.62.1-jammy \
  bash -c "npm ci && npx playwright test"
```

Open `playwright-report/index.html` to view results.

## Updating baselines

When you intentionally change the UI, baselines need to be regenerated.

```bash
docker run --rm -it \
  -v "$(pwd):/work" -w /work \
  --network host \
  -e PLAYWRIGHT_BASE_URL=http://localhost \
  mcr.microsoft.com/playwright:v1.62.1-jammy \
  bash -c "npm ci && npx playwright test --update-snapshots"
```

Then `git add tests/Playwright/snapshots/` and commit. Review the PNG diff in
the PR — the new images should reflect only the intended visual change.

## CI workflow

The `playwright` job in `.github/workflows/deploy.yml`:

1. Runs inside `mcr.microsoft.com/playwright:v1.62.1-jammy` (matches local).
2. Provisions a fresh `testing_pw` MySQL DB, migrates, seeds.
3. Boots `php artisan serve` and waits for `/up`.
4. Runs `npx playwright test` against `http://127.0.0.1:8000`.
5. On failure, uploads `playwright-report/` and `test-results/` as artifacts —
   download these to see actual/expected/diff PNGs.

## What's covered

| Spec | Pages |
| --- | --- |
| `homepage.spec.ts` | `/` (full page + header-only crop) |
| `section-landings.spec.ts` | `/christ`, `/church`, `/community`, `/calendar` |
| `sermons.spec.ts` | `/christ/sermons` index + first seeded sermon detail |
| `meeting-detail.spec.ts` | First meeting linked from `/community` |
| `church-services.spec.ts` | `/church/services` archive + first seeded service detail |
| `mobile-nav.spec.ts` | Homepage with mobile menu opened (mobile project only) |

Each spec runs in two Chromium projects: `desktop-chromium` (1280×800) and
`mobile-chromium` (375×812), unless the spec opts out of one.

## When a snapshot fails

1. Look at the GitHub Actions artifact `playwright-report` (failure runs only).
2. Inside the report HTML, each failing test shows three images: expected
   (committed baseline), actual (this run), and diff (red overlay).
3. If the diff is an **unintended** regression — fix the code.
4. If the diff is an **intended** UI change — re-run with `--update-snapshots`
   locally as above and commit the new PNGs.
