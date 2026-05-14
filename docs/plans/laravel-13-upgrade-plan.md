# Laravel 13 Upgrade Plan

**Source:** https://laravel.com/docs/13.x/upgrade
**Target framework version:** `laravel/framework: ^13.0`
**Current framework version:** `laravel/framework: ^12.0`
**Author:** Claude (plan only — not yet executed)
**Date drafted:** 2026-05-14

---

## 1. Pre-flight context

This codebase already uses the Laravel 12 streamlined bootstrap (`bootstrap/app.php` style with `Application::configure()`), so the structural migration that bit other Laravel 11→12 upgrades does **not** apply. The Laravel 13 upgrade is mostly:

- Dependency version bumps
- One CSRF middleware rename (the only HIGH-impact item that touches this repo)
- A handful of LOW/MEDIUM items that need spot fixes
- A cache prefix change to neutralise before going live

### Repo audit results

Performed during planning (see findings below). All paths are repo-relative.

| Audit check | Result |
|---|---|
| `VerifyCsrfToken` references | None (good — no direct imports) |
| `ValidateCsrfToken` references in tests | 4 files — `tests/Feature/CalendarAdminControllerTest.php`, `tests/Feature/SermonAdminControllerTest.php`, `tests/Feature/MeetingCrudTest.php`, `tests/Feature/UnifiedMediaProcessingTest.php` |
| `upsert()` calls missing `uniqueBy` | None — both call sites in [SongCatalogSyncService.php:479,537](app/Services/SongCatalogSyncService.php) pass non-empty arrays |
| `exceptionOccurred` listeners (JobAttempted) | None |
| `QueueBusy::$connection` listeners | None |
| `Js::from` usages | None |
| `Str::createUuidUsing` / `createUlidUsing` | None |
| Custom `Pivot`/`MorphPivot` classes | None (all pivot relationships use `withPivot()`, not custom pivot classes) |
| Model `boot()` methods doing nested instantiation | None — only `App\Models\Page::booted()` is defined and it's safe |
| Pagination Bootstrap-3 view overrides | None |
| Custom `MustVerifyEmail` / `Cache\Store` / `Bus\Dispatcher` / `Queue` implementations | None (only stock framework usage) |
| `CACHE_PREFIX` / `REDIS_PREFIX` / `SESSION_COOKIE` set in `.env.example` | None — relies on Laravel defaults |
| `config/cache.php` prefix default | `Str::slug(env('APP_NAME', 'laravel'), '_').'_cache'` (the underscore form Laravel 13 changes) |
| Redis prefixes in `config/database.php` | All `'prefix' => ''` — unaffected |
| Session cookie name in `config/session.php` | Explicit `'cookie' => 'laravel_session'` — unaffected |
| Tests asserting "Reset Password Notification" subject | None (`tests/Browser/Auth/ForgotPasswordTest.php` only checks the success message, not the email subject) |

Net effect: this is a **small, surgical upgrade**, not a sprawling one.

---

## 2. Required dependency upgrades

Update `composer.json` `require` and `require-dev` blocks:

| Package | Current | Target |
|---|---|---|
| `laravel/framework` | `^12.0` | `^13.0` |
| `laravel/tinker` | `^2.10` | `^3.0` |
| `laravel/boost` | `^2.1` | `^2.1` (already compatible; keep) |
| `phpunit/phpunit` | `^11.5` | `^12.0` |
| `laravel/sanctum` | `^4.0` | `^4.0` (verify Sanctum 4.x is compatible with L13 — likely yes; bump if a new minor exists) |
| `livewire/livewire` | `^3.0` | `^3.0` (verify; Livewire 3 is L13-compatible) |
| `larastan/larastan` | `^3.5` | `^3.5` (verify; bump if needed for PHP 8.5 / L13 stubs) |
| `laravel/dusk` | `^8.3` | `^8.3` (verify) |
| `laravel/pint` | `^1.23` | `^1.23` (latest minor) |
| `laravel/sail` | `^1.43` | `^1.43` (verify; bump if a Sail release adds PHP 8.5 image support) |
| `nunomaduro/collision` | `^8.8` | bump if required by PHPUnit 12 |
| `brianium/paratest` | `^7.8` | bump if required by PHPUnit 12 |

### PHP version

Laravel 13's upgrade guide implies PHP **8.5** (via `symfony/polyfill-php85`). The current `composer.json` requires `^8.2`. The `docker/` folder ships PHP image variants for 8.0–8.4. Decision needed (see Open Questions §7): pin to `^8.3` (minimum L13 will likely accept) or jump to `^8.5` to match L13's optimistic baseline.

