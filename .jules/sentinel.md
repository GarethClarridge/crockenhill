## 2025-05-15 - [Mass Assignment of Admin Status]
**Vulnerability:** The `is_admin` attribute was included in the `User` model's `$fillable` array.
**Learning:** This allowed anyone with access to a user creation or update flow (if not strictly guarded) to potentially escalate their privileges by including `is_admin: true` in the request payload. In this codebase, even administrative Livewire components were relying on this mass-assignment, making the attribute "intentionally" vulnerable for convenience.
**Prevention:** Always exclude high-privilege attributes from `$fillable`. Use explicit property assignment ($user->is_admin = true) in controlled administrative contexts to update such fields.
## 2026-02-21 - [Insufficient Authorization on Admin/API Endpoints]
**Vulnerability:** Administrative API endpoints for media processing and web-based calendar management were only protected by authentication (`auth` or `auth:sanctum`), allowing any registered user to perform privileged actions.
**Learning:** Route-level authorization (`admin` middleware) should always be applied to all administrative surfaces, even if individual controllers or requests perform their own checks, to ensure defense in depth and consistent protection.
**Prevention:** Audit all routes prefixed with `admin` or serving administrative functions to ensure the `admin` middleware is consistently applied at the route group level.
