# 🪦 Mortician: Possibly dead — `App\Services\Processing\SermonValidationService`

## What
**Path:** `app/Services/Processing/SermonValidationService.php`
**Description:** A service class intended for validating sermon uploads and generating fallback metadata.

## Evidence
Project-wide grep for the class name and all its public methods returns no results in `app/`, `resources/`, `routes/`, or `config/` (excluding the file itself and documentation/comments).

### Commands run:
```bash
# Check for class references
grep -r "SermonValidationService" app/ resources/ routes/ config/

# Check for public method callers (examples)
grep -r "validateAudioFile" app/ resources/ routes/
grep -r "generateFallbackData" app/ resources/ routes/
```

### Result:
- **Zero Production Callers:** No active code paths utilize this service.
- **Superseded:** Its responsibilities have been absorbed by newer, more specific classes:
    - File validation is now handled by `App\Services\Processing\MediaValidationService`.
    - Storage headroom checks are handled by `App\Services\Processing\TempDiskSpace`.
    - Fallback logic is implemented directly in `App\Jobs\ProcessTranscriptWithAI`.
    - Error and retry states are managed by `App\Services\Processing\ProcessingRunFailureHandler`.
- **Unbound:** The class is not registered in any Service Provider.

## Reality
This class is a relic from the early March 2026 media pipeline architecture. It was left behind during the transition to the `UnifiedMediaProcessor` and the granular job chain system. It remains only as a source of confusion for developers auditing the media services.

## Risk
**Low** — The class is entirely isolated and unreferenced.

## Recommendation
**Safe to remove.** The removal should also include its associated test files:
- `tests/Unit/Services/SermonValidationServiceTest.php`
- `tests/Integration/Services/SermonValidationServiceTest.php`
