# Implementation Plan

- [x] 1. Create database migration for thumbnail fields
  - Add nullable thumbnail_path, thumbnail_generated_at, and thumbnail_metadata fields to sermons table
  - Ensure migration follows existing naming conventions and patterns
  - _Requirements: 1.1, 2.1, 5.1_

- [x] 2. Create ThumbnailResult data object
  - Implement using Spatie Laravel Data pattern consistent with existing codebase
  - Include success, thumbnailPath, errorMessage, and metadata properties
  - Add static factory methods for success and skipped states
  - _Requirements: 1.1, 3.1_

- [x] 3. Create thumbnail generation configuration file
  - Create config/thumbnail-generation.php following existing config patterns
  - Include FFmpeg settings, storage configuration, overlay settings, and queue configuration
  - Use environment variables for key settings like enabled status and storage disk
  - _Requirements: 2.1, 3.1, 4.1_

- [x] 4. Implement core ThumbnailGenerationService
  - Create service class with dependency injection for VideoSegmentationService and Intervention/Image
  - Implement frame extraction using existing FFmpeg patterns from VideoSegmentationService
  - Add intelligent timestamp calculation based on video duration requirements
  - Include proper error handling that never throws exceptions
  - Follow existing PageImageService patterns for image processing and storage
  - _Requirements: 1.1, 4.1, 4.2, 4.3_

- [x] 5. Implement overlay generation functionality
  - Add overlay creation methods using existing Intervention/Image package following PageImageService patterns
  - Implement responsive text sizing and positioning logic using Intervention/Image text methods
  - Add church branding overlay with proper positioning to avoid text overlap
  - Use Oswald font with white background for accessibility using Intervention/Image text features
  - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [x] 6. Create GenerateThumbnail job class
  - Implement Laravel job following existing job patterns in codebase
  - Set single attempt (tries = 1) and 5-minute timeout
  - Include proper error handling that logs warnings but never fails processing
  - Add job to dedicated thumbnails queue for non-critical work
  - _Requirements: 1.1, 3.1, 3.3_

- [x] 7. Extend Sermon model with thumbnail functionality
  - Add getThumbnailUrlAttribute accessor following existing getAudioUrlAttribute pattern
  - Implement hasThumbnail method following existing hasTranscript pattern
  - Add withThumbnail scope following existing scope patterns
  - Ensure all new methods handle nullable thumbnail_path gracefully
  - _Requirements: 1.2, 5.1, 5.2_

- [x] 8. Integrate thumbnail generation with VideoProcessingService
  - Add GenerateThumbnail job dispatch to processDirectly method after sermon creation
  - Integrate GenerateThumbnail job into existing job chain for processWithSegmentation
  - Ensure thumbnail generation never blocks main processing pipeline
  - Pass correct video path and sermon ID to thumbnail job
  - _Requirements: 1.1, 3.1, 3.2_

- [x] 9. Update API responses to include thumbnail URLs
  - Modify sermon API resources to include thumbnail_url field
  - Ensure thumbnail URLs are optional and handle missing thumbnails gracefully
  - Add proper HTTP caching headers for thumbnail endpoints
  - Include thumbnail status in processing status responses when available
  - _Requirements: 5.1, 5.2, 6.1, 6.2_



- [x] 10. Implement Open Graph meta tags for social media sharing
  - Add thumbnail URLs to Open Graph meta tags in sermon pages
  - Ensure proper fallback when thumbnails are not available
  - Include proper aspect ratio and quality optimization for social media
  - Test with major social media platforms for proper thumbnail display
  - _Requirements: 6.1, 6.2_

- [x] 11. Create comprehensive test suite
  - Write unit tests for ThumbnailGenerationService covering frame extraction and overlay generation
  - Create tests for Sermon model thumbnail methods and URL generation
  - Add integration tests for thumbnail generation in video processing pipeline
  - Write feature tests for API endpoints including thumbnail URLs
  - Test error scenarios to ensure they don't break main processing
  - _Requirements: 1.1, 2.1, 3.3, 5.1_