# 🪦 Mortician: Possibly dead — Mailable `App\Mail\PermissionError`

## 1. Dead Mailable `App\Mail\PermissionError`

**Artefact:**
`app/Mail/PermissionError.php`

**Evidence:**
Project-wide search for `PermissionError` (covering `app/`, `resources/`, `config/`, `database/`, and `routes/`) returns zero callers in the application code. It is only referenced in its own test, in `AGENTS.md` documentation, and in internal documentation/runbooks.

```bash
# Application code search
grep -rn "PermissionError" app/
# Output:
# app/Mail/PermissionError.php:13:class PermissionError extends Mailable

# Usage search (excluding class definition and tests)
grep -rn "new PermissionError" app/ resources/ routes/ config/
# Output: (empty)

# Blade template search
grep -rn "PermissionError" resources/
# Output: (empty)
```

The mailable appears to have been intended for reporting file permission issues during the livestream processing pipeline, but it is currently never dispatched. General processing failures are instead handled by `App\Mail\LivestreamProcessingFailed`.

**Risk:**
Low — Pure removal of unused code and its associated assets/tests.

**Recommendation:**
Safe to remove. A complete cleanup would involve:
- `app/Mail/PermissionError.php`
- `resources/views/emails/permission-error.blade.php` (Blade view)
- `tests/Unit/Mail/PermissionErrorTest.php` (Unit test)
- Reference in `AGENTS.md` (line 257)
- Reference in `docs/operations/media-processing-runbook.md` (line 340)
