# Application error tracking — Sentry Cloud (EU region)

> **Status (reconciled 2026-08-12): optional, not started; dependency approval required.**
> `sentry/sentry-laravel` is not installed. The current upstream package line supports Laravel 13,
> but resolve the current stable compatible version at implementation time instead of preserving
> the old `^4.25` suggestion. This plan is independently useful and may ship at any time, but the
> historic final-readiness plan's D7 formally accepted rotating logs instead of Sentry for the import
> window. Sentry is therefore not an import gate. The
> [architectural-maintainability plan](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) owns
> production log rotation and terminal processing-state convergence.

## Outcome

Add a third observability layer alongside deploy smoke checks and scheduled Laravel Health checks:
unexpected request, job, and command failures are captured when they happen, with a stack trace and
the existing deployment release identifier. Local development and CI remain network-free by default.

Sentry owns generic application exception capture. The
[historic incremental convergence plan](HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md) owns import-specific
release evidence, its accepted log-only monitoring record, and rollback decisions. The architectural
plan owns log transport/retention and application state transitions; this plan observes their final
failure boundary rather than defining it.

## Existing seams to preserve

- `docker-compose.prod.yml` already injects `APP_RELEASE_IDENTIFIER=${IMAGE_TAG}`.
- `config/app.php` already exposes that value as `config('app.release_identifier')`.
- `bootstrap/app.php` already has an exception configuration closure and custom renderers, including
  the frozen historic-import 503 response and `ProvidesSafeMessage` 422 response.
- Laravel Health continues to own predicted state failures. Do not duplicate its checks in Sentry.

Do **not** add a second `APP_VERSION`/`GIT_SHA` build-argument path. One release identity must serve
deploys, historic runs, logs, and Sentry.

## Delivery 1 — install, configure, and capture unhandled failures

This is the first independently deployable slice.

1. Obtain approval for the new Composer dependency, then require the current stable
   `sentry/sentry-laravel` version compatible with Laravel 13 and PHP 8.4/8.5. Record the resolved
   version in the PR; do not hard-code an old plan-time constraint.
2. Publish the SDK's current Laravel config using its documented command and reduce it to the options
   this application deliberately owns.
3. Configure:
   - an empty-by-default `SENTRY_LARAVEL_DSN`, so local and CI runs are no-ops;
   - `release` from `SENTRY_RELEASE`, falling back to `APP_RELEASE_IDENTIFIER`;
   - `environment` from `SENTRY_ENVIRONMENT`, falling back to `APP_ENV`;
   - `send_default_pii=false`;
   - no request bodies;
   - errors only initially, with performance tracing disabled unless explicitly enabled later.
4. Add the SDK's documented `Integration::handles($exceptions)` call inside the **existing**
   `bootstrap/app.php` exception closure. Preserve every current renderer and JSON decision.
5. Enable duplicate suppression if compatible with the existing reporting behaviour.

Do not add a framework-wide `$exceptions->throttle()` in this slice. That would also suppress normal
Laravel logging and could hide distinct failures that share a generic exception class. Start with
Sentry project quotas and alert rules; introduce a narrowly scoped, evidence-led guard only if actual
event volume requires one.

### Privacy implementation

Sentry can retain a URL query string even when default PII is disabled. Add a small exportable
event-scrubber callable that removes sensitive query keys such as `token`, `signature`, `email`,
`key`, and their established application variants. Reference the callable from config without an
anonymous closure so `config:cache` and `config:clear` remain reliable.

Keep secrets outside the repository. `.env.example` should contain only empty, commented settings;
the production DSN belongs in the production secret/environment configuration.

### Delivery 1 tests

- release precedence: `SENTRY_RELEASE` overrides `APP_RELEASE_IDENTIFIER`;
- the app release identifier is the default Sentry release;
- the event scrubber removes sensitive query values without damaging ordinary query parameters;
- a fake SDK hub/transport receives an unhandled test exception without making a network request;
- existing frozen-503 and `ProvidesSafeMessage` render tests remain green;
- cached configuration boots successfully.

## Delivery 2 — report verified terminal failures that are caught

Unhandled integration cannot see exceptions that application code deliberately catches. Audit
terminal `Log::error` paths, beginning with
`App\Services\Processing\ProcessingRunFailureHandler::handle()`, and add `report($exception)` only
where the catch represents an unexpected **final** failure.

For processing, sequence this delivery after architectural AM8 has made that handler the sole
terminal owner. If Sentry Delivery 1 lands first, leave caught processing paths unchanged until AM8;
do not add reporting to job catches or `failed()` callbacks that AM8 will remove.

Keep expected states out of the error budget:

- a retry that will run again;
- manual-review transitions;
- validation/domain rejections already represented to the operator;
- deliberate Horizon worker replacement during deploys.

Do not mechanically convert every `Log::error` call. For each newly reported path, add a focused test
using Laravel's exception fake or the SDK's fake hub/transport, and verify one terminal event rather
than one event per retry. This slice is independently deployable after Delivery 1.

## Delivery 3 — production activation and acceptance

These are maintainer/operator actions, not repository automation:

1. Create or select the EU-region Sentry project and inject its DSN into production.
2. Configure new-issue and regression alerts to the agreed email/Slack destinations.
3. Deploy with the existing `APP_RELEASE_IDENTIFIER` path and run the SDK's supplied controlled test
   command.
4. Confirm the event has the deployed release identifier, production environment, scrubbed request
   data, and no request body or user identity.
5. Exercise one controlled caught-terminal test path in a non-production environment before relying
   on Delivery 2 in production.

If Sentry is activated before the historic production apply, record the accepted canary and
monitoring destination in its runbook. The operation does not wait for Sentry under D7; it waits for
the rotating-log evidence owned by architectural AM3. A Sentry event is evidence of an application
failure, not proof that an import invariant failed; the historic plan retains that decision.

## Files expected to change

- `composer.json` and `composer.lock`
- `config/sentry.php`
- `bootstrap/app.php`
- `.env.example`
- one exportable event scrubber under the existing application structure
- `ProcessingRunFailureHandler` and only other audited terminal catch paths
- focused unit/feature tests

No Dockerfile or deploy-workflow release-tag change is expected.

## Quality gates

For each repository delivery:

1. focused tests for configuration, privacy, capture, and any caught-terminal path;
2. `vendor/bin/sail composer phpstan`;
3. `vendor/bin/sail bin pint --dirty`;
4. `vendor/bin/sail artisan test --parallel --compact` for the dependency/integration slice.

Dusk is not required because this plan has no browser behaviour.

## Decisions still required

- approve the Composer dependency and Sentry Cloud EU account;
- choose alert destinations and the initial project-side event quota;
- decide later, from production evidence, whether performance tracing or Sentry-scoped sampling is
  worth enabling.
