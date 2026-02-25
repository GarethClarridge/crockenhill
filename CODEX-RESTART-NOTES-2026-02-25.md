# Codex Restart Notes (2026-02-25)

## 1. Simplification Findings (Prioritized)

1. **[P1] Processing state is encoded as ad-hoc strings in many places**
   - `app/Data/StandardProcessingResponse.php`
   - `app/Services/LivestreamStatusService.php`
   - `app/Jobs/TranscribeAudio.php`
   - Recommendation: introduce a single `ProcessingStep` enum + one progress mapper.

2. **[P1] S3/local storage handling is duplicated across services**
   - `app/Services/VideoExtractionService.php`
   - `app/Services/VideoStorageService.php`
   - `app/Services/SermonMetadataIntegrationService.php`
   - `app/Services/FrameExtractionService.php`
   - Recommendation: centralize storage adapter logic (exists/download/upload/cleanup).

3. **[P1] Media orchestration has overlapping service layers**
   - `app/Services/UnifiedMediaProcessor.php`
   - `app/Services/SermonProcessingService.php`
   - `app/Services/SermonAudioProcessingService.php`
   - `app/Services/LivestreamSegmentationService.php`
   - Recommendation: one orchestrator + per-type strategy, remove pass-through facades.

4. **[P2] Validation rules are duplicated and diverging**
   - `app/Services/MediaValidationService.php`
   - `app/Http/Requests/ProcessMediaRequest.php`
   - `app/Services/SermonAudioProcessingService.php`
   - `app/Services/AudioExtractionService.php`
   - `app/Jobs/ValidateVideoFile.php`
   - Recommendation: one canonical validator source consumed everywhere.

5. **[P2] `GenerateThumbnail` has legacy constructor complexity not used by runtime pipeline**
   - `app/Jobs/GenerateThumbnail.php`
   - `app/Services/ProcessingPipelineBuilder.php`
   - Recommendation: keep a single constructor path (`MediaProcessingLog`) and simplify.

6. **[P2] Logging/reporting is split across overlapping implementations**
   - `app/Services/MediaProcessingLogger.php`
   - `app/Services/LivestreamProcessingLogger.php`
   - `app/Services/ProcessingLogService.php`
   - Recommendation: unify logging/reporting behind one structured source.

7. **[P2] Livestream processing result mapping is duplicated**
   - `app/Services/LivestreamSegmentationService.php`
   - `app/Services/LivestreamStatusService.php`
   - Recommendation: extract one shared mapper.

8. **[P2] Runtime-dead services/requests/controllers exist (test-only or unreferenced in app runtime)**
   - `app/Services/LivestreamErrorHandler.php`
   - `app/Services/ProcessingExceptionHandler.php`
   - `app/Services/SermonMetadataService.php`
   - `app/Services/SermonVideoDisplayService.php`
   - `app/Http/Requests/StorePageRequest.php`
   - `app/Http/Requests/UpdatePageRequest.php`
   - `app/Http/Requests/Auth/LoginRequest.php`
   - `app/Http/Controllers/Auth/PasswordController.php`
   - Recommendation: remove/archive after confirming no external dependencies.

9. **[P3] Routing includes overlapping paradigms and wrappers**
   - `routes/web.php`
   - `app/Http/Controllers/SermonAdminController.php`
   - `app/Http/Controllers/SermonController.php`
   - Recommendation: pick one canonical route style and phase out wrappers.

10. **[P3] `Sermon` model carries too many presentation responsibilities**
    - `app/Models/Sermon.php`
    - Recommendation: move sitemap/podcast/display formatting concerns to presenters/transformers.

11. **[P3] `SubmitToProcessing` has heavy diagnostic branches in hot-path logic**
    - `app/Jobs/SubmitToProcessing.php`
    - Recommendation: move deep diagnostics behind explicit debug mode/helper.

12. **[P3] Queue config contains overlapping aliases and fallback chains**
    - `config/media-processing.php`
    - Recommendation: define one canonical queue map and migrate callers.

## 2. Laravel Boost MCP Install Status

- Initially unavailable in Codex because `boost.json` listed only `"claude_code"`.
- Updated `boost.json` agents to include `"codex"`.
- Ran:
  - `./vendor/bin/sail artisan boost:install --mcp --no-interaction`
- Generated Codex MCP config:
  - `.codex/config.toml`
- Corrected Codex MCP working directory for desktop workspace:
  - `cwd = "/Users/garethclarridge/Projects/crockenhill"`
  - (Boost wrote `/var/www/html` initially, which is container-only.)

## 3. After Restart Checklist

1. Confirm Codex has reloaded `.codex/config.toml`.
2. Verify MCP server visibility (Laravel Boost should appear).
3. Resume simplification work starting with:
   - processing-step unification,
   - storage adapter consolidation.

