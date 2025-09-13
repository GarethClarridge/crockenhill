# Unified Media Processing API Documentation

## Overview

The Unified Media Processing API provides endpoints for uploading and processing sermon audio files, sermon videos, and full livestream recordings. The system uses intelligent routing to direct different media types to appropriate processing services while maintaining consistent API responses.

## Base URL

```
https://your-domain.com/api/sermons
```

## Authentication

All API endpoints require authentication using Laravel Sanctum tokens. Include the token in the Authorization header:

```
Authorization: Bearer {your-api-token}
```

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Media Upload**: 10 requests per hour per user
- **Video Upload**: 5 requests per hour per user  
- **Status/Management**: 60 requests per minute per user
- **Monitoring/Health**: 30 requests per minute per user

Rate limit headers are included in responses:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Unix timestamp when the rate limit resets

## Content Types

- **Request**: `multipart/form-data` for file uploads, `application/json` for other requests
- **Response**: `application/json`

---

## Media Upload Endpoints

### 1. Upload Audio Sermon

Upload an audio file for automated sermon processing.

**Endpoint**: `POST /api/sermons/audio`

**Authentication**: Required

**Content-Type**: `multipart/form-data`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | File | Yes | Audio file (MP3, WAV, M4A, MP4) |

#### File Requirements

- **Supported formats**: MP3, WAV, M4A, MP4
- **Maximum file size**: 100MB
- **Content**: Must contain clear speech for transcription

#### Response Format

**Success Response (HTTP 202):**

```json
{
  "success": true,
  "message": "Sermon processing initiated successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status",
  "estimated_completion": "2024-01-15T10:30:00Z"
}
```

**Error Response (HTTP 422):**

```json
{
  "success": false,
  "message": "The file field is required.",
  "errors": {
    "file": ["The file field is required."]
  }
}
```

### 2. Upload Sermon Video

Upload a video file containing sermon-only content for direct processing (no segmentation required).

**Endpoint**: `POST /api/sermons/video`

**Authentication**: Required

**Content-Type**: `multipart/form-data`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | File | Yes | Video file (MP4, MOV, AVI, MKV) |

#### File Requirements

- **Supported formats**: MP4, MOV, AVI, MKV
- **Maximum file size**: 100MB (configurable)
- **Content**: Should contain sermon-only content (entire video will be processed)
- **Audio track**: Must contain clear speech for transcription

#### Response Format

**Success Response (HTTP 202):**

```json
{
  "success": true,
  "message": "Sermon processing initiated successfully", 
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status"
}
```

### 3. Upload Livestream Recording

Upload a full livestream video file for automated segmentation and sermon extraction.

**Endpoint**: `POST /api/sermons/livestream`

**Authentication**: Required

**Content-Type**: `multipart/form-data`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | File | Yes | Video file (MP4, MOV, AVI, MKV, WEBM) |

#### File Requirements

- **Supported formats**: MP4, MOV, AVI, MKV, WEBM
- **Maximum file size**: 2GB
- **Content**: Full service recording with music and speech segments
- **Audio track**: Must contain audio for RMS analysis and segmentation

#### Processing Pipeline

1. **RMS Analysis**: Audio level analysis to identify music vs speech
2. **Segmentation**: Automatic classification of video segments  
3. **Sermon Extraction**: FFmpeg-based extraction of sermon portions
4. **Audio Processing**: Transcription and AI analysis
5. **Video Preservation**: Original and extracted videos are preserved

#### Response Format

**Success Response (HTTP 202):**

```json
{
  "success": true,
  "message": "Livestream processing initiated successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000", 
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status",
  "estimated_completion": "2024-01-15T11:00:00Z"
}
```

---

## Processing Management Endpoints

### 4. Get Processing Status

Check the status of any processing operation using its processing ID.

**Endpoint**: `GET /api/sermons/processing/{processingId}/status`

**Authentication**: Required

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "processing",
  "current_step": "transcription",
  "progress_percentage": 75,
  "started_at": "2024-01-15T10:00:00Z",
  "updated_at": "2024-01-15T10:15:00Z",
  "estimated_completion": "2024-01-15T10:30:00Z"
}
```

**With Sermon Result (HTTP 200):**

```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "completed", 
  "current_step": "completed",
  "progress_percentage": 100,
  "sermon_id": 123,
  "sermon_url": "/christ/sermons/gods-providence-in-our-lives",
  "started_at": "2024-01-15T10:00:00Z",
  "updated_at": "2024-01-15T10:30:00Z"
}
```

**Not Found Response (HTTP 404):**

```json
{
  "found": false,
  "message": "Processing record not found"
}
```

#### Status Values

- `pending`: Processing queued but not yet started
- `processing`: Currently being processed
- `transcription`: Audio transcription in progress
- `ai_analysis`: AI metadata extraction in progress
- `completed`: Processing completed successfully
- `failed`: Processing failed with error

#### Processing Steps (Livestream)

- `queued`: Waiting in processing queue
- `rms_generation`: Analyzing audio levels
- `segment_analysis`: Identifying music/speech segments
- `sermon_extraction`: Extracting sermon portions
- `sermon_processing`: Transcribing and analyzing extracted content
- `completed`: All processing complete

### 5. Cancel Processing

Cancel an in-progress processing operation.

**Endpoint**: `DELETE /api/sermons/processing/{processingId}`

**Authentication**: Required

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "success": true,
  "message": "Processing cancelled successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Error Response (HTTP 400):**

```json
{
  "success": false,
  "message": "Cannot cancel completed processing",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

### 6. Retry Failed Processing

Retry a failed processing operation.

**Endpoint**: `POST /api/sermons/processing/{processingId}/retry`

**Authentication**: Required

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "success": true,
  "message": "Processing retry initiated",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status"
}
```

### 7. Graceful Degradation

Attempt to salvage partially processed content from failed processing.

**Endpoint**: `POST /api/sermons/processing/{processingId}/graceful-degradation`

**Authentication**: Required

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "success": true,
  "message": "Graceful degradation applied successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "sermon_id": 123,
  "note": "Partial sermon record created with available data"
}
```

