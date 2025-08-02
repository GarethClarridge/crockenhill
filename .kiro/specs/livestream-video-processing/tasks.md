# Implementation Plan

## 1. Configuration and Infrastructure Setup

- [x] 1.1 Create livestream processing configuration file
  - Create `config/livestream-processing.php` with RMS thresholds, file size limits, FFmpeg paths, and storage settings
  - Add environment variables for configuration values
  - Include supported video formats and processing parameters
  - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [x] 1.2 Add required Composer dependencies
  - Add `php-ffmpeg/php-ffmpeg` package for video processing
  - Add any additional video processing dependencies
  - Update composer.json and run composer install
  - _Requirements: 2.1, 2.2, 4.1_

- [x] 1.3 Create database migrations for livestream processing
  - Create `livestream_processing_logs` table migration with processing_id, status, file paths, and metadata
  - Create `livestream_segments` table migration for storing segment data
  - Add livestream-related columns to existing `sermons` table (livestream_processing_id, video_file_path, source_type, segment timing)
  - _Requirements: 7.1, 7.2, 6.1_

## 2. Core Data Models and DTOs

- [x] 2.1 Create LivestreamProcessingLog model
  - Generate Eloquent model with relationships to Sermon and segments
  - Add status enums and helper methods for status management
  - Include scopes for filtering by status and processing stages
  - _Requirements: 6.1, 8.1_

- [x] 2.2 Create LivestreamSegment model
  - Generate Eloquent model for segment data storage
  - Add relationships to processing log and classification methods
  - Include methods for segment duration and timing calculations
  - _Requirements: 2.4, 2.5, 3.1_

- [x] 2.3 Create data transfer objects for livestream processing
  - Create `LivestreamSegment` DTO for segment data using Spatie Laravel Data
  - Create `LivestreamProcessingResult` DTO for API responses
  - Create `LivestreamProcessingStatus` DTO for status tracking
  - _Requirements: 1.4, 6.1_

## 3. Video Processing Services

- [x] 3.1 Create VideoSegmentationService
  - Implement FFmpeg integration for RMS analysis following ClipLongestQuietSection.py logic
  - Add methods for parsing audio sections with accurate pts_time timestamps
  - Implement segment classification logic (song vs speech based on RMS threshold)
  - Add method to identify longest speech section as sermon candidate
  - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1_

- [x] 3.2 Create VideoStorageService
  - Implement video file storage using Laravel Storage facade
  - Add methods for extracting video segments with original quality preservation
  - Implement sermon video storage with metadata organization
  - Add cleanup methods for temporary files
  - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 3.3 Create LivestreamProcessingService
  - Implement main orchestration service for job chain dispatch
  - Add processing status tracking and retrieval methods
  - Implement error handling and retry logic
  - Add integration with existing sermon processing pipeline
  - _Requirements: 1.1, 6.1, 7.1, 8.1_

## 4. Background Job Processing Chain

- [x] 4.1 Create GenerateRmsLog job
  - Implement FFmpeg command execution for RMS analysis
  - Add file validation for video formats and size limits
  - Include error handling for FFmpeg failures
  - Update processing status to 'processing'
  - _Requirements: 1.2, 1.3, 2.1, 2.6_

- [x] 4.2 Create AnalyzeSegments job
  - Parse RMS log following ClipLongestQuietSection.py logic with accurate timing
  - Identify and classify segments as song or speech
  - Store segment data in database
  - Find longest speech section as sermon candidate
  - _Requirements: 2.2, 2.3, 2.4, 2.5, 3.1, 3.6_

- [x] 4.3 Create ExtractSermon job
  - Extract sermon video segment preserving original quality
  - Convert sermon audio to MP3 format for processing pipeline
  - Store both video and audio files appropriately
  - Handle cases where no clear sermon section is identified
  - _Requirements: 3.1, 3.2, 3.3, 3.6, 4.1, 4.2_

- [x] 4.4 Create SubmitToProcessing job
  - Submit sermon audio to existing automated sermon processing endpoint
  - Track processing status and link to original livestream
  - Store sermon video with extracted metadata
  - Handle sermon processing failures gracefully
  - _Requirements: 3.4, 3.5, 4.3, 4.4, 7.1, 7.5_

- [x] 4.5 Create CleanupTemporaryFiles job
  - Clean up temporary RMS logs and processing files
  - Remove extracted audio and video segments
  - Ensure reliable cleanup after both success and failure
  - Implement configurable retention policies
  - _Requirements: 4.6, 8.5_

## 5. API Controllers and Routes

- [x] 5.1 Create LivestreamProcessingController
  - Implement POST /api/livestreams/process endpoint for video uploads
  - Add file validation for video formats and size limits
  - Return processing ID and status URL in response
  - Include proper error handling and validation responses
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

- [x] 5.2 Add status monitoring endpoint
  - Implement GET /api/livestreams/processing/{processingId}/status endpoint
  - Return detailed processing status with segment information
  - Include progress percentage and current step information
  - Add sermon processing status and video path when available
  - _Requirements: 6.1, 6.2_

