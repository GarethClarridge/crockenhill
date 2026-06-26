# 🪦 Mortician: Possibly dead — `App\Services\Processing\SermonValidationService`

## What
**Path:** `app/Services/Processing/SermonValidationService.php`
**Description:** A service class intended for validating sermon uploads and generating fallback metadata.

## Evidence
*   **Zero Production Callers:** A project-wide grep for the class name and all its public methods returns no results in `app/`, `resources/`, `routes/`, or `config/`.
*   **Methods Checked:**
    *   `validateAudioFile`: No production callers (superseded by `MediaValidationService`).
    *   `validateProcessingMetadata`: No production callers.
    *   `generateFallbackData`: No production callers.
    *   `generateFallbackTitle`: No production callers (similar logic exists in `ProcessTranscriptWithAI` and `SermonAnalysisPromptBuilder` but doesn't call this service).
    *   `validateSermonData`: No production callers.
    *   `validateStorageConstraints`: No production callers (superseded by `TempDiskSpace`).
    *   `validateProcessingRequirements`: No production callers.
    *   `canRetryProcessing`: No production callers (superseded by `ProcessingRunFailureHandler`).
    *   `requiresManualReview`: No production callers (superseded by `ProcessingRunFailureHandler`).
*   **Unbound:** The class is not bound in `app/Providers/MediaProcessingServiceProvider.php` or any other service provider.
*   **Documentation Only:** The class name only appears in comments within `config/media-processing.php` and in architectural review documents.

## Risk
**Low** — The class is entirely isolated and unreferenced. Removing it will not impact any active code paths.

## Recommendation
**Safe to remove.** The removal should also include the following associated test files:
*   `tests/Unit/Services/SermonValidationServiceTest.php`
*   `tests/Integration/Services/SermonValidationServiceTest.php`

---

# 🪦 Mortician: Redundant Heading Assets — `.jpg` files in `public/images/headings/`

## What
**Path:** `public/images/headings/large/*.jpg`, `public/images/headings/small/*.jpg`, `public/images/headings/links.jpg`
**Description:** Redundant JPEG versions of page heading images that have been migrated to WebP.

## Evidence
*   **Service Logic:** `App\Services\Public\PageImageCacheService::resolveHeadingImageUrl` explicitly searches for `.webp` files:
    ```php
    $storagePath = "pages/headings/{$size}/{$page->slug}.webp";
    ```
*   **Zero References:** A project-wide grep for these specific `.jpg` filenames (e.g., `sunday-mornings.jpg`, `pastor.jpg`) returns no hits in Blade templates, CSS, or JS.
*   **Presence of Alternatives:** Every `.jpg` file in these directories has a corresponding `.webp` file which is the one actually served by the application.

## Risk
**Low** — These are redundant assets. The `.webp` versions are already in place and used by the cache service.

## Recommendation
**Safe to remove.** Pruning these 37 files will reduce repository size and simplify asset management.
