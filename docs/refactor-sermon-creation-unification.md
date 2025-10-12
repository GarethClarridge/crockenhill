# Refactor: Unify Sermon Creation Logic

## Current Problem

Two jobs (`CreateSermonRecord` and `SubmitToProcessing`) duplicate sermon creation logic, leading to:
- **Code duplication**: Date extraction, service detection, slug generation, title generation
- **Maintenance burden**: Bug fixes and features must be applied in two places
- **Inconsistency risk**: Logic can drift between the two implementations
- **Recent example**: Date extraction bug only fixed in one place, requiring duplicate fix

## Current Architecture

### CreateSermonRecord Job
**Used by:** Audio uploads, Direct video uploads

**Job chain:**
```
Audio:    ValidateAudioFile → CreateSermonRecord → TranscribeAudio → ProcessTranscriptWithAI
Video:    ValidateVideoFile → ExtractAudioFromVideo → CreateSermonRecord → TranscribeAudio → ProcessTranscriptWithAI → GenerateThumbnail
```

**Responsibilities:**
- Extract date from processing metadata or filename
- Detect service type (morning/evening) from filename
- Generate sermon title from AI analysis
- Create slug with uniqueness check
- Create Sermon model
- Handle video file path (for video uploads)
- Update processing log with sermon_id

**File:** `app/Jobs/CreateSermonRecord.php` (279 lines)

**Key Methods:**
- `handle()` - Main job execution
- `extractSermonDate()` - Date extraction with cascading fallback
- `extractDateFromFilename()` - Parse date patterns from filename
- `extractServiceFromFilename()` - Detect morning/evening service
- `generateSlug()` - Create unique URL slug
- `generateInitialTitle()` - Create title from filename or AI analysis
- `mapProcessingTypeToSourceType()` - Map processing type to sermon source

### SubmitToProcessing Job
**Used by:** Livestream uploads

**Job chain:**
```
Livestream: GenerateRmsLog → AnalyzeSegments → ExtractSermon → SubmitToProcessing → TranscribeAudio → ProcessTranscriptWithAI → GenerateThumbnail
```

**Responsibilities:**
- Validate audio file exists (with extensive S3/local diagnostics)
- Create sermon from livestream metadata
- Link video file to sermon (from temp storage)
- Update processing log with sermon_id
- Extract date from processing metadata or filename
- Detect service type from filename
- Generate sermon title from filename
- Create slug with uniqueness check

**File:** `app/Jobs/SubmitToProcessing.php` (348 lines)

**Key Methods:**
- `handle()` - Main job execution with storage validation
- `createSermonFromLivestream()` - Create sermon record
- `extractSermonDate()` - Date extraction with cascading fallback
- `extractDateFromFilename()` - Parse date patterns from filename (duplicated)
- `extractServiceFromFilename()` - Detect service type (duplicated)
- `generateTitleFromMetadata()` - Create title from filename
- `generateUniqueSlug()` - Create unique URL slug (duplicated)

## Duplicated Code Analysis

### Exact Duplicates (Can be extracted as-is)

1. **Date extraction from filename** (~15 lines)
   - Pattern: YYYY-MM-DD, DD-MM-YYYY
   - Fallback to current date
   - Location: Both jobs have `extractDateFromFilename()`

2. **Service detection from filename** (~15 lines)
   - Pattern matching: 'evening', 'eve', 'pm' → evening; default → morning
   - Location: Both jobs have `extractServiceFromFilename()`

3. **Slug generation with uniqueness** (~30 lines)
   - Sanitization, conflict detection, numeric suffix
   - Location: `CreateSermonRecord::generateSlug()` vs `SubmitToProcessing::generateUniqueSlug()`

4. **Date extraction with metadata priority** (~20 lines)
   - Check `processing_metadata['extracted_date']` first
   - Fall back to filename parsing
   - Location: Both jobs have `extractSermonDate()`

### Similar but Different (Requires unification)

1. **Title generation**
   - CreateSermonRecord: Uses AI analysis first, then filename fallback
   - SubmitToProcessing: Only uses filename parsing
   - Can be unified with a flag or strategy pattern

2. **Sermon data assembly**
   - Both create similar arrays for `Sermon::create()`
   - Slight differences in fields (livestream_processing_id, source_type handling)
   - Can be unified with optional parameters

### Job-Specific Logic (Keep separate)

1. **CreateSermonRecord:**
   - AI analysis integration
   - Processing log refresh (for queued jobs)
   - Processing logger integration
   - Step-by-step status updates

