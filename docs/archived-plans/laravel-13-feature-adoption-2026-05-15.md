# Laravel 13 Feature Adoption Plan

**Companion to:** [laravel-13-upgrade-plan.md](laravel-13-upgrade-plan.md) (the upgrade itself, already completed)
**Target framework version:** `laravel/framework: ^13.0` (currently installed: `v13.9.0`)
**Date drafted:** 2026-05-15
**Author:** Claude (plan only — not yet executed)

---

## 1. Purpose

The upgrade plan covered breaking changes and dependency bumps. This plan covers the *opportunities* the upgrade opens up: Laravel 13 features that map cleanly to existing pain points in this codebase.

Each phase is independently shippable. They are ordered by risk-adjusted value, not dependency — you can do Phase 1 and skip the rest, or cherry-pick.

## 2. Repo audit findings (already done in planning)

| Feature opportunity | Mapped to | Verdict |
|---|---|---|
| `#[FailOnTimeout]` job attribute | [`TranscribeAudio.php`](../../app/Jobs/TranscribeAudio.php), [`PerformVisualAnalysis.php`](../../app/Jobs/PerformVisualAnalysis.php), [`EnhanceAudio.php`](../../app/Jobs/EnhanceAudio.php), [`GenerateRmsLog.php`](../../app/Jobs/GenerateRmsLog.php), [`AnalyzeSegments.php`](../../app/Jobs/AnalyzeSegments.php) | Strong fit — current timeouts re-queue stuck jobs up to 3× |
| `response()->eventStream()` SSE | [`MediaController::status`](../../app/Http/Controllers/Api/MediaController.php), `media/processing/{id}/status` polling | Strong fit — long-running pipeline + admin UI polling |
| `cache.serializable_classes` allow-list | [`PublicMeetingReadModelCache.php`](../../app/Services/PublicMeetingReadModelCache.php) (caches `PublicMeetingReadModel` objects via `rememberForever`) | **Required** — silently breaks reads otherwise |
| `preventRequestForgery(allowSameSite: true)` | `bootstrap/app.php` (no current customisation) | Light hardening — already half-migrated in tests |
| `Limit::after()` response-based rate limiting | [`AppServiceProvider::configureRateLimiting()`](../../app/Providers/AppServiceProvider.php) | Modest fit — 404 enumeration on `/sermons/*` and `/preachers/*` |
| `WithCachedConfig` trait | `tests/TestCase.php` | Free CI speedup on a 200+ test parallel suite |
| `Cache::touch()` | Public read-side caches (sermons, songs, meetings) | Niche — most caches are `rememberForever` |
| `Schedule::group()` | `routes/console.php` | Minor tidy; defer |

## 3. Goals

- Reduce wasted worker time on stuck media-processing jobs (FailOnTimeout).
- Replace HTTP polling for processing status with SSE in admin UI.
- Lock down cache deserialization to known classes before traffic reaches the new default.
- Add origin-based CSRF defence on top of the existing token check.
- Keep all four CLAUDE.md quality gates green after each phase.

## 4. Non-goals

- Frontend framework changes. We will not introduce React/Vue/Svelte to consume SSE. Livewire + Alpine + native `EventSource` is sufficient.
- Rewriting the existing job retry strategy for jobs that *should* retry (e.g. `TranscribeAudio` on transient OpenAI failures).
- Adopting v13 features with no current pain point (`session()->cache()`, `Str::transliterate`).
- Re-running the upgrade work in the companion plan.

---

## Phase 1 — `#[FailOnTimeout]` on jobs that should never retry on timeout

**Risk:** Very low. The attribute is additive. Existing `$tries` and `backoff()` continue to apply to exceptions; only timeouts change behaviour.

### Why
A timeout in `PerformVisualAnalysis` (1 hour) means FFmpeg got stuck on the video. Retrying twice more burns 2 more hours of worker capacity. The same logic applies to the RMS / segmentation / enhancement jobs. `TranscribeAudio` is the exception: an OpenAI Whisper timeout is genuinely transient and should retry.

### Changes
Add the attribute to jobs whose timeouts are not retry-worthy:

```php
use Illuminate\Queue\Attributes\FailOnTimeout;

#[FailOnTimeout]
class PerformVisualAnalysis implements ShouldQueue { ... }
```

