# Requirements Document

## Introduction

This feature extends the existing automated sermon processing capability by adding support for full livestream video files. The system will automatically segment livestream recordings into distinct sections (songs, prayers, sermon, etc.), identify the sermon portion, extract it as audio, and feed it into the existing automated sermon processing pipeline. Additionally, it will store the sermon video with the processed sermon.

## Requirements

### Requirement 1

**User Story:** As a system administrator, I want to upload full livestream video files via API, so that the entire service can be processed automatically without manual segmentation.

#### Acceptance Criteria

1. WHEN a POST request is made to the livestream processing endpoint with a video file THEN the system SHALL accept the file and initiate automated segmentation
2. WHEN the video file is received THEN the system SHALL validate it is a valid video file format (MP4, MOV, AVI, MKV)
3. IF the file is invalid THEN the system SHALL return an appropriate error response
4. WHEN the file is valid THEN the system SHALL store it securely and return a processing confirmation
5. WHEN processing begins THEN the system SHALL generate a unique processing ID for tracking

### Requirement 2

**User Story:** As a system administrator, I want the livestream automatically segmented into distinct sections, so that different parts of the service can be identified and processed separately.

#### Acceptance Criteria

1. WHEN processing a livestream video THEN the system SHALL analyze the audio track to identify volume patterns
2. WHEN analyzing audio THEN the system SHALL use RMS level analysis to distinguish between loud sections (music/singing) and quiet sections (speech/prayer)
3. WHEN segmenting THEN the system SHALL identify sections with configurable volume thresholds and minimum duration requirements
4. WHEN segmentation is complete THEN the system SHALL classify each section as either "Song" (above RMS threshold) or "Speech" (below RMS threshold)
5. WHEN sections are identified THEN the system SHALL store segment metadata including start time, end time, duration, and classification
6. IF segmentation fails THEN the system SHALL log the error and mark for manual review

### Requirement 3

**User Story:** As a content manager, I want the sermon section automatically identified and extracted, so that it can be processed through the existing automated sermon processing pipeline.

#### Acceptance Criteria

1. WHEN segmentation is complete THEN the system SHALL identify the longest speech section as the primary sermon
2. WHEN the sermon section is identified THEN the system SHALL extract the audio from that video segment
3. WHEN audio is extracted THEN the system SHALL convert it to MP3 format suitable for the existing sermon processing API
4. WHEN the MP3 is ready THEN the system SHALL automatically submit it to the existing automated sermon processing endpoint
5. WHEN sermon processing is initiated THEN the system SHALL track the processing status and link it to the original livestream
6. IF no clear sermon section is identified (no speech segments found or all speech segments are shorter than the minimum sermon duration) THEN the system SHALL mark for manual review with segment suggestions

### Requirement 4

**User Story:** As a content manager, I want the sermon video segment stored locally with the processed sermon, so that the video is available for future use and potential sharing.

#### Acceptance Criteria

1. WHEN the sermon section is identified THEN the system SHALL extract the video segment maintaining the original video quality and format
2. WHEN extracting the sermon video THEN the system SHALL preserve all original video properties including resolution, bitrate, and codec
3. WHEN the sermon video is extracted THEN the system SHALL store it in the sermon storage directory alongside the audio file
4. WHEN the sermon record is created THEN the system SHALL include a reference to the video file path
5. WHEN displaying sermon details THEN administrators SHALL be able to access both the audio and video files
6. WHEN storage space is limited THEN the system SHALL provide configurable retention policies for video files
7. IF video extraction fails THEN the system SHALL continue with audio-only processing and log the video extraction error

### Requirement 5

**User Story:** As a system administrator, I want to configure segmentation parameters, so that the system can be tuned for different recording conditions and service formats.

#### Acceptance Criteria

1. WHEN configuring the system THEN the administrator SHALL be able to set RMS volume thresholds for distinguishing music from speech
2. WHEN configuring the system THEN the administrator SHALL be able to set minimum section durations to avoid micro-segments
3. WHEN configuring the system THEN the administrator SHALL be able to set file retention policies for video storage
4. WHEN configuration changes are made THEN the system SHALL validate settings and provide feedback on invalid values

### Requirement 6

**User Story:** As a system administrator, I want to monitor livestream processing status, so that I can track success and handle failures.

#### Acceptance Criteria

1. WHEN livestream processing runs THEN the system SHALL provide status updates for each processing stage
2. WHEN processing fails THEN the system SHALL send email notification to administrators with error details
3. WHEN processing is complete THEN the system SHALL provide a summary including segment count and sermon processing status

### Requirement 7

**User Story:** As a system administrator, I want livestream processing to integrate seamlessly with existing sermon management, so that processed sermons appear alongside manually created ones.

#### Acceptance Criteria

1. WHEN sermon extraction and processing is complete THEN the system SHALL create a sermon record linked to the original livestream
2. WHEN the sermon record is created THEN the system SHALL include metadata about the source livestream and segment timing
3. WHEN displaying sermons THEN the system SHALL indicate which ones were processed from livestreams vs standalone audio files
4. WHEN managing sermons THEN administrators SHALL be able to access the original livestream file and segment data
5. WHEN sermon processing fails THEN the system SHALL still preserve the livestream and segmentation data for manual processing
6. WHEN multiple speech segments are identified THEN the system SHALL extract only the longest speech segment as the primary sermon (multiple sermon extraction is not supported in this version)

### Requirement 8

**User Story:** As a system administrator, I want comprehensive logging and error handling for livestream processing, so that issues can be diagnosed and resolved efficiently.

#### Acceptance Criteria

1. WHEN livestream processing runs THEN the system SHALL log all major processing steps with timestamps and duration
2. WHEN errors occur THEN the system SHALL capture detailed error information including stack traces and context
3. WHEN external services fail THEN the system SHALL implement appropriate retry logic with exponential backoff
4. WHEN processing is complete THEN the system SHALL generate a comprehensive processing report
5. WHEN storage issues occur THEN the system SHALL handle disk space and file permission errors gracefully
6. WHEN the system encounters unknown file formats THEN the system SHALL provide clear error messages and format requirements