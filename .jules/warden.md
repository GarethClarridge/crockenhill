# Warden Journal

## 2026-03-13 - Fortify Processing Log Integrity
**Learning:** Found that `sermon_processing_steps` lacked a formal database foreign key to `media_processing_logs`, and its status enum was inconsistent with the PHP `ProcessingStatus` enum. Also discovered that applying an enum cast to a model can break Livewire components if they expect string values for rendering.

**Action:** Always verify that enum-casted model properties are converted back to strings (e.g., using `->value`) when being passed to view layers or used in strict string contexts. Ensure referenced columns in foreign keys are properly indexed and have compatible collations.

## 2026-03-26 - Calendar Event Timing and Status Integrity
**Learning:** `calendar_events` table lacked a database-level `CHECK` constraint for timing invariants (`end_datetime >= start_datetime`), and its status was a simple string rather than a formalized ENUM. Introducing these constraints uncovered multiple existing tests that were generating invalid data (end time before start time) or using non-existent status values like `tentative` and `cancelled`.

**Action:** When adding database-level constraints to existing tables, proactively check and update model factories and existing test suites, as they are likely to be the first point of failure. Formalize magic strings into string-backed enums and update model `$casts` to ensure type safety throughout the application.