---

## Monitoring Endpoints

### 8. Processing Statistics

Get system-wide processing statistics.

**Endpoint**: `GET /api/sermons/processing/statistics`

**Authentication**: Required

#### Response Format

```json
{
  "total_processing_requests": 1234,
  "pending": 5,
  "processing": 3, 
  "completed": 1200,
  "failed": 26,
  "success_rate": 97.89,
  "average_processing_time": "00:04:32"
}
```

### 9. Failed Processing Records

Get list of failed processing operations.

**Endpoint**: `GET /api/sermons/processing/failed`

**Authentication**: Required

#### Response Format

```json
{
  "failed_processing": [
    {
      "processing_id": "550e8400-e29b-41d4-a716-446655440000",
      "original_filename": "sermon-2024-01-15.mp3",
      "failed_at": "2024-01-15T10:30:00Z",
      "error_message": "Transcription service unavailable",
      "retry_available": true
    }
  ],
  "total_count": 26
}
```

### 10. System Health

Check system health and service availability.

**Endpoint**: `GET /api/sermons/processing/health`

**Authentication**: Required  

#### Response Format

```json
{
  "status": "healthy",
  "services": {
    "transcription": "available",
    "ai_analysis": "available", 
    "file_storage": "available",
    "queue_workers": "running"
  },
  "system": {
    "ffmpeg_available": true,
    "storage_space": "87% available",
    "queue_backlog": 2
  }
}
```

---

## Legacy Endpoints

### Livestream Processing (Legacy)

For backwards compatibility, the original livestream processing endpoint remains available:

**Endpoint**: `POST /api/livestreams/process`

**Note**: This endpoint bypasses the ProcessingRouter and goes directly to VideoProcessingService. For new integrations, use `/api/sermons/livestream` instead.

---

## Error Handling

### Common Error Responses

**Validation Error (HTTP 422):**
```json
{
  "success": false,
  "message": "The file field is required.",
  "errors": {
    "file": ["The file field is required."]
  }
}
```

**File Too Large (HTTP 413):**
```json
{
  "success": false,
  "message": "File size exceeds maximum limit of 100MB for video processing",
  "error_code": "FILE_TOO_LARGE"
}
```

**Unsupported Format (HTTP 422):**
```json
{
  "success": false, 
  "message": "File extension 'avi' not allowed for audio. Allowed: mp3, wav, m4a, mp4",
  "error_code": "UNSUPPORTED_FORMAT"
}
```

**Processing Failure (HTTP 500):**
```json
{
  "success": false,
  "message": "An unexpected error occurred during processing",
  "error_code": "PROCESSING_FAILED"
}
```

### Error Codes

- `INVALID_FILE`: Uploaded file is corrupted or invalid
- `FILE_TOO_LARGE`: File exceeds maximum size limit
- `UNSUPPORTED_FORMAT`: File format not supported for processing type
- `PROCESSING_FAILED`: General processing failure
- `TRANSCRIPTION_FAILED`: Transcription service error
- `AI_ANALYSIS_FAILED`: AI metadata extraction error
- `STORAGE_ERROR`: File storage or retrieval error

## Integration Examples

### Upload Audio File (JavaScript)

```javascript
const formData = new FormData();
formData.append('file', audioFile);

const response = await fetch('/api/sermons/audio', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer ' + apiToken
  },
  body: formData
});

const result = await response.json();
if (result.success) {
  console.log('Processing ID:', result.processing_id);
  // Poll status endpoint for updates
}
```

### Check Processing Status (JavaScript)

```javascript
const checkStatus = async (processingId) => {
  const response = await fetch(`/api/sermons/processing/${processingId}/status`, {
    headers: {
      'Authorization': 'Bearer ' + apiToken
    }
  });
  
  const status = await response.json();
  return status;
};

// Poll every 10 seconds
const pollStatus = (processingId) => {
  const interval = setInterval(async () => {
    const status = await checkStatus(processingId);
    
    if (status.status === 'completed') {
      clearInterval(interval);
      console.log('Sermon created:', status.sermon_url);
    } else if (status.status === 'failed') {
      clearInterval(interval); 
      console.error('Processing failed:', status.error_message);
    }
  }, 10000);
};
```

This unified API provides a consistent interface for all media processing needs while maintaining backwards compatibility with existing integrations.