2. **SubmitToProcessing:**
   - Extensive storage validation (S3 + local)
   - Video file linking from temp storage
   - Livestream segment metadata
   - Different error handling for storage issues

## Proposed Solution

### New Service: `SermonCreationService`

Create a new service that handles all common sermon creation logic.

**Location:** `app/Services/SermonCreationService.php`

**Responsibilities:**
- Date extraction (from metadata, filename, or default)
- Service type detection
- Title generation (with multiple strategies)
- Slug generation with uniqueness checks
- Sermon record creation with validation
- Common error handling

**Public Methods:**

```php
class SermonCreationService
{
    /**
     * Create a sermon record with all necessary metadata
     *
     * @param MediaProcessingLog $processingLog
     * @param SermonCreationOptions $options
     * @return Sermon
     */
    public function createSermon(
        MediaProcessingLog $processingLog,
        SermonCreationOptions $options
    ): Sermon;

    /**
     * Extract sermon date using cascading strategy
     * 1. Processing metadata (client-provided or video metadata)
     * 2. Filename parsing
     * 3. Current date
     *
     * @param MediaProcessingLog $processingLog
     * @param string $filename
     * @return string Date in Y-m-d format
     */
    public function extractDate(
        MediaProcessingLog $processingLog,
        string $filename
    ): string;

    /**
     * Detect service type (morning/evening) from filename
     *
     * @param string $filename
     * @return string 'morning' or 'evening'
     */
    public function extractServiceType(string $filename): string;

    /**
     * Generate a unique URL slug for the sermon
     *
     * @param string $baseTitle
     * @param string|null $date For date-based disambiguation
     * @return string
     */
    public function generateUniqueSlug(string $baseTitle, ?string $date = null): string;

    /**
     * Generate sermon title using specified strategy
     *
     * @param TitleGenerationStrategy $strategy
     * @param array $context Context data (AI analysis, filename, etc.)
     * @return string
     */
    public function generateTitle(
        TitleGenerationStrategy $strategy,
        array $context
    ): string;
}
```

### New DTO: `SermonCreationOptions`

**Location:** `app/Data/SermonCreationOptions.php`

```php
class SermonCreationOptions
{
    public function __construct(
        // Required fields
        public string $audioFilePath,
        public string $originalFilename,
        public string $sourceType,

        // Optional fields with defaults
        public ?string $videoFilePath = null,
        public ?string $transcriptFilePath = null,
        public ?array $aiAnalysis = null,
        public ?string $livestreamProcessingId = null,
        public ?float $segmentStartTime = null,
        public ?float $segmentEndTime = null,

        // Title generation strategy
        public TitleGenerationStrategy $titleStrategy = TitleGenerationStrategy::AI_WITH_FALLBACK,

        // Override defaults
        public ?string $preacher = null,
        public ?string $service = null,
        public ?string $date = null,
    ) {}

    public static function fromAudioUpload(MediaProcessingLog $log, array $aiAnalysis): self;
    public static function fromVideoUpload(MediaProcessingLog $log, array $aiAnalysis): self;
    public static function fromLivestream(MediaProcessingLog $log, array $metadata): self;
}
```

### New Enum: `TitleGenerationStrategy`

**Location:** `app/Enums/TitleGenerationStrategy.php`

```php
enum TitleGenerationStrategy: string
{
    case AI_WITH_FALLBACK = 'ai_with_fallback';  // Try AI analysis, fall back to filename
    case FILENAME_ONLY = 'filename_only';          // Only use filename parsing
    case CUSTOM = 'custom';                         // Use provided title directly
}
```

## Implementation Plan

### Phase 1: Create Service Infrastructure (No breaking changes)

**Step 1.1:** Create `SermonCreationService` with core methods
- Extract and test `extractDate()` logic
- Extract and test `extractServiceType()` logic
- Extract and test `generateUniqueSlug()` logic
- **Deliverable:** New service with unit tests
- **Risk:** Low - no existing code changed

**Step 1.2:** Create DTOs and Enums
- Create `SermonCreationOptions` DTO with factory methods
- Create `TitleGenerationStrategy` enum
- **Deliverable:** Type-safe data structures
- **Risk:** Low - just data structures

**Step 1.3:** Add title generation strategies
- Implement AI-with-fallback strategy (from CreateSermonRecord)
- Implement filename-only strategy (from SubmitToProcessing)
- **Deliverable:** Flexible title generation
- **Risk:** Low - new code, not changing existing

