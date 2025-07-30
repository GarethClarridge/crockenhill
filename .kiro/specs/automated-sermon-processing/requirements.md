# Requirements Document

## Introduction

This feature extends the existing Sermons functionality by adding an automated processing capability that accepts MP3 files via API and uses AI to extract metadata, transcribe content, and generate structured sermon information without manual data entry.

## Requirements

### Requirement 1

**User Story:** As a system administrator, I want to upload sermon audio files via API, so that sermons can be processed automatically without manual form entry.

#### Acceptance Criteria

1. WHEN a POST request is made to the sermon upload endpoint with an MP3 file THEN the system SHALL accept the file and initiate automated processing
2. WHEN the MP3 file is received THEN the system SHALL validate it is a valid audio file format
3. IF the file is invalid THEN the system SHALL return an appropriate error response
4. WHEN the file is valid THEN the system SHALL store it securely and return a processing confirmation

### Requirement 2

**User Story:** As a system administrator, I want sermon metadata extracted from the filename and file properties, so that basic information is captured automatically.

#### Acceptance Criteria

1. WHEN processing an MP3 file THEN the system SHALL extract the date from the filename
2. WHEN the file creation time is available THEN the system SHALL determine if it's an AM or PM service based on creation timestamp
3. IF the creation time indicates morning hours THEN the system SHALL set service type to AM
4. IF the creation time indicates evening hours THEN the system SHALL set service type to PM
5. WHEN creating a sermon record THEN the system SHALL set the preacher field to 'Mark Drury' by default
6. WHEN metadata extraction fails THEN the system SHALL log the error and use default values

### Requirement 3

**User Story:** As a content manager, I want sermon audio automatically transcribed to text, so that accurate transcripts are available for each sermon.

#### Acceptance Criteria

1. WHEN an MP3 file is processed THEN the system SHALL transcribe the audio to text using AI transcription services
2. WHEN transcription is complete THEN the system SHALL store the transcript in Markdown format
3. WHEN transcription fails THEN the system SHALL log the error and mark the sermon for manual review
4. WHEN the transcript is generated THEN the system SHALL validate it contains meaningful content

### Requirement 4

**User Story:** As a content manager, I want AI to generate sermon titles and identify series information, so that sermons are properly categorized without manual input.

#### Acceptance Criteria

1. WHEN a transcript is available THEN the system SHALL use AI to generate an appropriate sermon title
2. WHEN generating a title THEN the system SHALL ensure it contains no more than 12 words
3. WHEN analyzing the transcript THEN the system SHALL identify which existing sermon series the content belongs to from the database
4. IF no existing series matches THEN the system SHALL set the series field to null
5. WHEN AI processing is complete THEN the system SHALL store the generated title and series information
6. IF AI processing fails THEN the system SHALL use fallback values and mark for manual review

### Requirement 5

**User Story:** As a content manager, I want the main Bible passage automatically identified, so that scripture references are captured accurately.

#### Acceptance Criteria

1. WHEN analyzing the sermon transcript THEN the system SHALL use AI to identify the primary Bible passage being discussed in the sermon
2. WHEN multiple passages are referenced THEN the system SHALL determine the main sermon text based on context and emphasis rather than frequency alone
3. WHEN a Bible passage is identified THEN the system SHALL store it in the standard format used by the system
4. IF no clear Bible passage is identified THEN the system SHALL mark the sermon for manual review

### Requirement 6

**User Story:** As a website visitor, I want to view sermon transcripts with structured headings, so that I can easily navigate the sermon content.

#### Acceptance Criteria

1. WHEN displaying a sermon page THEN the system SHALL show the transcript with auto-generated headings
2. WHEN generating headings THEN the system SHALL identify main sermon points and create appropriate section headers
3. WHEN the transcript is displayed THEN the system SHALL format it as readable Markdown content
4. WHEN headings are generated THEN the system SHALL ensure they reflect the sermon's structure and main points
5. WHEN headings are generated THEN the system SHALL store them in the existing Points field for separate display
6. WHEN the Points field is populated THEN the system SHALL maintain compatibility with existing sermon point display functionality

### Requirement 7

**User Story:** As a system administrator, I want automated processing to integrate with existing sermon management, so that processed sermons appear alongside manually created ones.

#### Acceptance Criteria

1. WHEN automated processing is complete THEN the system SHALL create a sermon record in the existing database structure
2. WHEN the sermon is created THEN the system SHALL maintain compatibility with existing sermon display and management features
3. WHEN processing fails at any stage THEN the system SHALL provide clear error messages and logging
4. WHEN a sermon is successfully processed THEN the system SHALL notify administrators of completion

### Requirement 8

**User Story:** As a system administrator, I want to monitor automated processing status, so that I can track success rates and handle failures.

#### Acceptance Criteria

1. WHEN automated processing runs THEN the system SHALL log all processing steps and outcomes
2. WHEN processing fails THEN the system SHALL provide detailed error information for troubleshooting
3. WHEN processing is complete THEN the system SHALL update the sermon status to indicate automated vs manual creation
4. WHEN multiple files are processed THEN the system SHALL provide batch processing status information