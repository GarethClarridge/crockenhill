# Dev and Deploy Pipeline Optimisation Plan

Created: 2026-05-29

This plan turns GitHub Actions into the shared quality gate for Jules, Codex, Claude Code, and manual development. The current pipeline has strong deployment work, but most Laravel-specific validation happens after changes reach `master`, inside the deployment workflow. Recent PRs showed CodeQL checks only before merge, so the first meaningful app validation can happen too late.

## Goals

- Catch Laravel, PHPStan, formatting, frontend build, and targeted test failures before PRs merge.
- Keep PR feedback fast enough for agent and human iteration.
- Keep expensive browser, visual, Lighthouse, and full-suite checks available without blocking every tiny PR.
- Make production deploys serialized, traceable, and easy to recover from.
- Reduce duplicated GitHub Actions setup so the pipeline stays maintainable.

## Baseline State Before Implementation

- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml) is the only local workflow.
- The workflow triggers on `push` to `master` and `workflow_dispatch`, not `pull_request`.
- Deploy already builds and pushes a SHA-tagged GHCR image, smokes the image, deploys with `IMAGE_TAG`, backs up the database, runs migrations, optimizes Laravel, and runs [scripts/post-deploy-smoke.sh](../../scripts/post-deploy-smoke.sh).
- Dusk, Lighthouse, and Playwright jobs exist in the deploy workflow, but only `test`, `dusk`, and `lighthouse` are build prerequisites. Playwright currently runs without blocking the image build or deploy.
- Pint is currently `continue-on-error` in CI, so formatting drift is visible but not enforced.
- Recent PRs from Jules and Claude only exposed CodeQL checks before merge, not the project quality gates.

## Implementation Status

Code-side implementation completed on 2026-05-29:

- Added PR CI in `.github/workflows/pr.yml`.
- Split expensive browser, visual, and Lighthouse checks into `.github/workflows/nightly.yml`.
- Refactored `.github/workflows/deploy.yml` around master validation, Dusk, build, image smoke, serialized deploy, provenance, and summaries.
- Added `.github/workflows/rollback.yml`.
- Added `.github/dependabot.yml`.
- Added `.github/CODEOWNERS` for production-sensitive paths.
- Added `.github/actions/setup-laravel/action.yml`.
- Extracted deploy guard scripts into `scripts/check-deploy-view-cache-safety.sh` and `scripts/check-schema-dump-current.sh`.

Repository settings still need to be changed in GitHub after the PR workflow has run successfully at least once:

- Require the `Laravel Quality Gate` PR check on `master`.
- Consider production environment reviewers.
- Enable CODEOWNER-backed reviews for deployment-sensitive paths.

Already enabled in GitHub repository settings on 2026-05-29:

- Auto-merge.
- Update branch.

## Non-Goals

- Do not replace the current Docker/GHCR production deployment model.
- Do not add new runtime dependencies.
- Do not require Dusk, Playwright, or Lighthouse on every docs-only or low-risk PR.
- Do not make CI depend on Laravel Sail; GitHub Actions should keep using native PHP/Node/MySQL like the current workflow.

## Quality Gates for This Work

- YAML validation via GitHub Actions itself on a draft PR.
- One manual PR test run against a harmless branch before branch protection is tightened.
- Confirm branch protection only after the new PR checks have reported successfully at least once.
- No application test changes are required unless pipeline scripts are changed in a way that needs tests.

## Phase 1: Add Fast PR CI

Priority: **High**

Target files:

- `.github/workflows/pr.yml` — new.
- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml) — reference point only in this phase.

Tasks:

- [ ] Create a `PR` workflow triggered by:
  - `pull_request` to `master`
  - `merge_group` if merge queue is enabled later
  - `workflow_dispatch` for manual reruns
- [ ] Add top-level permissions:
  - `contents: read`
- [ ] Add concurrency:
  - group by workflow + PR ref
  - `cancel-in-progress: true` for PR validation
- [ ] Add a `quality` job with:
  - checkout
  - `./scripts/check-bootstrap-safety.sh`
  - deploy view-cache safety guard extracted from the current deploy workflow
  - PHP 8.4 setup with project extensions
  - FFmpeg install
  - Composer cache/install
  - Node 22 setup with npm cache
  - `npm ci && npm run build`
  - Laravel test environment setup
  - migrations
  - `./vendor/bin/pint --test`
  - `./scripts/check-typing-baseline.sh`
  - `composer phpstan`
  - `php artisan test --parallel --compact --exclude-group=dedicated --exclude-group=performance`