| Job | `$timeout` today | `$tries` today | Action |
|---|---|---|---|
| `PerformVisualAnalysis` | 3600s | 3 | Add `#[FailOnTimeout]` |
| `EnhanceAudio` | (check file) | (check) | Add `#[FailOnTimeout]` if heavy FFmpeg |
| `GenerateRmsLog` | (check) | (check) | Add `#[FailOnTimeout]` (FFmpeg analysis) |
| `AnalyzeSegments` | (check) | (check) | Add `#[FailOnTimeout]` |
| `TranscribeAudio` | 1800s | 3 | **Leave alone** — retry on transient API failures |
| `TranscribeSpeechSegments` | (check) | (check) | Decide per the same rule — external API → leave |

### Tests
- Add a unit test asserting each annotated job's class reflection contains the `FailOnTimeout` attribute. One test class, ~5 `assertContains` per job:
  ```php
  $attrs = (new \ReflectionClass(PerformVisualAnalysis::class))->getAttributes(FailOnTimeout::class);
  $this->assertNotEmpty($attrs);
  ```
- No behavioural test needed; the framework owns the runtime behaviour.

### Rollback
Remove the attribute lines. Zero data impact.

---

## Phase 2 — `cache.serializable_classes` allow-list

**Risk:** Medium if shipped wrong. Cache reads of unallow-listed objects will silently miss. This phase is **required** (not optional) before deploy.

### Why
Laravel 13 defaults `serializable_classes` to `false` to harden against PHP deserialization gadget chains. Any cache entry holding a PHP object will fail to unserialize after upgrade. The repo audit found at least one such site:

