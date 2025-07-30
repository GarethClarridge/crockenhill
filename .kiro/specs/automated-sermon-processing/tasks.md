# Implementation Plan

- [x] 1. Set up project dependencies and configuration
  - Install required Composer packages using Laravel Sail (openai-php/laravel, spatie/laravel-data)
  - Create sermon processing configuration file
  - Set up environment variables for OpenAI API integration
  - _Requirements: 1.1, 7.2_

- [x] 2. Create database migrations
- [x] 2.1 Create migration for processing logs
  - Create migration for sermon_processing_logs table
  - Add foreign key relationship to sermons table
  - Run migration to update database schema
  - _Requirements: 8.1, 8.3_

- [x] 2.2 Add transcript_path column to sermons table
  - Create migration to add transcript_path column to sermons table
  - Set column as nullable string to store transcript file path
  - Update existing sermon records to have null transcript_path
  - _Requirements: 6.1, 6.3_

- [x] 3. Implement Data Transfer Objects using Spatie Laravel Data
- [x] 3.1 Create SermonMetadata DTO
  - Define SermonMetadata class with Carbon date, SermonService enum, filename, and originalName properties
  - Add validation rules for each property
  - Implement factory methods for creating from UploadedFile
  - _Requirements: 2.1, 2.2, 2.6_

- [x] 3.2 Create SermonAnalysis DTO
  - Define SermonAnalysis class with title, series, reference, points, and transcript properties
  - Add validation for title length (max 12 words)
  - Implement methods for data transformation and validation
  - _Requirements: 4.1, 4.2, 5.1, 6.4_

- [x] 4. Create SermonProcessingLog model
  - Generate Eloquent model for sermon_processing_logs table
  - Define fillable fields and relationships
  - Add status enum casting and helper methods
  - Create factory for testing
  - _Requirements: 8.1, 8.3_

- [x] 5. Implement metadata extraction service
- [x] 5.1 Create MetadataExtractionService class
  - Implement date extraction from filename using regex patterns
  - Add service type determination from file creation time (AM/PM logic)
  - Handle edge cases and validation errors
  - Write unit tests for various filename formats
  - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.6_

- [x] 5.2 Integrate with existing GetID3 library
  - Extend metadata extraction to use existing GetID3 functionality
  - Extract additional audio file information (duration, bitrate)
  - Add validation for audio file format and quality
  - _Requirements: 1.2, 1.3_

- [x] 6. Implement audio transcription service
- [x] 6.1 Create AudioTranscriptionService class
  - Implement OpenAI Whisper API integration
  - Add file upload handling with chunking for large files
  - Implement retry logic with exponential backoff
  - Add transcript validation and formatting
  - _Requirements: 3.1, 3.3, 3.4_

- [x] 6.2 Add transcript storage functionality
  - Implement Markdown file storage in storage/app/transcripts/
  - Create transcript file naming convention using sermon_id
  - Add file cleanup on processing failure
  - Implement transcript retrieval methods
  - _Requirements: 3.2, 6.3_

- [x] 7. Implement comprehensive sermon analysis service
- [x] 7.1 Create SermonAnalysisService class
  - Implement OpenAI GPT API integration for comprehensive content analysis
  - Create structured prompt to extract title, series, Bible passage, and sermon headings in single API call
  - Add series identification logic using existing database series
  - Use AI to identify the main Bible passage being discussed (not just text matching)
  - Implement JSON response parsing for all analysis results
  - Add fallback handling for AI processing failures with graceful degradation
  - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 5.1, 5.2, 5.3, 5.4, 6.2, 6.4, 6.5, 6.6_

- [x] 8. Create queue job classes for processing chain
- [x] 8.1 Create CreateSermonRecord job
  - Implement job to create initial sermon record with 'processing' status
  - Set default preacher to 'Mark Drury'
  - Generate initial slug and handle metadata
  - Create processing log entry
  - _Requirements: 2.5, 7.1, 8.1_

- [x] 8.2 Create TranscribeAudio job
  - Implement job to handle audio transcription using AudioTranscriptionService
  - Add error handling and retry logic
  - Update processing log with current step
  - Store transcript file with sermon_id reference
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 8.3 Create ProcessTranscriptWithAI job
  - Implement comprehensive AI analysis job using single OpenAI GPT API call
  - Create structured prompt to extract title, series, Bible passage, and sermon headings in one request
  - Parse JSON response containing all analysis results
  - Handle AI processing failures with graceful degradation and fallback values
  - Update processing log with complete analysis results
  - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 5.1, 5.2, 5.3, 5.4, 6.2, 6.4, 6.5, 6.6_