- [ ] Upload test/log artifacts on failure.
- [ ] Add a GitHub step summary with the commands run and failure pointers.

Exit criteria:

- Every PR gets one fast, required Laravel check before merge.
- Formatting is enforced before merge.
- The app can no longer be deployed from a PR that never passed PHPUnit/PHPStan/build checks.

## Phase 2: Split Validation from Deployment

Priority: **High**

Target files:

- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml)
- `.github/workflows/pr.yml`

Tasks:

- [ ] Keep `deploy.yml` triggered by `push` to `master` and `workflow_dispatch`.
- [ ] Remove duplicate pre-merge validation assumptions from deploy once PR CI is required.
- [ ] Keep a short production-preflight job in deploy:
  - bootstrap guard
  - deploy view-cache guard
  - `composer install`
  - `npm ci && npm run build`
  - PHPStan or a smoke-level static check if runtime allows
- [ ] Keep deployment responsible for:
  - build and push image
  - smoke SHA-tagged image
  - production deployment
  - post-deploy smoke checks
- [ ] Decide whether deploy should run a full PHPUnit pass after merge or trust required PR CI plus the merge commit. If retained, run it as a separate `master-validation` job before build.

Exit criteria:

- PR checks answer "can this merge?"
- Deploy checks answer "can this artifact ship?"
- Deployment logs are shorter and easier to reason about.

## Phase 3: Make Browser, Visual, and Lighthouse Checks Intentional

Priority: **High**

Target files:

- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml)
- `.github/workflows/nightly.yml` — new.
- [lighthouserc.json](../../lighthouserc.json)

Tasks:

- [ ] Decide one blocking policy for Playwright:
  - **Option A:** Add `playwright` to deploy `build.needs` so visual regression blocks deployment.
  - **Option B:** Move Playwright to nightly and label-triggered PR checks so it does not silently look important while being non-blocking.
- [ ] Keep Dusk as a small smoke suite. Do not let Dusk grow into broad feature coverage.
- [ ] Move Lighthouse to nightly unless a PR touches frontend/public-page files or has a `needs-lighthouse` label.
- [ ] Add `workflow_dispatch` inputs to run:
  - Dusk only
  - Playwright only
  - Lighthouse only
  - all browser/visual checks
- [ ] Upload Playwright reports, Dusk screenshots, Laravel logs, and Lighthouse reports on failure.

Exit criteria:

- Browser and visual checks are either clearly blocking or clearly advisory.
- Developers and agents can request heavier checks without running them on every small PR.

## Phase 4: Serialize and Harden Production Deploys

Priority: **High**

Target files:

- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml)
- [scripts/post-deploy-smoke.sh](../../scripts/post-deploy-smoke.sh)

Tasks:

- [ ] Add workflow or deploy-job concurrency:
  - group: `production`
  - `cancel-in-progress: false`
- [ ] Ensure deploys cannot overlap if multiple PRs are merged in quick succession.
- [ ] Add a GitHub step summary with:
  - deployed SHA
  - image reference
  - backup filename
  - migration status
  - post-deploy smoke result
- [ ] Upload remote smoke output as an artifact if feasible.
- [ ] Confirm the `production` environment has protection rules appropriate for the site:
  - optional required reviewer
  - secrets scoped to environment
  - deployment branch limited to `master`

Exit criteria:

- Only one production deploy can run at a time.
- A failed deploy leaves enough information in GitHub to decide whether to rerun, rollback, or SSH in.

## Phase 5: Add Nightly Deep Checks

Priority: **Medium**

Target files:

- `.github/workflows/nightly.yml` — new.

Tasks:

- [ ] Trigger nightly checks on a quiet schedule, e.g. early morning UK time.
- [ ] Include:
  - full PHPUnit suite with `--parallel --compact`
  - dedicated tests
  - Dusk
  - Playwright visual regression
  - Lighthouse
  - dependency audit where practical
  - optional link/path crawl if a stable command exists
- [ ] Upload reports even on success for trend inspection where useful.
- [ ] Add a summary that is readable without opening raw logs.
- [ ] Consider notifying only on failure to avoid alert fatigue.

Exit criteria:

- Expensive checks run regularly without slowing every PR.
- Nightly failures create a clear work queue for humans or agents.

## Phase 6: Reduce Workflow Duplication

Priority: **Medium**

Target files:

- `.github/actions/setup-laravel/action.yml` — new composite action.
- `.github/workflows/pr.yml`
- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml)
- `.github/workflows/nightly.yml`

Tasks:

- [ ] Extract repeated setup into a local composite action:
  - PHP setup
  - FFmpeg install
  - Composer cache/install
  - Node setup
  - npm install/build, optionally controlled by input
  - storage/bootstrap directory creation