- [`PublicMeetingReadModelCache.php:25`](../../app/Services/PublicMeetingReadModelCache.php#L25) — `rememberForever` returning `PublicMeetingReadModel`

### Changes

1. **Inventory all cached object types.** Grep that needs running before code changes:
   ```bash
   vendor/bin/sail bin grep -rn "Cache::\(put\|remember\|rememberForever\|forever\)" app/ | grep -v "\(array\|string\|int\|true\|false\|null\)"
   ```
   For each hit, read the closure return type. If it's an object, add the FQN to the allow-list.

2. **Edit `config/cache.php`:**
   ```php
   'serializable_classes' => [
       \App\Services\PublicMeetingReadModelCache\PublicMeetingReadModel::class,
       // …add others discovered in step 1
   ],
   ```
   (Confirm the namespace — the existing `PublicMeetingReadModelCache.php` may declare the DTO inline or in a sibling file.)

3. **Prefer flattening to arrays** for new code. Objects in the cache are a yellow flag generally.

### Tests
- New `tests/Feature/Cache/SerializableClassesTest.php`:
  - Loops the allow-list, instantiates a stub of each class, puts it in cache, reads it back, asserts equality.
  - Asserts a class *not* on the allow-list (e.g. `stdClass`) cannot be round-tripped.
- Run [`tests/Feature/PublicMeetingReadModelCacheTest.php`](../../tests/Feature) (or equivalent) after the config edit to confirm production caching paths still work.

### Rollout
1. Merge config + allow-list together.
2. Deploy.
3. **Clear cache** as part of deploy: `vendor/bin/sail artisan cache:clear`. This is critical — pre-upgrade serialized payloads may also fail to deserialize against the new class allow-list.
4. Watch error logs for `UnexpectedValueException` from `Cache::get` for 24h.

### Rollback
Revert `config/cache.php`. The pre-v13 behaviour returns. Clear cache again.

---

## Phase 3 — SSE for processing status

**Risk:** Low to medium. New endpoint, no behavioural change to existing endpoint. Frontend swap is the riskier part.

### Why
The admin "media processing" screen polls `/api/sermons/processing/{id}/status` every few seconds for up to an hour while the livestream pipeline runs. Each poll spins up a full Laravel request lifecycle. SSE replaces this with a single long-lived response that yields events as state changes.

### Changes

1. **Add SSE endpoint** in [`routes/api.php`](../../routes/api.php), next to the existing `media/processing/{id}/status`:
   ```php
   Route::get('media/processing/{processingId}/stream', [MediaController::class, 'stream'])
       ->middleware('throttle:processing-stream');
   ```

2. **Implement `MediaController::stream`:**
   ```php
   public function stream(string $processingId, GetMediaProcessingStatus $statusService)
   {
       return response()->eventStream(function () use ($processingId, $statusService) {
           $lastHash = null;
           $deadline = now()->addHour();

           while (now()->lt($deadline)) {
               $snapshot = $statusService->forProcessingId($processingId);
               $hash = md5(json_encode($snapshot));

               if ($hash !== $lastHash) {
                   yield new \Illuminate\Http\StreamedEvent(
                       event: 'progress',
                       data: $snapshot,
                   );
                   $lastHash = $hash;
               }

               if ($snapshot['terminal'] ?? false) {
                   return;
               }

               sleep(2);
           }
       });
   }
   ```
   (Adjust signature to match `GetMediaProcessingStatus` actual API — currently `__invoke` vs `forProcessingId`.)

3. **Add a rate limiter** in `AppServiceProvider::configureRateLimiting()`:
   ```php
   RateLimiter::for('processing-stream', fn (Request $r) =>
       Limit::perMinute(10)->by($r->user()?->id ?: $r->ip())
   );
   ```
   (Connections per minute, not requests — a single SSE connection counts once.)

4. **Frontend swap** in the Livewire processing-status component:
   - Replace `wire:poll` with Alpine `x-data` holding an `EventSource('/api/sermons/processing/' + id + '/stream')`.
   - On `progress` events, call `$wire.set('status', evt.data)` or trigger a Livewire method.
   - On connection close (`terminal: true`), close the EventSource and refresh.
   - Keep `wire:poll` as a fallback if `EventSource` is undefined (very old browsers; probably unnecessary).

5. **Keep the JSON endpoint.** Both endpoints share `GetMediaProcessingStatus`, so the JSON path remains the canonical "give me one snapshot" API and the stream is a presentation-layer optimisation.

### Tests
- `tests/Feature/Api/MediaProcessingStreamTest.php`:
  - Asserts `Content-Type: text/event-stream`.
  - Asserts an initial `progress` event with the snapshot payload.
  - Mocks `GetMediaProcessingStatus` to return a terminal snapshot; asserts the response closes.
  - Asserts rate-limiter throttles after the configured count.
- A Dusk smoke test that confirms the admin processing page renders without errors and the stream connects (don't try to assert event delivery in Dusk — too flaky).

### Rollback
Delete the route, controller method, and revert the Livewire component to `wire:poll`. JSON endpoint never changed.

### Notes
- SSE doesn't play nicely behind some proxies (default Nginx buffers; you'd need `X-Accel-Buffering: no` header). The eventStream helper sets this automatically — verify with `curl -N` against the deployed endpoint.
- Workers per server: each SSE connection holds a PHP-FPM worker for up to an hour. With 1–3 admins watching at most, this is fine. If usage grows, move to swoole/octane or queue-driven push.

---

## Phase 4 — `preventRequestForgery(allowSameSite: true)`

**Risk:** Very low. Token validation continues; this only *adds* an origin check.

### Why
The CSRF middleware was already renamed in the upgrade (`PreventRequestForgery`). Configuring `allowSameSite: true` enables the `Sec-Fetch-Site` check the new middleware was designed around. Defence in depth.

### Changes
Edit `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->preventRequestForgery(allowSameSite: true);
    // …existing middleware config
})
```

`originOnly: true` would be stronger but disables token fallback — skip it unless you've confirmed every admin form posts from the same origin in production. `allowSameSite` keeps tokens as the primary defence and adds origin checking on top.

### Tests
- Existing four `withoutMiddleware(PreventRequestForgery::class)` tests must still pass.
- Add one test that posts an admin form with a missing/invalid `Sec-Fetch-Site` header and a valid token — should still succeed (token fallback works).
- Add one test that posts with a valid token but `Sec-Fetch-Site: cross-site` — should 403/419.

### Rollback
Remove the `preventRequestForgery(...)` line.

---

## Phase 5 — `Limit::after()` for 404 enumeration

**Risk:** Very low. Additive rate limiter.

### Why
Public sermon and preacher routes have predictable URL patterns. A scanner hitting `/sermons/1`, `/sermons/2`, … to enumerate IDs currently isn't rate limited because legitimate browsers also hit lots of these URLs.

### Changes
In `AppServiceProvider::configureRateLimiting()`:
```php
RateLimiter::for('public-not-found', fn (Request $r) =>
    Limit::perMinute(15)
        ->by($r->ip())
        ->after(fn (Response $response) => $response->status() === 404)
);
```

Attach via `->middleware('throttle:public-not-found')` to public sermon/preacher route groups in `routes/web.php`.

### Tests
- `tests/Feature/RateLimiting/PublicNotFoundLimiterTest.php`:
  - 14 requests for non-existent sermons all return 404.
  - 15th returns 429.
  - A 200 response in the middle of the run does *not* count against the limit.

### Rollback
Remove route middleware + limiter definition.

---

## Phase 6 — `WithCachedConfig` in tests

**Risk:** Negligible. Test-suite-only change.

### Why
CI runs `vendor/bin/sail artisan test --parallel --compact`. Each worker boots the app per test. `WithCachedConfig` builds the config once per worker.

### Changes
Edit `tests/TestCase.php`:
```php
use Illuminate\Foundation\Testing\WithCachedConfig;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig;
    // …
}
```

### Tests
- Run the full suite locally and in CI; assert pass + measure wall-clock delta.
- If a test depends on `config()->set(...)` mid-test, the trait won't break it (it caches at worker boot, not request boot), but watch for any test that relies on a config provider modifying config at runtime.

### Rollback
Remove the trait usage.

---

## Phase 7 — `Cache::touch()` and `Schedule::group()` (deferred / opportunistic)

**Risk:** Minimal.

Defer until there's a concrete use case:
- `Cache::touch()` — apply when a public read-side cache needs sliding TTL.
- `Schedule::group()` — refactor next time you edit `routes/console.php`.

No standalone PR for these.

---

## 5. Sequencing recommendation

1. **Phase 2 first.** It's the only mandatory item (silent breakage risk). Ship in its own PR before anything else, with `cache:clear` in deploy.
2. **Phase 1 second.** Three-file PR, immediate worker-time savings, zero rollback cost.
3. **Phase 4 third.** Single config line + two tests.
4. **Phase 3 fourth.** Largest PR; isolate to its own branch. Verify SSE behind production proxy before promoting.
5. **Phase 5 fifth.** Public-facing surface; ship after admin SSE is stable.
6. **Phase 6 last.** DX nicety; bundle with any other test refactor.

Skip Phase 7 unless triggered by adjacent work.

## 6. Quality gates per phase

Per CLAUDE.md, every phase must pass all four checks before merge:

1. `vendor/bin/sail bin pint --dirty`
2. `vendor/bin/sail composer phpstan` (must stay at 0 errors)
3. `vendor/bin/sail artisan test --compact --parallel`
4. `vendor/bin/sail artisan dusk`

## 7. Risks and mitigations

| Risk | Phase | Mitigation |
|---|---|---|
| Cache reads return null after Phase 2 deploy | 2 | `cache:clear` in deploy; monitor logs for 24h; serialize-classes allow-list reviewed by second engineer |
| SSE connection held open by worker, exhausts FPM | 3 | Per-user rate limit; 1-hour hard deadline in stream loop; document need to monitor `pm.max_children` |
| Nginx buffers SSE | 3 | Verify `X-Accel-Buffering: no` header arrives; document in operations/ |
| `FailOnTimeout` on a job that *was* timing out and successfully retrying on attempt 2 | 1 | Audit failed-jobs table before rollout; jobs in scope already have low timeout-retry success rate |
| `Limit::after()` blocks a legitimate visitor with many bookmarked dead links | 5 | Limit is per-minute, not per-day; 15/min is generous; tune after observing prod |
| `allowSameSite` breaks an HTTP-only local environment | 4 | Sec-Fetch-Site only sent over HTTPS; local dev unaffected; document in CLAUDE.md |

## 8. Out of scope

- Replacing the JSON status endpoint with SSE entirely.
- Frontend framework introduction.
- Octane / Swoole adoption to support more SSE connections.
- Migrating away from `Cache::rememberForever` to TTL'd caches.
- v13 features without a current pain point: `session()->cache()`, `Str::transliterate()`, `Eloquent\Pivot` table inference (no custom pivots in repo).

## 9. Memory hooks for follow-up

After completion, save a project memory recording:
- The eventStream endpoint path and its rate-limiter name (so future Livewire components can opt into it).
- The `serializable_classes` allow-list location (so adding new cached objects has a known landing spot).
- The reason `TranscribeAudio` deliberately does *not* use `FailOnTimeout` (so a future contributor doesn't "consistency-fix" it).
