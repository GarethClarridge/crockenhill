# Google Analytics Enhancement (2026-06-19)

> **Status (2026-07-05): nearly complete.** GA1–GA4 shipped (see the 2026-06-29 reconciliation
> below). Remaining: **GA6** — a manual GA4-admin task for the maintainer (register the four
> event-scoped custom dimensions, mark key events as conversions), without which the GA4 data is
> collected but not reportable — and **GA5** (server-side Measurement Protocol), which is optional
> and not started; treat GA5 as a maintainer decision, not queued work. No agent code work remains
> unless GA5 is green-lit.

Created 2026-06-19 from an assessment of the current GA4 integration. Turns the
"better use of Google Analytics" backlog into ordered, independently-shippable
phases (`GA1…GA6`).

## Recommendation

The site has a clean but **minimal** GA4 install: one deferred `gtag('config', …)`
call and nothing else. That yields automatic pageviews and zero insight into the
content that defines a church website — sermon listens, downloads, podcast pickup,
and which preacher/series drives engagement. Two of those pageviews are also
**counted wrongly** because of the SPA navigation model (see Background).

This plan does six things, in priority order:

1. **GA1 — Consent.** Add a cookie-consent banner + Google Consent Mode v2 so
   analytics cookies only fire after opt-in. *(Compliance, not just an
   enhancement — see Privacy & Compliance.)*
2. **GA2 — Pageview correctness.** Fire `page_view` on `livewire:navigated` and
   extract the inline snippet into a testable `resources/js/analytics.js` module.
   **Technical prerequisite for GA3–GA4.**
3. **GA3 — Event tracking.** `sermon_play`, `sermon_download`,
   `transcript_download`, `podcast_subscribe`, `share`.
4. **GA4 — Content grouping & custom dimensions.** Attach `preacher`, `series`,
   `service`, `content_type` to events and set `content_group`.
5. **GA5 — Server-side Measurement Protocol** for podcast / direct-download
   listens that never touch browser JS. *(Higher effort, optional.)*
6. **GA6 — GA4 property configuration & docs.** Register custom dimensions, mark
   key events as conversions, update the SEO guide. *(No app code; without it the
   data from GA3–GA5 is collected but not reportable.)*

GA2–GA4 are the value sweet-spot: modest effort, and they convert GA from a
hit-counter into something that answers real questions about the preaching
archive. GA1 is the highest *priority* (legal), and is technically independent of
the rest, so it can land in parallel.

## Status reconciliation (2026-06-29)

The plan's per-phase "☐ Not started" markers were stale. Verified against the
working tree:

- **GA1–GA4 are implemented and shipped.** The whole integration now lives in
  `resources/js/analytics.js` (Consent Mode v2 + `page_view` on
  `livewire:navigated` + delegated events + content dimensions), with the
  `<x-cookie-consent>` banner (`resources/views/components/cookie-consent.blade.php`),
  the `share` hook in `resources/views/components/clipboard-button.blade.php`,
  and the consent docs in `docs/operations/SEO_SETUP_GUIDE.md`. The Background
  section below describes the *pre-implementation* state and is kept for rationale.
- **GA5 (server-side Measurement Protocol) is not started** — optional, ship last.
- **GA6 is partially done**: the SEO guide documents GA4 setup + consent, but the
  one-off GA4-admin work (register event-scoped custom dimensions, mark key events
  as conversions) is still outstanding and is a manual console task, not app code.

Per-phase status lines below have been updated to match.

## Background — current state (measured 2026-06-19)

