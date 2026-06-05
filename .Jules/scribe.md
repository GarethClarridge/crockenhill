## 2025-06-05 - [MediaProcessingLog Testing]
**Learning:** Testing model logic that depends on complex JSON metadata (cast via Spatie Laravel Data) requires careful factory state setup or manual attribute overrides to hit different code branches (e.g., `requiresManualSermonReview` depending on `manualReview->status`).
**Action:** Always check the `casts()` method and the corresponding Data objects to understand the expected structure of metadata before writing assertions against it.

## 2025-06-05 - [Legacy Fallback Testing]
**Learning:** Some models maintain legacy fallback logic (e.g., parsing string-based `error_message` when `processing_metadata` is null). Testing these ensures backward compatibility during migrations.
**Action:** Use `grep` to find these legacy patterns and explicitly test them alongside modern implementations.
