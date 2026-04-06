# Warden Journal

## 2026-03-13 - Fortify Processing Log Integrity
**Learning:** Found that `sermon_processing_steps` lacked a formal database foreign key to `media_processing_logs`, and its status enum was inconsistent with the PHP `ProcessingStatus` enum. Also discovered that applying an enum cast to a model can break Livewire components if they expect string values for rendering.

**Action:** Always verify that enum-casted model properties are converted back to strings (e.g., using `->value`) when being passed to view layers or used in strict string contexts. Ensure referenced columns in foreign keys are properly indexed and have compatible collations.

## 2026-03-26 - Calendar Event Timing and Status Integrity
**Learning:** `calendar_events` table lacked a database-level `CHECK` constraint for timing invariants (`end_datetime >= start_datetime`), and its status was a simple string rather than a formalized ENUM. Introducing these constraints uncovered multiple existing tests that were generating invalid data (end time before start time) or using non-existent status values like `tentative` and `cancelled`.

**Action:** When adding database-level constraints to existing tables, proactively check and update model factories and existing test suites, as they are likely to be the first point of failure. Formalize magic strings into string-backed enums and update model `$casts` to ensure type safety throughout the application.

## 2026-04-01 - Media Duration and Timing Invariants
**Learning:** Found that several media-related tables (`media_processing_logs`, `song_videos`, `livestream_segments`) lacked database-level `CHECK` constraints for durations and timing invariants (e.g., `end_time >= start_time`). While the application logic generally handles these, the database is the last line of defense against corrupted data from failed processing jobs or manual database edits.

**Action:** Implement `CHECK` constraints for all numeric columns that have logical bounds (e.g., duration must be non-negative). When implementing these constraints, use raw SQL `DB::statement` for maximum compatibility and provide explicit constraint names to ensure helpful error messages in test failures. Always verify that existing tests which perform migration rollbacks are updated to account for the new migration steps.

## 2026-04-03 - Recurring Meeting Frequency Dependency
**Learning:** Found that the `meetings` table allowed a meeting to be marked as recurring (`is_recurring = true`) without a mandatory `frequency`, leading to potential logic errors during next-occurrence calculations. This dependency was only partially enforced in some UI-layer validation but missing in others.

**Action:** Enforce cross-column dependencies using database-level `CHECK` constraints (`is_recurring = 0 OR frequency IS NOT NULL`). Synchronize this rule across all validation entry points (FormRequests and Livewire Forms). When testing data integrity, add new dedicated test files to avoid modifying or deleting existing coverage, and ensure tests target both the database level (using raw DB inserts) and the application level (using Validator).
