## 2025-05-15 - [Mass Assignment of Admin Status]
**Vulnerability:** The `is_admin` attribute was included in the `User` model's `$fillable` array.
**Learning:** This allowed anyone with access to a user creation or update flow (if not strictly guarded) to potentially escalate their privileges by including `is_admin: true` in the request payload. In this codebase, even administrative Livewire components were relying on this mass-assignment, making the attribute "intentionally" vulnerable for convenience.
**Prevention:** Always exclude high-privilege attributes from `$fillable`. Use explicit property assignment ($user->is_admin = true) in controlled administrative contexts to update such fields.
