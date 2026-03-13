# Warden Journal

## 2026-03-13 - Fortify Processing Log Integrity
**Learning:** Found that `sermon_processing_steps` lacked a formal database foreign key to `media_processing_logs`, and its status enum was inconsistent with the PHP `ProcessingStatus` enum. Also discovered that applying an enum cast to a model can break Livewire components if they expect string values for rendering.

**Action:** Always verify that enum-casted model properties are converted back to strings (e.g., using `->value`) when being passed to view layers or used in strict string contexts. Ensure referenced columns in foreign keys are properly indexed and have compatible collations.
