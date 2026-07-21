## 2025-05-15 - Documenting Livestream Media Processing Orchestration
**Learning:** Documenting complex service orchestrators requires balancing "what" the method does with "why" it does it (e.g., explaining the 2x storage space requirement or the eager-loading strategy for status retrieval). Precise array shapes for summary methods (like `getProcessingSummary`) are high-leverage documentation wins that immediately improve IDE autocompletion and PHPStan accuracy.
**Action:** Always look for associative array returns in services and replace generic `array<string, mixed>` with explicit PHPStan shapes. Ensure `@throws` annotations capture both domain-specific `Exception` calls and lower-level `RuntimeException` triggers.

## 2026-06-01 - Documenting Sermon Creation Logic and AI Data Shapes
**Learning:** Core business logic like the "richness-aware" upsert strategy for media processing needs clear PHPDoc explanation to prevent accidental "richness downgrades" during future maintenance. Complex array parameters (like `ai_analysis` or title generation `context`) should use PHPStan array shape annotations with optional keys (`?:`) when the data might be partially populated, which satisfies static analysis without introducing false positives in `isset()` checks.
**Action:** Use optional keys in array shapes for DTOs and service parameters that handle external API data or flexible context arrays. Ensure `isset()` checks in the implementation remain robust even when PHPDoc suggests keys "should" be there.

## 2026-06-03 - Centralizing Progress and Retry Documentation
**Learning:** The `ProcessingPhaseRegistry` is a critical bottleneck for user experience (progress bars) and system reliability (retries). Documenting the exact array shapes for retry plans (actions, strategies, and scopes) is essential for maintaining the contract between the registry and the `ProcessingRunOrchestrator`.
**Action:** When documenting registry-like services, prioritize documenting the return array shapes of mapping methods, as these define the "plans" executed by other services.

## 2026-06-05 - Enhancing Type Safety and Business Context
**Learning:** Added precise array shapes to `SermonAnalysis` DTO and its interface, and documented a magic number in `SermonCandidateConfidenceService`. Explicit array shapes are particularly useful for DTOs that interact with AI services or database attributes, as they clarify the expected keys and types which might otherwise be opaque. Documenting magic numbers like the 20-minute sermon threshold provides essential business context for why specific values were chosen.
**Action:** Continue to prioritize array shape documentation for DTOs and services that return or consume complex associative arrays. Always look for magic numbers in business logic and provide a "why" comment explaining their calibration or rationale.

## 2026-06-08 - Documenting Fuzzy Reassembly Logic
**Learning:** Documentation for reassembly and deduplication logic (like in `AudioChunkingService`) must explicitly bridge the gap between technical implementation (85% similarity threshold) and domain impact (Whisper transcription variations across chunk boundaries). Precise `list<array{...}>` shapes for multi-segment data are essential for ensuring callers provide the necessary metadata (indices, timestamps) for correct ordering and overlap handling.
**Action:** When documenting fuzzy matching or deduplication, always explain the calibration rationale for similarity thresholds to prevent arbitrary adjustments that might break edge-case handling.

## 2026-06-15 - Documenting Log Parsing and Performance Summaries
**Learning:** Documenting services that parse unstructured logs into structured data (like `ProcessingLogService`) requires precise return shapes to ensure downstream consumers (like status dashboards) can safely access nested metrics. Identifying that `timestamp` might be null in `step_metrics` (if a log entry lacks a timestamp but has performance data) was a critical catch for PHPStan accuracy.
**Action:** Use nullable types in array shapes (`type|null`) for keys derived from logs or external inputs that might be malformed or missing.

## 2026-06-18 - Centralizing Complex Array Shapes with PHPStan Types
**Learning:** For complex data structures reused across the processing pipeline (like `RetryPlan` and `ProcessingPhase`), defining `@phpstan-type` aliases in a central registry (e.g., `ProcessingPhaseRegistry`) and leveraging `@phpstan-import-type` in consuming services ensures documentation consistency and improves static analysis precision.
**Action:** Use `@phpstan-type` and `@phpstan-import-type` whenever a complex array shape is shared between services to prevent documentation rot. Note that `@phpstan-import-type` must be placed in the class-level PHPDoc block.