- [ ] Keep job-specific database configuration in each workflow so test environments remain explicit.
- [ ] Consider a reusable `workflow_call` workflow only after the composite action has proven useful. Composite actions fit repeated step setup better than reusable workflows because they can be used inside ordinary jobs.
- [ ] Replace duplicated setup blocks across PR, deploy, Dusk, Playwright, and Lighthouse jobs.

Exit criteria:

- Updating PHP extensions, Node version, or install flags happens in one place.
- Workflow files describe the job intent, not pages of repeated setup.

## Phase 7: Improve GitHub Repository Settings

Priority: **Medium**

Repository settings, not code:

- Enable auto-merge.
- Enable "allow update branch".
- Require PR CI before merging to `master`.
- Require branches to be up to date or use merge queue if available.
- Prefer squash merges for agent PRs unless preserving multiple commits is useful.
- Consider requiring review for production-sensitive paths:
  - `.github/**`
  - `docker/**`
  - `docker-compose.prod.yml`
  - `scripts/post-deploy-smoke.sh`
  - `bootstrap/app.php`
  - migrations

Exit criteria:

- Agents can open PRs and humans can approve them without babysitting checks.
- GitHub blocks merges that bypass the agreed quality gates.

## Phase 8: Add Dependabot Maintenance

Priority: **Medium**

Target files:

- `.github/dependabot.yml` — new.

Tasks:

- [ ] Add grouped weekly updates for:
  - Composer
  - npm
  - GitHub Actions
  - Docker, if Dependabot can detect the repo's Docker usage cleanly
- [ ] Keep security updates enabled.
- [ ] Group minor/patch updates to avoid PR noise.
- [ ] Keep major updates separate so they get deliberate review.

Exit criteria:

- Dependency drift becomes a regular, reviewable maintenance stream.
- Action version updates no longer rely on ad hoc manual sweeps.

## Phase 9: Add Build Provenance and Rollback Workflow

Priority: **Low-Medium**

Target files:

- [.github/workflows/deploy.yml](../../.github/workflows/deploy.yml)
- `.github/workflows/rollback.yml` — new.

Tasks:

- [ ] Add container image artifact attestations if supported for the repository plan.
- [ ] Capture the Docker image digest from `docker/build-push-action`.
- [ ] Prefer deploying by digest if it works cleanly with the production Compose setup; otherwise keep SHA tags and record the digest in the job summary.
- [ ] Create a manual rollback workflow with inputs:
  - image tag or SHA
  - optional confirmation string
- [ ] The rollback workflow should:
  - set `IMAGE_TAG`
  - pull the selected image
  - restart services
  - run post-deploy smoke checks
  - avoid running migrations backwards automatically
- [ ] Document database restore as a manual operator step, not an automatic rollback side effect.

Exit criteria:

- A known-good application image can be redeployed from GitHub without editing server files by hand.
- Each production image has a traceable build origin.

## Suggested Implementation Order

1. Phase 1: Add `pr.yml`.
2. Phase 7: Enable required PR checks after `pr.yml` has passed on at least one PR.
3. Phase 4: Add production deploy concurrency and summaries.
4. Phase 3: Decide Playwright/Dusk/Lighthouse blocking policy.
5. Phase 5: Add nightly deep checks.
6. Phase 6: Extract repeated setup once the workflow shape settles.
7. Phase 8: Add Dependabot.
8. Phase 9: Add provenance and rollback workflow.

## Agent Prompt Guidance

For Jules, Codex, Claude Code, or any future agent creating pipeline PRs:

- Keep each PR to one phase unless the phase is tiny.
- Do not silently weaken existing deploy safety checks.
- Do not make formatting advisory once PR CI exists.
- Do not require browser or visual checks for every docs-only PR.
- Include the exact Actions run URL in the PR description once verified.
- For workflow changes, include a rollback note explaining which workflow file can be reverted safely.

## References

- GitHub Actions deployments, environments, and concurrency: <https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments>
- Reusable workflows and composite action distinction: <https://docs.github.com/en/actions/concepts/workflows-and-actions/reusable-workflows>
- Merge queue `merge_group` trigger requirements: <https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/configuring-pull-request-merges/managing-a-merge-queue>
- Job summaries via `GITHUB_STEP_SUMMARY`: <https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions>
- Artifact attestations: <https://docs.github.com/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations>
- Dependabot version updates: <https://docs.github.com/en/code-security/dependabot/dependabot-version-updates/configuring-dependabot-version-updates>