**Step 1.4:** Add main `createSermon()` method
- Implement full sermon creation logic
- Use all the extracted methods
- Handle video file paths, AI analysis, livestream metadata
- **Deliverable:** Complete service ready for use
- **Risk:** Low - new code, well-tested

### Phase 2: Refactor CreateSermonRecord (Breaking change contained)

**Step 2.1:** Update CreateSermonRecord to use service
```php
public function handle(
    SermonProcessingLogger $logger,
    SermonCreationService $sermonCreationService
): void {
    // Refresh processing log
    $refreshedLog = $this->processingLog->fresh();
    // ... existing validation ...

    // Prepare options using factory method
    $options = SermonCreationOptions::fromAudioUpload($refreshedLog, $aiAnalysis);

    // Or for video:
    // $options = SermonCreationOptions::fromVideoUpload($refreshedLog, $aiAnalysis);

    // Create sermon using service
    $sermon = $sermonCreationService->createSermon($refreshedLog, $options);

    // Update processing log
    $refreshedLog->update(['sermon_id' => $sermon->id]);

    // ... rest of existing logic ...
}
```

**Step 2.2:** Remove duplicated methods from CreateSermonRecord
- Delete `extractDateFromFilename()`
- Delete `extractServiceFromFilename()`
- Delete `generateSlug()`
- Delete `extractSermonDate()`
- Keep job-specific logic (AI integration, status updates, logging)

**Step 2.3:** Test thoroughly
- Run all existing tests
- Verify audio upload creates sermons correctly
- Verify video upload creates sermons correctly
- Verify dates, slugs, services are extracted correctly
- **Risk:** Medium - changing existing functionality

### Phase 3: Refactor SubmitToProcessing (Breaking change contained)

**Step 3.1:** Update SubmitToProcessing to use service
```php
public function handle(
    SermonMetadataIntegrationService $metadataIntegrationService,
    SermonCreationService $sermonCreationService
): void {
    // ... existing storage validation ...

    // Prepare options using factory method
    $options = SermonCreationOptions::fromLivestream(
        $this->processingLog,
        $metadata
    );

    // Create sermon using service
    $sermon = $sermonCreationService->createSermon($this->processingLog, $options);

    // Handle livestream-specific video linking
    if ($this->processingLog->video_file_path) {
        $this->linkVideoToSermon($sermon);
    }

    // Update processing log
    $this->processingLog->update(['sermon_id' => $sermon->id]);

    // ... rest of existing logic ...
}
```

**Step 3.2:** Remove duplicated methods from SubmitToProcessing
- Delete `extractDateFromFilename()`
- Delete `extractServiceFromFilename()`
- Delete `generateUniqueSlug()`
- Delete `extractSermonDate()`
- Delete `createSermonFromLivestream()`
- Delete `generateTitleFromMetadata()`
- Keep job-specific logic (storage validation, video linking)

**Step 3.3:** Test thoroughly
- Run all existing tests
- Verify livestream upload creates sermons correctly
- Verify video file linking still works
- Verify dates, slugs, services are extracted correctly
- **Risk:** Medium - changing existing functionality

### Phase 4: Cleanup and Documentation

**Step 4.1:** Update existing tests
- Modify tests to use service directly where appropriate
- Add new tests for edge cases
- Ensure 100% code coverage for service

**Step 4.2:** Update documentation
- Add service documentation to CLAUDE.md
- Document the DTO and its factory methods
- Add examples of using the service

**Step 4.3:** Remove debug logging
- Remove `CreateSermonRecord: Checking for extracted date` debug log
- Clean up any other temporary debug logs added during investigation

## Benefits

### Code Quality
- **DRY principle**: Single source of truth for sermon creation logic
- **Single Responsibility**: Jobs focus on job-specific concerns (storage, validation, orchestration)
- **Testability**: Service can be unit tested independently
- **Type Safety**: DTOs provide compile-time checking

### Maintainability
- **Bug fixes**: One place to fix issues
- **Features**: One place to add new extraction logic
- **Refactoring**: Easier to change sermon creation logic
- **Onboarding**: Clearer separation of concerns

### Consistency
- **Behavior**: Same logic for all upload types
- **Validation**: Unified validation rules
- **Error handling**: Consistent error messages
- **Logging**: Standardized logging format

## Risks and Mitigation