- [x] 5.3 Register API routes
  - Add routes to api.php with proper middleware
  - Include authentication and rate limiting
  - Add route model binding for processing ID lookup
  - _Requirements: 1.1, 6.1_

## 6. Integration with Existing Sermon Processing

- [x] 6.1 Extend Sermon model for livestream integration
  - Add livestream_processing_id, video_file_path, source_type columns to sermons table migration
  - Add segment_start_time and segment_end_time columns to sermons table migration
  - Create relationship to LivestreamProcessingLog in Sermon model
  - Add methods to identify livestream-sourced sermons in Sermon model
  - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 6.2 Create SermonMetadataIntegrationService
  - Implement video storage with sermon metadata integration
  - Link video files to sermon records after processing
  - Use AI-extracted metadata for file organization
  - Handle video storage on appropriate disk/storage system
  - _Requirements: 4.3, 4.4, 7.1, 7.2_

- [x] 6.3 Update existing sermon processing to handle livestream sources
  - Modify sermon processing pipeline to accept livestream context
  - Ensure proper metadata extraction and storage for video sermons
  - Add video file references to sermon records
  - _Requirements: 7.1, 7.5_

## 7. Administrative Interface Enhancements

- [x] 7.1 Create SermonVideoDisplayService
  - Add methods to retrieve sermon data with video information
  - Implement video preview data generation
  - Include livestream source information in sermon display
  - Add video URL generation for administrative access
  - _Requirements: 4.5, 7.3, 7.4_

- [x] 7.2 Update sermon management views
  - Add video file display to sermon detail pages
  - Show livestream processing information and segment data
  - Include indicators for livestream vs manual sermon creation
  - Add access to original livestream files and processing logs
  - _Requirements: 7.3, 7.4_

## 8. Error Handling and Logging

- [x] 8.1 Create LivestreamProcessingLogger
  - Implement comprehensive logging for all processing steps
  - Add structured logging with processing context
  - Include performance metrics and memory usage tracking
  - Generate detailed processing reports
  - _Requirements: 8.1, 8.4_

- [x] 8.2 Implement error handling strategies
  - Add graceful degradation for segmentation failures
  - Implement retry logic with exponential backoff
  - Handle partial success scenarios appropriately
  - Add manual review workflow for failed processing
  - _Requirements: 2.6, 3.6, 4.7, 8.2, 8.3_

- [x] 8.3 Add notification system for processing events
  - Send email notifications for processing failures
  - Include detailed error context and troubleshooting information
  - Notify administrators of successful processing completion
  - Add configurable notification preferences
  - _Requirements: 6.2, 6.3, 8.2_

## 9. Health Checks and Monitoring

- [x] 9.1 Create custom health checks
  - Add FFmpegHealthCheck for FFmpeg availability
  - Create LivestreamQueueHealthCheck for queue monitoring
  - Add StorageSpaceHealthCheck for video storage monitoring
  - Integrate with existing health check system
  - _Requirements: 8.1, 8.5_

- [x] 9.2 Add processing metrics and monitoring
  - Track processing success rates and failure types
  - Monitor average processing times by video duration
  - Add storage usage metrics and retention policy effectiveness
  - Include queue health and worker status monitoring
  - _Requirements: 6.1, 8.1_

## 10. Testing and Quality Assurance

- [x] 10.1 Create unit tests for video processing services
  - Test RMS parsing with various audio patterns
  - Verify segment identification accuracy
  - Mock FFmpeg commands and test error handling
  - Test configuration validation and defaults
  - _Requirements: 2.1, 2.2, 2.3, 5.1_

- [x] 10.2 Create integration tests for processing pipeline
  - Test end-to-end processing with sample video files
  - Verify sermon processing integration with metadata extraction
  - Test file storage, organization, and cleanup
  - Validate database integration and record relationships
  - _Requirements: 3.1, 3.4, 4.1, 7.1_

- [x] 10.3 Create API endpoint tests
  - Test video upload endpoint with various file types
  - Verify status monitoring endpoint responses
  - Test error handling and validation responses
  - Include authentication and rate limiting tests
  - _Requirements: 1.1, 1.2, 6.1_

- [x] 10.4 Performance and load testing
  - Test with multi-hour livestream recordings
  - Verify concurrent processing capabilities
  - Monitor memory usage during processing
  - Test storage space management and cleanup
  - _Requirements: 8.5_

## 11. Documentation and Deployment

- [x] 11.1 Create API documentation
  - Document livestream processing endpoints
  - Include request/response examples
  - Add error code documentation
  - Create integration guide for external systems
  - _Requirements: 1.1, 6.1_

- [x] 11.2 Update deployment documentation
  - Add FFmpeg installation requirements
  - Document configuration options and environment variables
  - Include storage requirements and recommendations
  - Add monitoring and alerting setup instructions
  - _Requirements: 5.1, 8.1_

- [x] 11.3 Create operational runbooks
  - Document troubleshooting procedures for common issues
  - Add manual recovery procedures for failed processing
  - Include performance tuning guidelines
  - Create backup and disaster recovery procedures
  - _Requirements: 8.2, 8.3_