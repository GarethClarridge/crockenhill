# API And Webhook Boundary Review

Date: 2026-03-18

Scope:
- API controllers and routes
- Form requests and middleware
- Sanctum token boundaries
- Rate limits
- Media upload flows
- Webhook signature and duplicate handling
- Public asset exposure
- Existing test coverage

Focused verification:
- Ran `vendor/bin/sail artisan test --compact tests/Feature/Api/MailgunInboundWebhookControllerTest.php tests/Feature/Api/ChurchServiceControllerTest.php tests/Feature/Api/MediaUploadTest.php tests/Feature/Api/RateLimitingTest.php tests/Feature/Security/ChildrensTalkAssetSecurityTest.php tests/Feature/SermonAssetControllerTest.php`
- Result: 66 tests passed, 181 assertions

## Findings

### [P1] Children's Talk media is still publicly retrievable through storage/CDN URLs

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/SermonAssetController.php:26` and `/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/SermonAssetController.php:68` add access control to the controller routes for audio and thumbnails.
- `/Users/garethclarridge/Projects/crockenhill/app/Models/Sermon.php:163` and `/Users/garethclarridge/Projects/crockenhill/app/Models/Sermon.php:174` expose `audio_url` and `thumbnail_url` as direct storage URLs.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/SermonStorageService.php:69` and `/Users/garethclarridge/Projects/crockenhill/app/Services/SermonStorageService.php:89` return raw public disk or CDN URLs instead of guarded routes.
- `/Users/garethclarridge/Projects/crockenhill/resources/views/childrens-corner/show.blade.php:8` and `/Users/garethclarridge/Projects/crockenhill/resources/views/childrens-corner/show.blade.php:100` embed those direct URLs into the Children's Corner page.
- `/Users/garethclarridge/Projects/crockenhill/config/media-processing.php:49` and `/Users/garethclarridge/Projects/crockenhill/config/thumbnail-generation.php:6` default sermon and thumbnail storage to `public`.

Why it matters:
- The route guard only protects `/christ/sermons/{slug}/audio` and `/thumbnail`.
- Once a file path is known or leaked, the actual media can be fetched directly from `/storage/...` or the configured CDN, bypassing `childrens-corner.access` and the asset controller checks entirely.
- This undermines the intended private/public split for Children's Talk content.

Test coverage note:
- Existing tests cover the guarded controller routes in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Security/ChildrensTalkAssetSecurityTest.php:17` and `/Users/garethclarridge/Projects/crockenhill/tests/Feature/SermonAssetControllerTest.php:15`.
- I did not find a test that proves direct storage URLs are blocked when Children's Talks are private.

Recommended direction:
- Serve private Children's Talk assets through signed or guarded routes only, or move those assets onto a private disk and generate temporary URLs intentionally.

### [P1] Mailgun signature enforcement is freshness-only and does not prevent replay

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Middleware/EnsureValidMailgunWebhookSignature.php:20` reads only `timestamp`, `token`, and `signature`.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/MailgunWebhookSignatureValidator.php:17` enforces timestamp tolerance and HMAC validation, but it does not record or reject previously used tokens/signatures.
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MailgunInboundWebhookController.php:20` trusts unsigned fields such as `Message-Id`, `subject`, and message bodies after that check.

Inference from source:
- A captured `(timestamp, token, signature)` tuple remains usable for the full tolerance window.
- Because the verifier does not bind `Message-Id`, `subject`, `from`, or body fields, a replayed request can change those values and still pass verification.
- The duplicate check on `message_id` only stops exact same-message replays.

Why it matters:
- This turns any leaked signed webhook tuple into a short-lived capability for forging a different inbound message record.
- The result is unauthorized service imports or poisoned operator review data rather than a harmless duplicate.

Test coverage note:
- Existing tests cover invalid signatures, stale timestamps, and exact duplicate `Message-Id` submissions in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/MailgunInboundWebhookControllerTest.php:56`, `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/MailgunInboundWebhookControllerTest.php:89`, and `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/MailgunInboundWebhookControllerTest.php:109`.
- I did not find a test for replaying a valid signature with a different `Message-Id` or body.

Recommended direction:
- Cache and reject reused Mailgun tokens/signatures inside the tolerance window, or otherwise add replay protection before trusting the payload.

### [P2] Media upload endpoints are not idempotent, so retries can create duplicate work and duplicate sermons

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/routes/api.php:50` defines upload endpoints with no idempotency key/header support.
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MediaController.php:29` validates and immediately hands each request to the processor.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/UnifiedMediaProcessor.php:227`, `/Users/garethclarridge/Projects/crockenhill/app/Services/UnifiedMediaProcessor.php:344`, and `/Users/garethclarridge/Projects/crockenhill/app/Services/LivestreamSegmentationService.php:41` create a fresh processing record for every accepted upload.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/SermonCreationService.php:113` always inserts a new `Sermon`, and `/Users/garethclarridge/Projects/crockenhill/app/Services/SermonCreationService.php:287` only resolves collisions by incrementing the slug.

Why it matters:
- A network retry, client timeout, or operator double-submit can enqueue the same media twice.
- The current behavior produces duplicate background processing and potentially duplicate sermon records rather than returning the original accepted run.

Test coverage note:
- The only duplicate-submission protection I found is client-side Livewire suppression in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Livewire/MediaUploadTest.php:165`.
- I did not find API-level tests asserting idempotent behavior for repeated upload POSTs.

