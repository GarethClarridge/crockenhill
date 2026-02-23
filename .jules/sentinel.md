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
