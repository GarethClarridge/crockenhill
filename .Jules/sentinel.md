## 2025-05-15 - [Mass Assignment of Admin Status]
**Vulnerability:** The `is_admin` attribute was included in the `User` model's `$fillable` array.
**Learning:** This allowed anyone with access to a user creation or update flow (if not strictly guarded) to potentially escalate their privileges by including `is_admin: true` in the request payload. In this codebase, even administrative Livewire components were relying on this mass-assignment, making the attribute "intentionally" vulnerable for convenience.
**Prevention:** Always exclude high-privilege attributes from `$fillable`. Use explicit property assignment ($user->is_admin = true) in controlled administrative contexts to update such fields.

## 2026-02-22 - [Authentication Entry Point Throttling]
**Vulnerability:** Registration, forgot password, and reset password flows were missing rate limiting, making them vulnerable to automated registration spam and brute-force password reset attempts.
**Learning:** While the login flow was protected, other authentication-related entry points were overlooked. Livewire components require manual implementation of the `RateLimiter` logic as they don't automatically inherit route-level throttling in some configurations.
**Prevention:** Ensure all public-facing authentication and sensitive data entry points implement throttling. Use a combination of user identifier (e.g., email) and IP address for the throttle key to prevent targeted and distributed attacks.

## 2026-02-23 - [Information Leakage in Error Responses]
**Vulnerability:** Exception messages were being returned directly to users in redirect messages, potentially leaking internal details like file paths or database structure.
**Learning:** Catching exceptions and displaying `$e->getMessage()` is a common developer habit for debugging but dangerous in production.
**Prevention:** Always log the full exception for developers and return a generic, friendly message to the user.

## 2026-02-23 - [Under-protected Administrative Routes]
**Vulnerability:** Several routes for editing sermons and managing meetings relied solely on `auth` middleware or controller-level authorization, missing route-level `admin` middleware for defense-in-depth.
**Learning:** Relying only on policy checks in controllers is technically correct but less robust than combining it with route-level middleware.
**Prevention:** Apply administrative middleware (`admin`) to all routes that perform administrative actions, even if they are already guarded by policies.

## 2026-02-28 - [XSS in JSON-LD Structured Data]
**Vulnerability:** User-controlled strings (like sermon titles or page headings) were being rendered inside `<script type="application/ld+json">` tags using the raw `{!! json_encode($data) !!}` directive without sufficient escaping flags. An attacker could inject `</script><script>alert(1)</script>` to break out of the JSON block and execute arbitrary JavaScript.
**Learning:** Default `json_encode` does not escape `<` and `>` characters. In a Blade template, using `{!! !!}` bypasses Laravel's automatic HTML escaping, creating an XSS vector if the JSON is placed inside a script tag.
**Prevention:** Always use `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` flags when encoding JSON for use within a `<script>` tag. `JSON_HEX_TAG` specifically converts `<` and `>` to `\u003C` and `\u003E`, preventing script termination.

## 2026-03-14 - [Information Leakage in API Responses]
**Vulnerability:** Core media processing services were catching raw exceptions and returning `$e->getMessage()` directly in API responses and database logs. This leaked internal server paths, SQL errors, and configuration details to end users.
**Learning:** General exception messages are often too technical and descriptive for public exposure. Failing to explicitly distinguish between "user-safe" and "internal" errors leads to accidental information disclosure.
**Prevention:** Use the `ProvidesSafeMessage` interface to mark exceptions that are safe for public display. Sanitize all error reporting in public-facing services by replacing non-safe exceptions with generic messages. Always ensure technical details are still captured in system logs (`Log::error`) for developer visibility.

## 2026-05-15 - [Authorized Asset Serving Policy]
**Vulnerability:** `SermonAssetController` was only enforcing access policies for Children's Talk content, leaving regular sermon assets (audio, video, thumbnails) accessible via direct URLs even if they were stored in `private/` or marked as unexposed by the `SermonExposurePolicy` (e.g., due to poor quality).
**Learning:** Relying on frontend visibility logic (hiding links) is insufficient if the serving endpoint itself does not re-verify the exposure policy. Public-facing asset controllers must act as the final gatekeeper for all media assets.
**Prevention:** Centralize authorization logic for asset serving that combines content-type policies, automated quality assessment results, and manual visibility overrides. Explicitly restrict "private/" storage paths to administrators to prevent accidental leakage of raw or unedited media.

## 2026-07-08 - [DoS Protection for Rate Limiter Keys]
**Vulnerability:** The 'recipient' field in inbound email webhooks was used as a rate-limiting key without length validation or metadata exclusion.
**Learning:** Using unvalidated input as a cache key for rate limiting can lead to resource exhaustion if attackers send excessively large strings. Technical routing keys should also be kept out of stored message metadata to maintain clean data separation.
**Prevention:** Always apply strict length (max:255) and format (e.g. email) validation to any input used as a rate-limiting key. Exclude such fields from application metadata storage.