Recommended direction:
- Add an explicit idempotency key for upload APIs, or derive a server-side duplicate key from file identity plus actor and reject/reuse an in-flight processing run.

### [P2] Duplicate handling exists for webhooks and OpenLP uploads, but it is not race-safe

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MailgunInboundWebhookController.php:20` uses `firstOrCreate()` against `/Users/garethclarridge/Projects/crockenhill/database/migrations/2026_03_08_210000_create_inbound_emails_table.php:16`.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/ImportChurchServiceFromOpenLp.php:45` uses `firstOrNew()` plus `save()` against `/Users/garethclarridge/Projects/crockenhill/database/migrations/2026_02_28_160000_create_church_services_table.php:24`.

Inference from source:
- Sequential duplicates are handled.
- Simultaneous duplicates can still race between read and insert, hit the unique index, and surface as an unhandled `QueryException` or 500 instead of a clean duplicate/update response.

Why it matters:
- Concurrent webhook retries are normal in distributed delivery systems.
- Concurrent OpenLP re-submits are also plausible from impatient operators or automation retries.
- A race here causes noisy failures at the exact boundary where the system is trying to be idempotent.

Test coverage note:
- Sequential duplicate webhook handling is covered in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/MailgunInboundWebhookControllerTest.php:89`.
- Sequential re-upload behavior is covered in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/ChurchServiceControllerTest.php:122`.
- I did not find concurrency or duplicate-key-race tests for either path.

Recommended direction:
- Switch to a catch-and-reload or `upsert`-style flow so duplicate-key races collapse into the intended duplicate/update result.

### [P2] OpenLP archive parsing is vulnerable to decompression-bomb style uploads

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/app/Http/Requests/UploadChurchServiceRequest.php:32` limits only the uploaded archive size.
- `/Users/garethclarridge/Projects/crockenhill/app/Services/OpenLpServiceParser.php:23` opens the archive and `/Users/garethclarridge/Projects/crockenhill/app/Services/OpenLpServiceParser.php:37` reads the selected `.osj` entry into memory with `getFromIndex()`.
- There are no checks for entry count, decompressed size, compression ratio, or total archive expansion before JSON decode.

Why it matters:
- A small `.osz` can still expand into a very large `.osj` payload after it passes request validation.
- That turns the upload endpoint into a memory and CPU exhaustion point.

Test coverage note:
- I found wrong-file-type and normal parsing tests in `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/ChurchServiceControllerTest.php:360`.
- I did not find a test for oversized decompressed entries or suspicious zip structure.

Recommended direction:
- Inspect zip metadata before extraction and reject archives whose `.osj` entry count, uncompressed size, or compression ratio exceeds a safe threshold.

### [P3] Invalid webhook probes bypass the named Mailgun rate limiter

Evidence:
- `/Users/garethclarridge/Projects/crockenhill/routes/api.php:43` applies `mailgun.signature` before `throttle:mailgun-inbound`.
- `/Users/garethclarridge/Projects/crockenhill/tests/Feature/Api/RateLimitingTest.php:125` only proves throttling for a validly signed webhook request.

Why it matters:
- Unsigned or malformed floods never enter the named Mailgun rate limiter path.
- This is mostly an availability and log-noise hardening gap rather than a direct auth break, because signature validation itself is cheap.

Recommended direction:
- Consider an outer IP-based throttle before signature verification, then keep the recipient-aware throttle for valid deliveries.

## Coverage Gaps Worth Fixing

- Add a Mailgun replay test that reuses a valid `(timestamp, token, signature)` with a different `Message-Id`.
- Add a direct-URL asset test proving private Children's Talk media cannot be fetched from `/storage/...` or CDN-origin paths.
- Add API-level duplicate-upload tests so backend idempotency is exercised independently of the Livewire UI.
- Add duplicate-key race tests for Mailgun and OpenLP import paths.
- Add decompression-bomb tests for `.osz` uploads.