The Sail Docker image used for development will need to expose a PHP 8.5 variant. Laravel Sail typically ships this within ~weeks of the PHP release; verify `vendor/bin/sail` after `composer update` offers a `php85` runtime, or stay on 8.4 if not yet available.

---

## 3. Required code changes

### 3.1 [HIGH] CSRF middleware rename — update 4 test files

`Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` is renamed to `PreventRequestForgery`. The old aliases (`VerifyCsrfToken`, `ValidateCsrfToken`) still resolve but are deprecated.

Update these test files to use the new class:

- [tests/Feature/CalendarAdminControllerTest.php:27](tests/Feature/CalendarAdminControllerTest.php#L27)
- [tests/Feature/SermonAdminControllerTest.php:32](tests/Feature/SermonAdminControllerTest.php#L32)
- [tests/Feature/MeetingCrudTest.php:35](tests/Feature/MeetingCrudTest.php#L35)
- [tests/Feature/UnifiedMediaProcessingTest.php:28](tests/Feature/UnifiedMediaProcessingTest.php#L28)

**Before:**
```php
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

$this->withoutMiddleware(ValidateCsrfToken::class);
```

**After:**
```php
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

$this->withoutMiddleware(PreventRequestForgery::class);
```

Note: the better long-term pattern here is `withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)` being replaced inline rather than via use-statement, but matching the existing import style in each file is fine.

### 3.2 [LOW] Pin cache and session keys in `.env` (mitigation, not code)

Laravel 13 changes the default-prefix separator from `_` to `-`:

- `_cache_` → `-cache-`
- `_database_` (Redis) → `-database-`
- `_session` (cookie name) → `-session`

This codebase is partially insulated:

- `config/session.php` has an **explicit literal** `'cookie' => 'laravel_session'` — **no action needed**.
- `config/database.php` uses `'prefix' => ''` for all Redis connections — **no action needed**.
- `config/cache.php` uses `Str::slug(env('APP_NAME', 'laravel'), '_').'_cache'` — this **does** change after upgrade.

To prevent the production cache from silently switching keyspace at deploy time (which would leave existing entries orphaned, not corrupt anything but cause a transient cold cache), add to `.env` and `.env.example` **before deploying**:

```env
CACHE_PREFIX=crockenhill_cache
```

This is a "set once, then upgrade" step — do it in a prep PR a day or two before the framework bump merges.

### 3.3 [LOW] Verify `upsert()` call sites after upgrade

Laravel 13 throws `InvalidArgumentException` if `uniqueBy` is empty. Both calls in this repo are already safe:

- [app/Services/SongCatalogSyncService.php:479-483](app/Services/SongCatalogSyncService.php#L479-L483) — `uniqueBy: ['display_name']`
- [app/Services/SongCatalogSyncService.php:537](app/Services/SongCatalogSyncService.php#L537) — to be verified by re-reading after upgrade

No change required, but the `SongCatalogSyncService` tests should be run post-upgrade as a sanity check.

### 3.4 [VERY LOW] Defensive items — nothing to change but worth knowing

These Laravel 13 changes were checked against the codebase and found not to apply:

- **`Js::from` unescaped unicode** — not used.
- **`JobAttempted::$exception` rename** — no listeners.
- **`QueueBusy::$connectionName` rename** — no listeners.
- **Custom `Pivot` table pluralisation** — no custom Pivot classes.
- **`Container::call` nullable defaults** — no patterns relying on the old behaviour.
- **Model `boot()` nested instantiation** — only `Page::booted()` exists and it doesn't instantiate.
- **Polymorphic morphs / `morphMap`** — not in scope of any reported break.
- **Pagination Bootstrap-3 view names** — not used.
- **Default password reset subject change** — no tests assert the subject string.

If any of these change between now and the upgrade (this plan is a snapshot), re-audit by grepping for the affected symbols.

### 3.5 [MEDIUM] `serializable_classes` cache hardening — opt in deliberately

Laravel 13 adds `serializable_classes` to `config/cache.php`, defaulting to `false`. With the file driver this codebase uses for tests and the Redis driver it likely uses in production, **any code that caches arbitrary PHP objects breaks** unless they're allow-listed.

This codebase uses caching sparingly (verify by grepping `Cache::put`, `cache()->put`, `Cache::remember`). Most cached values appear to be scalars and arrays, which are unaffected.

**Action:** after the framework bump, audit `Cache::` call sites. If anything caches an Eloquent model, Carbon, Collection, or DTO instance, either:
1. Convert to `->toArray()` before caching (preferred — flatter cache, easier to evolve), or
2. Add the class to a new `'serializable_classes'` config entry.

Do not blanket-enable `serializable_classes => true` — that defeats the security hardening.

---

## 4. Test/CI updates

### 4.1 PHPUnit 12

PHPUnit 12 removes some deprecated APIs. Most likely areas of breakage:

- `@dataProvider` PHPDoc (still works in 12, but `#[DataProvider]` attribute is preferred)
- Deprecated assertion methods (e.g., `assertObjectHasAttribute` removed in 11; verify none re-introduced)
- `setUpBeforeClass` / `tearDownAfterClass` signature checks

Run `vendor/bin/sail artisan test --compact --parallel` after the bump; any breakage will be specific and small.

### 4.2 Larastan / PHPStan

Larastan 3.x already targets Laravel 12; check whether a 3.x patch release adds Laravel 13 stubs. Until it does, expect some "unknown method" false positives on framework classes. Acceptable solution: temporarily add the offending symbols to `phpstan-baseline.neon` or wait for the Larastan patch.

The CLAUDE.md project rule is **0 PHPStan errors at all times** — so the upgrade PR must end at 0 errors, possibly via a baseline if Larastan lags.

### 4.3 Dusk (CI)

`.env.dusk.ci` and the dusk job in CI shouldn't be affected by the framework bump as long as the cache prefix is pinned (§3.2). If Dusk 8 doesn't yet support L13, defer dusk-job-passing as a follow-up and skip-flag the workflow with a tracking issue.

---

## 5. Execution sequence

Suggested ordering, each step in its own commit:

1. **Prep PR (merge first, deploy first):**
   - Add `CACHE_PREFIX=crockenhill_cache` to `.env` (production) and `.env.example`.
   - No code changes. Verifies cache still works under explicit prefix before the keyspace changes underneath us.

2. **Upgrade PR:**
   1. Bump `composer.json` — framework, tinker, phpunit, and any deps that PHPUnit 12 requires.
   2. `vendor/bin/sail composer update` and commit the lockfile.
   3. Rename `ValidateCsrfToken` → `PreventRequestForgery` in the 4 test files (§3.1).
   4. Run `vendor/bin/sail bin pint --dirty`.
   5. Run `vendor/bin/sail composer phpstan` — fix or baseline.
   6. Run `vendor/bin/sail artisan test --compact --parallel`.
   7. Run `vendor/bin/sail artisan dusk` (or skip if Dusk 8 + L13 isn't ready — see §4.3).
   8. Audit `Cache::` calls per §3.5 if any cached object types exist.

3. **Post-merge cleanup PR (optional):**
   - Remove any temporary PHPStan baseline entries once Larastan catches up.
   - Tighten `serializable_classes` allow-list if anything was added permissively.

---

## 6. Verification checklist (the four standard checks plus upgrade-specific)

Standard project workflow:

- [ ] `vendor/bin/sail bin pint --dirty` — clean
- [ ] `vendor/bin/sail composer phpstan` — 0 errors
- [ ] `vendor/bin/sail artisan test --compact --parallel` — all green
- [ ] `vendor/bin/sail artisan dusk` — all green

Upgrade-specific:

- [ ] `composer show laravel/framework` reports `13.x`
- [ ] `php artisan about` runs without warnings
- [ ] CSRF still works in the browser (login form posts succeed)
- [ ] A representative cached value is readable post-deploy (cache prefix didn't drift)
- [ ] `SongCatalogSyncService` integration test passes (`upsert()` validation didn't trip)
- [ ] Queues drain normally (no `JobAttempted` / `QueueBusy` listener regressions)

---

## 7. Open questions

1. **PHP version target.** Stay on 8.2 minimum (`composer.json` current floor), bump to 8.3 (safer modern floor), or jump to 8.5 to match Laravel 13's polyfill assumption? Production servers' installed PHP version drives this.
2. **Sail PHP 8.5 image availability.** If Sail hasn't shipped a `php85` Docker variant yet, development would stay on 8.4 while L13 itself loads the `symfony/polyfill-php85` shim. This works but is worth confirming.
3. **Timing relative to `master` branch.** Currently on `master` with uncommitted plan-doc changes (`docs/plans/import-historic-videos.md` deletion, `docs/archived-plans/import-historic-videos.md` add). The upgrade should land on a clean working tree.
4. **Laravel 13 release status.** This plan was drafted against the published 13.x upgrade guide. If Laravel 13 hasn't shipped a stable `13.0.0` yet, hold the upgrade PR until it does — the guide can shift on minor points up to release.

---

## 8. Rollback plan

Because the only structural code change is the CSRF middleware class rename in test files (which doesn't affect runtime behaviour), rollback is straightforward:

1. Revert the upgrade PR.
2. `composer install` restores Laravel 12 from the lockfile.
3. The `CACHE_PREFIX` env var stays — it's harmless on Laravel 12 and avoids re-orphaning the cache on rollback.

No database migrations are involved, so there is no schema rollback to worry about.