## 2026-06-20 - Documenting Preacher Resolution and Speaker Identification
**Learning:** Documenting the "why" in preacher services involves explaining the fallback strategies (e.g., mapping empty names to 'Visiting Speaker') and the technical boundaries of speaker identification (delegating to Python scripts for embedding extraction). Precise generic collection types like `Collection<int, SpeakerProfile>` and `list<array<int, float>>` significantly clarify the data flow in identification pipelines.
**Action:** When documenting services that rely on external scripts or complex matching logic, explicitly mention the underlying technology (e.g., Python/Resemblyzer) and the matching strategy (e.g., cosine similarity) to provide context for thresholds and error modes.

## 2026-06-25 - Documenting Automated Fallbacks and Non-Fatal Failures
**Learning:** Public methods in core media services (like `AudioExtractionService`) often implement automatic fallback logic (e.g., re-encoding if a file exceeds the Whisper 25MB limit). Explicitly documenting these fallbacks and the technical constraints that trigger them (e.g., specific file sizes or bitrate settings) is crucial for developers to understand the pipeline's resilience and capacity.
**Action:** Always highlight automatic recovery paths and technical limits (bitrates, file sizes, timeouts) in service PHPDoc to clarify the "contract" between the service and the infrastructure it interacts with.

## 2026-07-02 - Documenting Identity Resolution and Data Normalization
**Learning:** Documenting utility-like resolver services (like `MediaProcessingIdentityResolver`) requires clear explanation of their role in bridging unstructured metadata (extracted from uploads) with structured domain models (like `Sermon` records). Precise array shapes for resolution results and descriptive parameter documentation for parsing methods ensure that callers (Queries, Jobs, Commands) understand the expected format and validation rules of the identity components.
**Action:** Always document the specific "identity" shape (e.g., date and service) in resolver services to clarify how records are matched and retrieved. Ensure query scopes are documented with their filtering criteria and parameter types to support better developer experience in repository or query classes.

## 2026-07-05 - Clarifying Extraction Precedence and Data Shapes
**Learning:** Documentation for entry-point methods (like `ProcessingInitiator::initiateProcessing`) must explicitly define the "Identity Extraction Hierarchy" to clarify how overlapping inputs (overrides, metadata, determination) are resolved. Precise PHPStan array shapes for mixed-data parameters (like `additionalLogData` and `preExtractedMetadata`) are essential for catching type mismatches early, such as when a service-level storage path may return `false` on failure while the DB expects a `string`.
**Action:** Always include a hierarchy or precedence list in PHPDoc for methods with multiple source-data inputs. Use explicit array shapes for all "bag of data" parameters to satisfy static analysis and improve discoverability of expected keys.

## 2026-07-07 - Documenting Interface Contracts for API Clarity
**Learning:** Interface contracts in `app/Contracts/` define the API surface and boundary between services. Providing class-level PHPDoc that explains the "why" and "purpose" of the interface, along with detailed method documentation, significantly improves developer experience when navigating service implementations.
**Action:** Prioritize documenting interface contracts as they are the source of truth for service expectations. Ensure parameter and return descriptions clarify the domain context (e.g., OoS email components) rather than just technical types.

## 2026-07-20 - Documenting Complex Quality Assessment Services
**Learning:** When documenting intricate services that run local video frame evaluations (like `SermonVideoQualityAssessmentService`), it's essential to define distinct phpstan-type schemas (such as `FrameMetrics` and `FrozenWindowMetrics`) at the class level. Keep documentation strictly constrained to the public API/methods to follow negative boundaries and respect internal implementation encapsulated within private methods.
**Action:** Ensure custom `@phpstan-type` array shapes are clearly documented. Avoid documenting private methods as they are implementation details, focusing instead on robust class-level and public method PHPDoc.
