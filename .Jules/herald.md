## 2025-05-15 - Documenting Livestream Media Processing Orchestration
**Learning:** Documenting complex service orchestrators requires balancing "what" the method does with "why" it does it (e.g., explaining the 2x storage space requirement or the eager-loading strategy for status retrieval). Precise array shapes for summary methods (like `getProcessingSummary`) are high-leverage documentation wins that immediately improve IDE autocompletion and PHPStan accuracy.
**Action:** Always look for associative array returns in services and replace generic `array<string, mixed>` with explicit PHPStan shapes. Ensure `@throws` annotations capture both domain-specific `Exception` calls and lower-level `RuntimeException` triggers.

## 2026-06-01 - Documenting Sermon Creation Logic and AI Data Shapes
**Learning:** Core business logic like the "richness-aware" upsert strategy for media processing needs clear PHPDoc explanation to prevent accidental "richness downgrades" during future maintenance. Complex array parameters (like `ai_analysis` or title generation `context`) should use PHPStan array shape annotations with optional keys (`?:`) when the data might be partially populated, which satisfies static analysis without introducing false positives in `isset()` checks.
**Action:** Use optional keys in array shapes for DTOs and service parameters that handle external API data or flexible context arrays. Ensure `isset()` checks in the implementation remain robust even when PHPDoc suggests keys "should" be there.

## 2026-06-03 - Centralizing Progress and Retry Documentation
**Learning:** The `ProcessingPhaseRegistry` is a critical bottleneck for user experience (progress bars) and system reliability (retries). Documenting the exact array shapes for retry plans (actions, strategies, and scopes) is essential for maintaining the contract between the registry and the `ProcessingRunOrchestrator`.
**Action:** When documenting registry-like services, prioritize documenting the return array shapes of mapping methods, as these define the "plans" executed by other services.
