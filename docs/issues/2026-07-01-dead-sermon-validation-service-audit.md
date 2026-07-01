# 🪦 Mortician: Dead Code Audit — `App\Services\Processing\SermonValidationService`

## What
**Path:** `app/Services/Processing/SermonValidationService.php`
**Description:** A service class from the legacy media pipeline intended for validating sermon uploads and generating fallback metadata.

## Evidence
A comprehensive project-wide audit confirms this class has zero active production callers. Its previous responsibilities have been entirely superseded by the modern `UnifiedMediaProcessor` pipeline.

### Reference Audit
```bash
# 1. Search for class references in production code
grep -rn "SermonValidationService" app/ resources/ routes/ config/
# Result:
# config/media-processing.php:59: (Comment reference only)
# app/Services/Processing/SermonValidationService.php:22: (Class definition)

# 2. Search for public method callers
grep -rn "validateAudioFile" app/ resources/ routes/
# Result: Only called by App\Jobs\ValidateAudioFile (delegates to AudioExtractionService)
# and App\Services\Media\Audio\AudioExtractionService itself.
# The implementation in SermonValidationService is unreferenced.

grep -rn "generateFallbackData" app/ resources/ routes/
# Result: Zero callers. Fallback logic now lives in App\Jobs\ProcessTranscriptWithAI.
```

### Functional Supersession
- **File Validation:** Now handled by `App\Services\Processing\MediaValidationService`.
- **Disk Space Monitoring:** Now handled by `App\Services\Media\TempDiskSpace` (shared by `MediaValidationService` and `HistoricVideoImporter`).
- **Metadata Fallbacks:** Now implemented directly in `App\Jobs\ProcessTranscriptWithAI` and `App\Jobs\CreateSermonRecord`.
- **Pipeline Orchestration:** Retries and error states are managed by `App\Services\Processing\ProcessingRunFailureHandler` and `App\Services\Processing\ProcessingRunOrchestrator`.

## Reality
This class is a "ghost" from the early March 2026 media pipeline architecture. It was left behind during the transition to the granular job chain system. It serves no functional purpose and complicates the maintenance of the media services layer.

## Risk
**Low** — The class is unreferenced by the container, type-hints, or string resolution paths.

## Recommendation
**Bury it.**
- Delete `app/Services/Processing/SermonValidationService.php`.
- Remove the stale comment in `config/media-processing.php`.
- **Note on Tests:** While the class is dead, its associated tests (`tests/Unit/Services/SermonValidationServiceTest.php` and `tests/Integration/Services/SermonValidationServiceTest.php`) contain logic that might be useful for documenting the *old* validation requirements. However, per the Mortician mission, these tests should either be retired or deleted only once the removal is approved.
