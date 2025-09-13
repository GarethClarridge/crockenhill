# Requirements Document

## Introduction

This feature adds automated thumbnail generation to the existing media processing system. The system will generate thumbnails for sermon videos with title, date, and church branding overlays to improve the browsing experience.

## Requirements

### Requirement 1

**User Story:** As a church member browsing sermons, I want to see thumbnails for each sermon, so that I can quickly identify sermons of interest.

#### Acceptance Criteria

1. WHEN a sermon video is processed THEN the system SHALL generate a thumbnail image automatically
2. WHEN displaying sermon lists THEN the system SHALL show thumbnail images for each sermon
3. WHEN thumbnail generation fails THEN the system SHALL not display a thumbnail for that sermon

### Requirement 2

**User Story:** As a church administrator, I want thumbnails to include sermon metadata and branding, so that they are informative and visually consistent.

#### Acceptance Criteria

1. WHEN generating a thumbnail THEN the system SHALL overlay the sermon title with text wrapping
2. WHEN generating a thumbnail THEN the system SHALL include the formatted service date
3. WHEN generating a thumbnail THEN the system SHALL include church branding without overlapping text
4. WHEN generating overlays THEN the system SHALL use Oswald font in black with white backgrounds for accessibility

### Requirement 3

**User Story:** As a system administrator, I want thumbnail generation to integrate with existing processing pipelines, so that it doesn't disrupt current workflows.

#### Acceptance Criteria

1. WHEN processing videos THEN the system SHALL generate thumbnails after video processing completes
2. WHEN thumbnail generation occurs THEN it SHALL be tracked in processing status updates
3. WHEN thumbnail generation fails THEN it SHALL not prevent other processing steps from completing

### Requirement 4

**User Story:** As a church administrator, I want intelligent thumbnail extraction from videos, so that thumbnails represent the sermon content.

#### Acceptance Criteria

1. WHEN extracting thumbnails THEN the system SHALL extract from 60 seconds into the video up to 60 seconds before the end
2. WHEN extracting thumbnails THEN the system SHALL generate at the same resolution as the video

### Requirement 5

**User Story:** As a developer integrating with the API, I want thumbnail URLs included in sermon data, so that external applications can display thumbnails.

#### Acceptance Criteria

1. WHEN retrieving sermon data via API THEN the response SHALL include thumbnail URLs
2. WHEN requesting thumbnails via API THEN the system SHALL serve optimized images with proper caching headers

### Requirement 6

**User Story:** As a church member sharing sermons on social media, I want thumbnails to enhance social media posts, so that shared content is more engaging.

#### Acceptance Criteria

1. WHEN sharing sermon links THEN the system SHALL provide Open Graph meta tags with thumbnail URLs
2. WHEN thumbnails are displayed on social media THEN they SHALL maintain proper aspect ratios and quality