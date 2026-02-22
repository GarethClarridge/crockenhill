## 2025-05-15 - [Mass Assignment of Admin Status]
**Vulnerability:** The `is_admin` attribute was included in the `User` model's `$fillable` array.
**Learning:** This allowed anyone with access to a user creation or update flow (if not strictly guarded) to potentially escalate their privileges by including `is_admin: true` in the request payload. In this codebase, even administrative Livewire components were relying on this mass-assignment, making the attribute "intentionally" vulnerable for convenience.
**Prevention:** Always exclude high-privilege attributes from `$fillable`. Use explicit property assignment ($user->is_admin = true) in controlled administrative contexts to update such fields.

## 2026-02-22 - [Authentication Entry Point Throttling]
**Vulnerability:** Registration, forgot password, and reset password flows were missing rate limiting, making them vulnerable to automated registration spam and brute-force password reset attempts.
**Learning:** While the login flow was protected, other authentication-related entry points were overlooked. Livewire components require manual implementation of the `RateLimiter` logic as they don't automatically inherit route-level throttling in some configurations.
**Prevention:** Ensure all public-facing authentication and sensitive data entry points implement throttling. Use a combination of user identifier (e.g., email) and IP address for the throttle key to prevent targeted and distributed attacks.
