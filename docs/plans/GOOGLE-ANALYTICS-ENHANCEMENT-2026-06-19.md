# Google Analytics enhancement — closeout plan

> **Status (2026-08-12): GA1–GA4 shipped; GA6 operator configuration remains; GA5 is optional and
> undecided.** Do not reopen the completed consent, SPA pageview, browser-event, or content-
> dimension implementation. The live code/tests and
> [`docs/operations/SEO_SETUP_GUIDE.md`](../operations/SEO_SETUP_GUIDE.md) are their authorities.

## Delivered

The application already provides:

- Consent Mode v2 and an opt-in cookie banner;
- pageview handling across `wire:navigate` navigation;
- browser events for sermon play/download, transcript download, podcast subscribe, and share where
  the corresponding control exists;
- preacher, series, service, content type, and content-group context;
- production-only/no-ID no-op behaviour and focused rendering/browser tests.

Do not maintain a second event catalogue in this plan. Update the operations guide only when the
implemented event contract or verified GA4 console setup changes.

## Delivery 1 — GA6 console configuration

**Ready now; no repository code or deployment dependency.** A maintainer with GA4 property access:

1. registers these event-scoped custom dimensions:
   - `preacher`;
   - `series`;
   - `service`;
   - `content_type`;
2. confirms with stakeholders which events are genuine key events, with `sermon_play` and
   `podcast_subscribe` as the current candidates, then marks only the approved set;
3. confirms Enhanced Measurement scroll/outbound-click settings do not duplicate explicit events;
4. uses DebugView on one sermon and one children's talk to verify pageview/play dimensions and
   content grouping;
5. records the completion date and any console naming differences in the operations guide.

After [Site Search](SITE-SEARCH-2026-07-20.md) ships, add one follow-up console check: Enhanced
Measurement must recognise `q` as the site-search parameter without adding application analytics
code. This check does not block search release.

Delivery 1 is complete when reportable dimensions and the agreed key events are visible in GA4,
not merely when the application continues sending parameters.

## Delivery 2 — decide GA5 server-side listen measurement

**Optional; decide after GA6.** Browser analytics cannot observe podcast clients and may miss direct
asset requests. Before writing code, answer whether that additional count is useful enough to
justify a new server-to-server data path and its privacy/accuracy limitations.

### Approve only if

- the church has a named reporting question that browser `sermon_play` cannot answer;
- GA4 is the accepted destination for server-side listens;
- stakeholders accept that a first asset request is a delivery signal, not proof that a person
  listened meaningfully;
- a privacy review approves the client/session identifier strategy; and
- the asset delivery route is confirmed to observe every intended podcast/direct-download entry
  before redirecting to storage/CDN.

If any point fails, reject GA5, record the decision here, and archive this plan after GA6. Do not
build it speculatively.

### If approved

Ship GA5 as one isolated, reversible PR:

- implement a small HTTP-client service against the **current** official GA4 Measurement Protocol
  contract, with explicit connect/request timeouts, retry policy, and `Http::fake()` coverage;
- keep the API secret in `config/services.php`/production secrets and no-op when absent;
- queue delivery after the authorised asset request so media response latency is unaffected;
- emit only for the first request representing one delivery and prove ordinary range requests do
  not multiply events;
- carry only the already-approved content dimensions and the minimum privacy-safe identifiers;
- make job retries idempotent or supply an event identity that prevents duplicate reporting;
- never make analytics failure affect asset delivery;
- add structured outcome logging without URLs, secrets, user identity, or query strings.

Verify the current GA endpoint, payload requirements, validation endpoint, quota, and regional
privacy behaviour at implementation time; the removed 2026 draft is not an API authority.

### GA5 tests and acceptance

- missing secret/config is a true no-op;
- initial eligible request dispatches once;
- non-initial range requests and denied/missing assets do not dispatch;
- payload contains only approved dimensions and no secret in logs;
- timeout/retry/exhaustion never changes the media response;
- DebugView or the current validation tool accepts a non-production canary before production enablement.

Run focused tests, PHPStan, Pint, the full parallel suite, and relevant asset-delivery Dusk coverage
if browser behaviour changes.

## Recommended order

1. Complete GA6 now.
2. Verify `q` in Enhanced Measurement after Site Search release.
3. Accept or reject GA5 using the decision gate above.
4. Archive this plan when GA6 and the GA5 disposition are recorded.

**Who benefits:** the maintainer and church leaders using content analytics.

**What observably improves:** already-collected sermon dimensions become reportable first; any later
server-side listen count exists only if it answers a named question with understood accuracy and
privacy limits.