- [x] 8.5 Create UpdateSermonRecord job
  - Implement job to finalize sermon record with all processed data
  - Update sermon status from 'processing' to 'completed'
  - Generate final slug from AI-generated title
  - Ensure compatibility with existing sermon display features
  - _Requirements: 7.1, 7.2, 8.3_

- [x] 8.6 Create SendCompletionNotification job
  - Implement job to notify administrators of processing completion
  - Send email or system notification with processing results
  - Include links to newly created sermon and any manual review items
  - Update final processing log status
  - _Requirements: 7.4, 8.1_

- [x] 9. Implement main processing orchestration service
- [x] 9.1 Create SermonProcessingService class
  - Implement main orchestration logic for job chain dispatch
  - Add processing status tracking and retrieval methods
  - Create job chain with proper error handling
  - Implement processing ID generation and management
  - _Requirements: 7.1, 7.3, 8.1, 8.2_

- [x] 9.2 Add error handling and recovery logic
  - Implement comprehensive error handling for each processing step
  - Add graceful degradation with fallback values
  - Create manual review queue for failed processing
  - Implement detailed logging for troubleshooting
  - _Requirements: 7.3, 8.2, 8.4_

- [x] 10. Create API controller and routes
- [x] 10.1 Create AutomatedSermonController
  - Implement POST endpoint for automated sermon upload
  - Add file validation and security checks
  - Create processing initiation logic
  - Implement processing status endpoint
  - _Requirements: 1.1, 1.2, 1.3, 1.4_

- [x] 10.2 Add API routes and middleware
  - Define API routes for automated sermon processing
  - Add authentication and rate limiting middleware
  - Implement CORS configuration for API access
  - Add input validation and security measures
  - _Requirements: 1.1, 7.2_

- [x] 11. Extend Sermon model for automated processing
- [x] 11.1 Add transcript functionality to Sermon model
  - Create accessor method to read transcript file content using stored transcript_path
  - Add fillable transcript_path field to model
  - Implement transcript file existence checking
  - Maintain backward compatibility with existing functionality
  - _Requirements: 6.1, 6.3, 7.2_

- [x] 11.2 Add processing status tracking
  - Add methods to identify automated vs manual sermon creation
  - Implement status checking for processing completion
  - Add relationship to SermonProcessingLog model
  - Create scopes for filtering automated sermons
  - _Requirements: 8.3, 8.4_

- [x] 12. Implement comprehensive testing suite
- [x] 12.1 Create unit tests for services
  - Write tests for MetadataExtractionService with various filename formats
  - Test AudioTranscriptionService with mocked API responses
  - Create tests for SermonAnalysisService with sample transcripts
  - Test error handling and edge cases for all services
  - _Requirements: 2.1, 2.2, 3.1, 4.1, 5.1_

- [x] 12.2 Create integration tests for job chain
  - Test complete processing pipeline with sample audio files
  - Verify database record creation and updates
  - Test job chain error handling and recovery
  - Validate file storage and cleanup operations
  - _Requirements: 7.1, 7.2, 8.1, 8.3_

- [x] 12.3 Create API endpoint tests
  - Test automated sermon upload endpoint with various file types
  - Verify authentication and rate limiting functionality
  - Test processing status retrieval
  - Validate error responses and edge cases
  - _Requirements: 1.1, 1.2, 1.3, 1.4_

- [x] 13. Implement health monitoring and logging
- [x] 13.1 Create custom health checks
  - Implement OpenAI API connectivity health check
  - Add sermon processing queue health monitoring
  - Create storage accessibility health check
  - Integrate with Laravel 12 health check system
  - _Requirements: 8.1, 8.2_

- [x] 13.2 Add comprehensive logging
  - Implement detailed logging for all processing steps
  - Add performance metrics tracking
  - Create error logging with context information
  - Add processing statistics and monitoring
  - _Requirements: 8.1, 8.2, 8.4_

- [x] 14. Create documentation and deployment preparation
- [x] 14.1 Create API documentation
  - Document automated sermon upload endpoint
  - Create example requests and responses
  - Document error codes and handling
  - Add authentication and rate limiting information
  - _Requirements: 1.1, 7.3_

- [x] 14.2 Create deployment configuration
  - Set up environment variables for production
  - Configure queue workers for background processing
  - Set up monitoring and alerting for processing failures
  - Create backup and recovery procedures for transcript files
  - _Requirements: 7.1, 8.1, 8.2_