### Risk 1: Breaking Existing Functionality
**Mitigation:**
- Implement service first without touching existing code
- Extensive testing at each phase
- Feature flag to switch between old and new implementation
- Easy rollback (just revert job changes)

### Risk 2: Performance Impact
**Mitigation:**
- Service is stateless, no overhead
- Same logic, just reorganized
- Profile before/after to confirm no regression

### Risk 3: Incomplete Edge Case Handling
**Mitigation:**
- Careful code review of all paths
- Extract test cases from existing tests
- Add new tests for edge cases discovered during refactor

### Risk 4: Subtle Behavioral Differences
**Mitigation:**
- Document all differences between CreateSermonRecord and SubmitToProcessing
- Ensure service preserves both behaviors with strategy pattern
- A/B testing in staging environment

## Testing Strategy

### Unit Tests (New)
- `SermonCreationServiceTest.php`
  - Test date extraction with all strategies
  - Test service type detection with all patterns
  - Test slug generation with conflicts
  - Test title generation with all strategies
  - Test sermon creation with all option combinations

### Integration Tests (Modified)
- Modify existing sermon creation tests to verify same behavior
- Add tests for new factory methods on `SermonCreationOptions`

### Feature Tests (Existing)
- Run full test suite to ensure no regressions
- Specifically verify:
  - `AutomatedSermonApiTest`
  - `DirectSermonVideoUploadTest`
  - `LivestreamProcessingIntegrationTest`
  - `SermonCreationIntegrationTest`

### Manual Testing
- Upload audio file → verify sermon created correctly
- Upload video file → verify sermon created correctly with video
- Upload livestream file → verify sermon created correctly with video
- Verify dates extracted correctly for all types
- Verify slugs are unique and correct
- Verify services detected correctly

## Success Criteria

1. ✅ All existing tests pass
2. ✅ New service has 100% code coverage
3. ✅ Zero duplication in date/service/slug extraction
4. ✅ CreateSermonRecord reduced by ~100 lines
5. ✅ SubmitToProcessing reduced by ~100 lines
6. ✅ Sermon creation behavior identical to before
7. ✅ PHPStan passes with 0 errors
8. ✅ Performance unchanged (or improved)

## Timeline Estimate

- **Phase 1:** 4-6 hours (service creation + tests)
- **Phase 2:** 2-3 hours (CreateSermonRecord refactor + tests)
- **Phase 3:** 2-3 hours (SubmitToProcessing refactor + tests)
- **Phase 4:** 1-2 hours (cleanup + documentation)

**Total:** 9-14 hours

## Future Enhancements (Post-Refactor)

Once this refactor is complete, the following improvements become easier:

1. **Custom title formatting**: Add more title strategies
2. **Metadata enrichment**: Easier to add new metadata extraction (speaker detection, series detection)
3. **Validation improvements**: Centralized validation for all sermon fields
4. **Alternative slug strategies**: Date-based, series-based, etc.
5. **Audit logging**: Track all sermon creations in one place
6. **Dry-run mode**: Preview sermon before creation
7. **Bulk imports**: Reuse service for CSV/batch imports

## Related Files

### Files to Create
- `app/Services/SermonCreationService.php`
- `app/Data/SermonCreationOptions.php`
- `app/Enums/TitleGenerationStrategy.php`
- `tests/Unit/Services/SermonCreationServiceTest.php`

### Files to Modify
- `app/Jobs/CreateSermonRecord.php` (reduce from ~279 to ~150 lines)
- `app/Jobs/SubmitToProcessing.php` (reduce from ~348 to ~200 lines)
- Various test files to accommodate new structure

### Files to Reference
- `app/Services/ProcessingPipelineBuilder.php` (understand job chains)
- `app/Models/Sermon.php` (understand model fields)
- `app/Models/MediaProcessingLog.php` (understand metadata structure)

## Notes

- This refactor does NOT change the job chains or pipeline builder
- This refactor does NOT change the ProcessingPipelineBuilder
- This refactor does NOT add new features (except better flexibility)
- This refactor focuses purely on eliminating duplication
- The jobs remain responsible for orchestration, validation, and job-specific concerns
- The service handles pure sermon creation logic

## Approval Required

Before starting this refactor, confirm:
- [ ] Acceptance of 9-14 hour time investment
- [ ] Agreement that benefits justify the effort
- [ ] Acceptance of risks with mitigation plan
- [ ] Approval to modify CreateSermonRecord job
- [ ] Approval to modify SubmitToProcessing job
- [ ] Test coverage requirements met