- **The whole integration** is one block in
  [resources/views/layouts/main.blade.php:66-87](../../resources/views/layouts/main.blade.php#L66-L87).
  It reads `config('services.google_analytics.measurement_id')`
  ([config/services.php:49-51](../../config/services.php#L49-L51), from
  `GOOGLE_ANALYTICS_ID`), defers the `gtag.js` load until after `window load`
  + 100 ms (a deliberate, good LCP optimisation — **keep it**), then fires a bare
  `gtag('config', …)`.
- **CSP already allows the Google hosts** —
  [app/Http/Middleware/SecurityHeaders.php:68-71](../../app/Http/Middleware/SecurityHeaders.php#L68-L71)
  permits `googletagmanager.com` (`script-src`, `img-src`) and
  `*.google-analytics.com` (`connect-src`). No CSP change is needed for any
  *client-side* phase here. (GA5 is server-side and not subject to CSP.)
- **Existing tests:**
  [tests/Feature/SeoMetaTagsTest.php:123-143](../../tests/Feature/SeoMetaTagsTest.php#L123-L143)
  assert the tag appears when configured and is absent otherwise. These must keep
  passing through every phase.
- **Production-only:** `GOOGLE_ANALYTICS_ID` is commented out in `.env` /
  `.env.example`, so GA is inert locally. All client phases must stay no-ops when
  the ID is unset.

### The two correctness problems a bare `config` call leaves behind

1. **Pageviews are undercounted.** The site navigates via `wire:navigate`
   (61 uses across 32 files, 16 in the nav alone), so internally it behaves like
   an SPA — Livewire swaps the `<body>` with no full document load. The GA snippet
   fires on `window load`, which happens **once**. A visitor who browses five
   sermons registers as **one** pageview. The codebase already solves this exact
   problem for a different script:
   [resources/js/scripture-fums.js:8-15,48](../../resources/js/scripture-fums.js#L8-L15)
   listens to `livewire:navigated` precisely because it "covers both the initial
   page load and all subsequent wire:navigate transitions" and documents how to
   avoid double-counting the first render. GA must follow that precedent.

2. **The content events are invisible.** GA4 Enhanced Measurement only auto-tracks
   *embedded YouTube*, never native `<audio>`/`<video>`. The sermon players are
   native elements
   ([resources/views/sermons/sermon.blade.php:86,92](../../resources/views/sermons/sermon.blade.php#L86-L98)),
   so **sermon listens — the single most important metric — are untracked**.
   Likewise, auto `file_download` matches on URL *extension*; audio/transcript are
   served by extension-less controller routes
   (`/{sermon}/audio`, `/{sermon}/transcript` →
   [app/Http/Controllers/SermonAssetController.php:35,59](../../app/Http/Controllers/SermonAssetController.php#L35-L93)),
   so downloads never fire either.

## Quality Gates (run for every phase that touches the relevant area)

1. `vendor/bin/sail bin pint --dirty --format agent`
2. `vendor/bin/sail composer phpstan` — must stay at 0 errors.
3. `vendor/bin/sail artisan test --compact --parallel <focused paths>`, then a full
   `--parallel` run before merge.
4. `vendor/bin/sail artisan dusk` — for GA1 (consent banner) and any phase touching
   public sermon routes or the layout. Run `vendor/bin/sail npm run build` before
   Dusk for phases that change `resources/js`.

## Privacy & Compliance (drives GA1)

Under UK PECR / ICO guidance, analytics cookies are **not** "strictly necessary",
so they require **opt-in consent**. The site currently sets GA cookies with no
banner and no Consent Mode — a real compliance gap for a UK church, independent of
the data-quality work. GA1 is therefore the highest-priority phase even though it
adds the least analytical value. The recommended approach is **bespoke** (Alpine
banner + Google Consent Mode v2, no new dependency); `spatie/laravel-cookie-consent`
is an alternative but predates Consent Mode v2 and would need dependency approval
per `AGENTS.md`.

## Decisions to confirm before starting

1. **Consent UX (GA1):** accept/reject banner wording, and whether a "reject"
   choice is remembered for the same duration as "accept". *Recommended:* simple
   two-button banner ("Accept" / "Decline"), choice stored 12 months.
2. **Conversions (GA6):** which events count as "key events" in GA4. *Recommended:*
   `sermon_play` and `podcast_subscribe`.
3. **Server-side tracking (GA5):** do we want podcast/direct-download listens
   measured at all? It needs a GA4 Measurement Protocol API secret and careful
   Range-request handling. *Recommended:* yes, but ship it last and behind a flag.

---

## Phase GA1 — Consent banner + Google Consent Mode v2

**Priority: Highest (compliance) · Risk: Low · Effort: M · Status: ✅ Done (2026-06-29 audit)** — `<x-cookie-consent>` + Consent Mode v2 default-denied in `resources/js/analytics.js`.
Technically independent of GA2–GA6; can land first or in parallel.

### Rationale
Analytics cookies need opt-in consent (see Privacy & Compliance). Consent Mode v2
is the Google-native mechanism: GA loads but is told storage is `denied` until the
user opts in, at which point we update to `granted`.

### Approach
- Set `gtag('consent', 'default', { analytics_storage: 'denied' })` **before** the
  `gtag('config', …)` call (ordering matters — default consent must be registered
  before config runs). This slots into the existing deferred loader.
- A small Alpine banner component persists the choice (localStorage + a first-party
  cookie for server awareness if needed) and, on "Accept", calls
  `gtag('consent', 'update', { analytics_storage: 'granted' })`.
- Banner is suppressed once a choice exists. No banner renders when
  `GOOGLE_ANALYTICS_ID` is unset (nothing to consent to).

### Target files
- `resources/views/components/cookie-consent.blade.php` — **new** Alpine banner.
- `resources/js/analytics.js` — **new** (shared with GA2); houses the
  `grantConsent()` / `denyConsent()` helpers and the default-consent bootstrap.
- [resources/views/layouts/main.blade.php](../../resources/views/layouts/main.blade.php)
  — set `consent` default before `config`; render `<x-cookie-consent />`.

### Tasks
- [ ] Build `<x-cookie-consent>` (brand tokens via the `frontend-design` skill;
      dismissible, keyboard-accessible, `x-cloak` to avoid flash).
- [ ] Emit `consent default … denied` ahead of `config` in the deferred loader.
- [ ] On Accept → `consent update … granted` + persist; on Decline → persist denial.
- [ ] Guard everything behind `@if(config('services.google_analytics.measurement_id'))`.

### Verification
- [ ] Dusk: banner shows on first visit; Accept hides it and persists across reload;
      Decline persists and leaves `analytics_storage` denied.
- [ ] Feature test: banner markup present when ID set, absent when unset.

---

## Phase GA2 — Pageview correctness + analytics module extraction

**Priority: High · Risk: Low · Effort: S · Status: ✅ Done (2026-06-29 audit)** — snippet extracted to `resources/js/analytics.js`; `page_view` fires on `livewire:navigated` with `send_page_view:false`.
**Prerequisite for GA3 and GA4.**

### Root cause
The snippet fires once on `window load`; `wire:navigate` transitions never
re-trigger it (see Background → correctness problem 1).

### Approach
- Add `send_page_view: false` to the `gtag('config', …)` options so the initial
  automatic pageview doesn't fire.
- Move the GA bootstrap + a `sendPageView()` into `resources/js/analytics.js`, and
  fire `page_view` on `livewire:navigated` — mirroring `scripture-fums.js`, which
  fires on initial render too, giving exactly one pageview per navigation.
- Pass `page_path: location.pathname + location.search` and
  `page_title: document.title` explicitly (the title changes on wire:navigate).
- Keep the deferred-load timing and the `config('…measurement_id')` guard.

### Target files
- `resources/js/analytics.js` — **new** (shared with GA1).
- [resources/js/app.js](../../resources/js/app.js) — `import './analytics';`.
- [resources/views/layouts/main.blade.php:66-87](../../resources/views/layouts/main.blade.php#L66-L87)
  — pass the measurement ID to JS (e.g. `window.__gaId`), thin the inline script to
  just the deferred bootstrap, add `send_page_view: false`.

### Tasks
- [ ] Extract bootstrap to `analytics.js`; expose `sendPageView()`.
- [ ] `document.addEventListener('livewire:navigated', sendPageView)`.
- [ ] No-op cleanly when `window.__gaId` is falsy.

### Verification
- [ ] Existing `SeoMetaTagsTest` still passes (tag present/absent).
- [ ] Manual/Dusk: `dataLayer` receives one `page_view` per `wire:navigate` hop in
      GA4 DebugView / realtime.

---

## Phase GA3 — Client-side event tracking

**Priority: High · Risk: Low · Effort: M · Status: ✅ Done (2026-06-29 audit)** — `sermon_play`, `sermon_download`, `transcript_download`, `podcast_subscribe`, and `share` (via `clipboard-button.blade.php`) all wired through the delegated listener.

### Rationale
Native players and extension-less routes mean the core engagement is invisible
(Background → correctness problem 2). Add explicit events.

### Events & hooks
| Event | Trigger | Hook |
|-------|---------|------|
| `sermon_play` | `play` on the sermon `<audio>`/`<video>` | delegated listener on `data-analytics="sermon-media"` |
| `sermon_download` | click on a "Download audio" link | `data-analytics="sermon-download"` |
| `transcript_download` | transcript fetch/open | `data-analytics="transcript"` |
| `podcast_subscribe` | click on a feed/subscribe link | `data-analytics="podcast-subscribe"` |
| `share` | clipboard copy succeeds | hook in the copy `.then()` |

### Approach
- Add `data-analytics="…"` (+ data-* params, see GA4) to the existing markup; a
  single delegated listener in `analytics.js` reads them and calls `gtag('event', …)`.
  Delegation means it survives `wire:navigate` DOM swaps without re-binding.
- For `<audio>`/`<video>`, listen for the `play` event once per element (guard a
  `dataset.played` flag so seeking doesn't re-fire).
- For `share`, add an optional `analytics` prop to
  [resources/views/components/clipboard-button.blade.php:50](../../resources/views/components/clipboard-button.blade.php#L50)
  and call `gtag('event','share', …)` inside the existing `writeText().then()`.

### Target files
- [resources/views/sermons/sermon.blade.php:86-98](../../resources/views/sermons/sermon.blade.php#L86-L98)
  — `data-analytics` on the players; a Download link if one isn't already present.
- [resources/views/components/clipboard-button.blade.php](../../resources/views/components/clipboard-button.blade.php)
  — optional `share` event in the copy handler.
- [resources/views/components/podcast-discovery.blade.php](../../resources/views/components/podcast-discovery.blade.php)
  and any visible subscribe links — `data-analytics="podcast-subscribe"`.
- `resources/js/analytics.js` — the delegated event dispatcher.

### Tasks
- [ ] Delegated `click`/`play` listener reading `data-analytics` + params.
- [ ] Annotate players, download/transcript links, subscribe links, share button.
- [ ] Respect consent (no events before `granted` — Consent Mode handles transport,
      but skip queuing where sensible).

### Verification
- [ ] Feature/HTTP test: sermon page renders the `data-analytics` attributes
      (asserts the *wiring*, which is PHP-testable even though the JS isn't).
- [ ] Dusk smoke: clicking the share button pushes a `share` event to `dataLayer`.
- [ ] Manual: events appear in GA4 DebugView.

---

## Phase GA4 — Content grouping & custom dimensions

**Priority: Medium · Risk: Low · Effort: S–M · Status: ✅ Done in app code (2026-06-29 audit)** — `preacher`/`series`/`service`/`content_type`/`content_group` flow from `<x-analytics-context>` into `page_view` and `sermon_play`. *Note:* the dimensions only become reportable once registered in the GA4 admin — that registration is GA6 and is still pending.

### Rationale
Turns "page X got 200 views" into "Pastor Y is the most-listened preacher" and
"the Romans series drives the most plays" — without manual URL parsing. The data is
already on the page: `$sermonView['preacher_name']`, `$sermon->series`,
`$sermon->service`, `$sermon->content_type`
([sermon.blade.php:4-7,42-44](../../resources/views/sermons/sermon.blade.php#L4-L44),
via `App\Presenters\SermonViewPresenter`).

### Approach
- Set `content_group` on the sermon `page_view` (e.g. `Sermons`, `Children's Corner`).
- Emit `preacher`, `series`, `service`, `content_type` as **event params** on
  `page_view` and `sermon_play`, sourced from `data-*` attributes on a page-level
  element so JS needs no Blade-embedded scripts.
- These params become reportable only after GA6 registers them as event-scoped
  custom dimensions in the GA4 admin — call that dependency out in GA6.

### Target files
- `resources/views/sermons/sermon.blade.php` — `data-ga-*` attributes on a wrapper.
- `resources/js/analytics.js` — read the wrapper's dataset into event params.
- (Optional) a small `<x-analytics-context>` partial so other content types
  (children's corner, songs) can supply the same dimensions consistently.

### Tasks
- [ ] Emit `data-ga-preacher/series/service/content-type` on sermon pages.
- [ ] Merge dataset into `page_view` + `sermon_play` params.
- [ ] Set `content_group` per content type.

### Verification
- [ ] HTTP test: attributes present with correct values for a sermon vs a
      children's talk (factory states).
- [ ] Manual: dimensions populate in GA4 DebugView after GA6 registration.

---

## Phase GA5 — Server-side Measurement Protocol (podcast & direct downloads)

**Priority: Low · Risk: Medium · Effort: L · Status: ☐ Not started (confirmed 2026-06-29) · Optional, ship last.**

### Rationale
Podcast apps fetch the RSS enclosure server-side and direct media hits may bypass
the page entirely — invisible to any browser JS. The only way to count them is to
emit GA4 Measurement Protocol events from the controller that serves the bytes.

### Approach
- A `GoogleAnalyticsMeasurementProtocol` service POSTs to
  `https://www.google-analytics.com/mp/collect` via Laravel's HTTP client (a
  server-to-server call — **not** subject to CSP).
- Fire from
  [SermonAssetController::serveAudio()/serveVideo()](../../app/Http/Controllers/SermonAssetController.php#L59-L130)
  with a `sermon_listen` event carrying the same dimensions as GA4.
- **Range-request guard (critical):** browsers issue many partial `Range` requests
  per media file; only emit on the first request (no `Range` header, or
  `Range: bytes=0-…`) to avoid one "listen" becoming dozens. Dispatch on a queued
  job so serving latency is unaffected.
- New env: `GOOGLE_ANALYTICS_MP_API_SECRET` (+ config under
  `services.google_analytics.mp_api_secret`). No-op when unset.

### Target files
- `app/Services/Analytics/GoogleAnalyticsMeasurementProtocol.php` — **new**.
- `app/Jobs/RecordSermonListen.php` — **new** (queued).
- [config/services.php:49-51](../../config/services.php#L49-L51) — add `mp_api_secret`.
- [app/Http/Controllers/SermonAssetController.php](../../app/Http/Controllers/SermonAssetController.php)
  — dispatch on first (non-Range) hit.

### Tasks
- [ ] MP client with `Http::fake`-able transport; no-op without secret.
- [ ] Range-aware dispatch + queued job.
- [ ] Reuse the same `client_id` strategy where a cookie exists; generate otherwise.

### Verification
- [ ] Unit test (`Http::fake`): correct payload shape; **no** call without the secret.
- [ ] Unit test: a `Range: bytes=200-` request does **not** dispatch; a fresh GET does.

---

## Phase GA6 — GA4 property configuration & documentation

**Priority: Medium (do alongside GA3–GA5) · Risk: None (no app code) · Status: ◐ Partial (2026-06-29 audit)** — `docs/operations/SEO_SETUP_GUIDE.md` documents GA4 setup + consent; the manual GA4-admin work (register event-scoped custom dimensions for `preacher`/`series`/`service`/`content_type`, mark key events as conversions) is still outstanding.

### Rationale
Custom event params are collected but **not reportable** until registered as
custom dimensions in the GA4 admin, and key events aren't conversions until marked.
This is dashboard work, but the data from GA3–GA5 is wasted without it.

### Tasks
- [ ] Register event-scoped custom dimensions: `preacher`, `series`, `service`,
      `content_type`.
- [ ] Mark key events as conversions (confirm list — see Decisions: likely
      `sermon_play`, `podcast_subscribe`).
- [ ] Confirm Enhanced Measurement settings (scroll, outbound clicks) are on and not
      duplicating our explicit events.
- [ ] Update
      [docs/operations/SEO_SETUP_GUIDE.md:70-126](../../docs/operations/SEO_SETUP_GUIDE.md#L70)
      with the event/dimension catalogue, the consent behaviour, and the
      `GOOGLE_ANALYTICS_MP_API_SECRET` step (if GA5 ships).

---

## Testing strategy

- **PHP/HTTP (primary):** the *wiring* is PHP-testable even though the JS isn't —
  assert `data-analytics`/`data-ga-*` attributes render with correct values, that
  the consent banner and GA tag appear only when the ID is set, and (GA5) test the
  MP service with `Http::fake`. Extend `SeoMetaTagsTest` rather than duplicating it.
- **Dusk (targeted):** consent Accept/Decline flow (GA1) and a `dataLayer` push on
  the share button (GA3). Keep it to the flows that genuinely need a browser.
- **Manual:** GA4 **DebugView** is the source of truth for confirming events and
  dimensions land — list the expected events per page in the PR description.

## Sequencing

```
GA1 (consent)  ─────────────┐  (independent; land first or parallel)
GA2 (pageview fix + module) ─┴─→ GA3 (events) ─→ GA4 (dimensions) ─→ GA6 (GA admin/docs)
GA5 (Measurement Protocol)  ───────────────────────────────────────→ (optional, last)
```

One phase per PR, each independently revertable. GA6 is partly continuous (register
each dimension as the phase that emits it merges).
