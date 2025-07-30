# Automated sermon processing API documentation

## Overview

The Automated Sermon Processing API provides endpoints for uploading sermon audio files and automatically processing them using AI services. The system extracts metadata, transcribes audio content, and generates structured sermon information including titles, series identification, Bible passage references, and sermon points.

## Base URL

```
https://your-domain.com/api/sermons
```

## Authentication

All API endpoints require authentication using Laravel Sanctum tokens. Include the token in the Authorization header:

```
Authorization: Bearer {your-api-token}
```

## Rate limiting

The API implements rate limiting to prevent abuse:

- **Sermon Upload**: 10 requests per hour per user
- **Status/Retry/General**: 60 requests per minute per user
- **Statistics/Health**: 30 requests per minute per user

Rate limit headers are included in responses:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Unix timestamp when the rate limit resets

## Content types

- **Request**: `multipart/form-data` for file uploads, `application/json` for other requests
- **Response**: `application/json`

---

## Endpoints

### 1. Upload sermon for automated processing

Upload an audio file for automated sermon processing.

**Endpoint**: `POST /api/sermons/automated`

**Authentication**: Required

**Rate Limit**: `sermon-upload` (10 requests/hour)

**Request parameters**:

| Parameter | Type | Required | Description                     |
| --------- | ---- | -------- | ------------------------------- |
| `file`    | File | Yes      | Audio file (MP3, WAV, M4A, MP4) |

**File requirements**:
- **Maximum Size**: 100MB
- **Allowed Formats**: MP3, WAV, M4A, MP4
- **MIME Types**: `audio/mpeg`, `audio/mp3`, `audio/wav`, `audio/x-wav`, `audio/mp4`, `audio/m4a`

**Example Request**:
```bash
curl -X POST https://your-domain.com/api/sermons/automated \
  -H "Authorization: Bearer your-api-token" \
  -F "file=@sermon-2024-01-15-am.mp3"
```

**Success Response** (202 Accepted):
```json
{
  "success": true,
  "message": "Sermon processing initiated successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status",
  "estimated_completion": "2024-01-15T10:30:00Z"
}
```

**Error Responses**:

**400 Bad request** - Invalid file:
```json
{
  "success": false,
  "message": "Invalid or corrupted file uploaded",
  "error_code": "INVALID_FILE"
}
```

**422 Unprocessable entity** - Validation errors:
```json
{
  "success": false,
  "message": "The uploaded file must be one of the following types: mp3, wav, m4a, mp4.",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "file": [
      "The uploaded file must be one of the following types: mp3, wav, m4a, mp4."
    ]
  }
}
```

**429 Too many requests** - Rate limit exceeded:
```json
{
  "success": false,
  "message": "Too many upload requests. Please try again later.",
  "error_code": "RATE_LIMIT_EXCEEDED",
  "retry_after": 3600
}
```

**500 Internal server error** - Server error:
```json
{
  "success": false,
  "message": "An unexpected error occurred during upload processing",
  "error_code": "INTERNAL_ERROR"
}
```

---

### 2. Get processing status

Check the status of a sermon processing job.

**Endpoint**: `GET /api/sermons/processing/{processingId}/status`

**Authentication**: Required

**Rate Limit**: `api` (60 requests/minute)

**Path Parameters**:

| Parameter      | Type | Required | Description                        |
| -------------- | ---- | -------- | ---------------------------------- |
| `processingId` | UUID | Yes      | Processing ID returned from upload |

**Example Request**:
```bash
curl -X GET https://your-domain.com/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status \
  -H "Authorization: Bearer your-api-token"
```

**Success Response** (200 OK):
```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "processing",
  "current_step": "transcription",
  "progress_percentage": 45,
  "started_at": "2024-01-15T10:00:00Z",
  "estimated_completion": "2024-01-15T10:30:00Z",
  "sermon_id": null,
  "error_message": null,
  "steps_completed": [
    "metadata_extraction",
    "audio_validation"
  ],
  "steps_remaining": [
    "transcription",
    "ai_analysis",
    "sermon_creation"
  ]
}
```

**Completed processing response**:
```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "completed",
  "current_step": "completed",
  "progress_percentage": 100,
  "started_at": "2024-01-15T10:00:00Z",
  "completed_at": "2024-01-15T10:25:00Z",
  "sermon_id": 123,
  "sermon_url": "/sermons/the-parable-of-the-sower",
  "error_message": null,
  "processing_summary": {
    "title": "The Parable of the Sower",
    "series": "Parables of Jesus",
    "reference": "Matthew 13:1-23",
    "transcript_generated": true,
    "points_extracted": 3,
    "summary_generated": true
  }
}
```

**Failed processing response**:
```json
{
  "found": true,
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "failed",
  "current_step": "transcription",
  "progress_percentage": 25,
  "started_at": "2024-01-15T10:00:00Z",
  "failed_at": "2024-01-15T10:15:00Z",
  "sermon_id": null,
  "error_message": "Audio transcription service unavailable",
  "error_code": "TRANSCRIPTION_FAILED",
  "retry_available": true,
  "graceful_degradation_available": true
}
```

**Error Responses**:

**400 Bad Request** - Invalid processing ID:
```json
{
  "found": false,
  "message": "Invalid processing ID format"
}
```

**404 Not found** - Processing ID not found:
```json
{
  "found": false,
  "message": "Processing record not found"
}
```

---

### 3. Retry failed processing

Retry a failed processing job.

**Endpoint**: `POST /api/sermons/processing/{processingId}/retry`

**Authentication**: Required

**Rate Limit**: `sermon-retry` (5 requests/hour)

**Path Parameters**:

| Parameter      | Type | Required | Description                 |
| -------------- | ---- | -------- | --------------------------- |
| `processingId` | UUID | Yes      | Processing ID of failed job |

**Example Request**:
```bash
curl -X POST https://your-domain.com/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/retry \
  -H "Authorization: Bearer your-api-token"
```

**Success Response** (202 Accepted):
```json
{
  "success": true,
  "message": "Processing retry initiated successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "retry_attempt": 2,
  "status_url": "/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/status"
}
```

**Error Responses**:

**400 Bad Request** - Invalid processing ID:
```json
{
  "success": false,
  "message": "Invalid processing ID format",
  "error_code": "INVALID_PROCESSING_ID"
}
```

**422 Unprocessable Entity** - Cannot retry:
```json
{
  "success": false,
  "message": "Processing cannot be retried in current state",
  "error_code": "RETRY_NOT_AVAILABLE",
  "current_status": "completed"
}
```

---

### 4. Apply graceful degradation

Apply graceful degradation to a failed processing job, creating a sermon record with available data.

**Endpoint**: `POST /api/sermons/processing/{processingId}/graceful-degradation`

**Authentication**: Required

**Rate Limit**: `api` (60 requests/minute)

**Path Parameters**:

| Parameter      | Type | Required | Description                 |
| -------------- | ---- | -------- | --------------------------- |
| `processingId` | UUID | Yes      | Processing ID of failed job |

**Example Request**:
```bash
curl -X POST https://your-domain.com/api/sermons/processing/550e8400-e29b-41d4-a716-446655440000/graceful-degradation \
  -H "Authorization: Bearer your-api-token"
```

**Success Response** (200 OK):
```json
{
  "success": true,
  "message": "Graceful degradation applied successfully",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "sermon_id": 124,
  "sermon_url": "/sermons/sermon-2024-01-15-am",
  "degradation_summary": {
    "title": "Sermon - 2024-01-15 AM",
    "series": null,
    "reference": null,
    "transcript_available": false,
    "points_extracted": 0,
    "summary_generated": false,
    "manual_review_required": true
  }
}
```

---

### 5. Get processing statistics

Get comprehensive processing statistics and monitoring data.

**Endpoint**: `GET /api/sermons/processing/statistics`

**Authentication**: Required

**Rate Limit**: `api` (60 requests/minute)

**Query Parameters**:

| Parameter | Type    | Required | Default | Description                      |
| --------- | ------- | -------- | ------- | -------------------------------- |
| `days`    | Integer | No       | 7       | Number of days to include (1-30) |

**Example Request**:
```bash
curl -X GET "https://your-domain.com/api/sermons/processing/statistics?days=30" \
  -H "Authorization: Bearer your-api-token"
```

**Success Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "total_processed": 45,
    "successful": 42,
    "failed": 3,
    "success_rate": 93.33,
    "average_processing_time": 1245,
    "comprehensive_statistics": {
      "period": {
        "days": 30,
        "start_date": "2023-12-16T00:00:00Z",
        "end_date": "2024-01-15T23:59:59Z"
      },
      "processing_counts": {
        "total": 45,
        "completed": 42,
        "failed": 3,
        "in_progress": 0
      },
      "success_metrics": {
        "overall_success_rate": 93.33,
        "transcription_success_rate": 95.56,
        "ai_analysis_success_rate": 97.78
      },
      "performance_metrics": {
        "average_total_time": 1245,
        "average_transcription_time": 456,
        "average_analysis_time": 234,
        "median_processing_time": 1180
      },
      "error_analysis": {
        "most_common_errors": [
          {
            "error_code": "TRANSCRIPTION_TIMEOUT",
            "count": 2,
            "percentage": 66.67
          }
        ]
      }
    },
    "health": {
      "overall_status": "healthy",
      "services": {
        "openai_api": "healthy",
        "queue_system": "healthy",
        "storage": "healthy"
      }
    },
    "performance": {
      "memory_usage": 52428800,
      "peak_memory": 67108864,
      "uptime": 3.45
    }
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

---

### 6. Get failed processing logs

Get a list of failed processing jobs for manual review.

**Endpoint**: `GET /api/sermons/processing/failed`

**Authentication**: Required

**Rate Limit**: `api` (60 requests/minute)

**Query Parameters**:

| Parameter | Type    | Required | Default | Description                         |
| --------- | ------- | -------- | ------- | ----------------------------------- |
| `limit`   | Integer | No       | 50      | Number of records to return (1-100) |

**Example Request**:
```bash
curl -X GET "https://your-domain.com/api/sermons/processing/failed?limit=10" \
  -H "Authorization: Bearer your-api-token"
```

**Success Response** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "processing_id": "550e8400-e29b-41d4-a716-446655440000",
      "original_filename": "sermon-2024-01-15-am.mp3",
      "status": "failed",
      "current_step": "transcription",
      "error_message": "Audio transcription service unavailable",
      "error_code": "TRANSCRIPTION_FAILED",
      "failed_at": "2024-01-15T10:15:00Z",
      "retry_count": 2,
      "retry_available": true,
      "graceful_degradation_available": true
    }
  ],
  "count": 1,
  "limit": 10,
  "timestamp": "2024-01-15T10:30:00Z"
}
```

---

### 7. System health check

Check the health status of the automated sermon processing system.

**Endpoint**: `GET /api/sermons/processing/health`

**Authentication**: Required

**Rate Limit**: `api` (60 requests/minute)

**Example Request**:
```bash
curl -X GET https://your-domain.com/api/sermons/processing/health \
  -H "Authorization: Bearer your-api-token"
```

**Healthy response** (200 OK):
```json
{
  "overall_status": "healthy",
  "services": {
    "openai_api": {
      "status": "healthy",
      "response_time": 245,
      "last_check": "2024-01-15T10:29:00Z"
    },
    "queue_system": {
      "status": "healthy",
      "pending_jobs": 2,
      "failed_jobs": 0,
      "last_check": "2024-01-15T10:29:30Z"
    },
    "storage": {
      "status": "healthy",
      "disk_usage": 45.2,
      "available_space": "2.1TB",
      "last_check": "2024-01-15T10:29:45Z"
    }
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

**Degraded response** (200 OK):
```json
{
  "overall_status": "degraded",
  "services": {
    "openai_api": {
      "status": "degraded",
      "response_time": 2450,
      "last_check": "2024-01-15T10:29:00Z",
      "message": "High response times detected"
    },
    "queue_system": {
      "status": "healthy",
      "pending_jobs": 15,
      "failed_jobs": 2,
      "last_check": "2024-01-15T10:29:30Z"
    },
    "storage": {
      "status": "healthy",
      "disk_usage": 45.2,
      "available_space": "2.1TB",
      "last_check": "2024-01-15T10:29:45Z"
    }
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

**Error response** (503 Service unavailable):
```json
{
  "overall_status": "error",
  "services": {
    "openai_api": {
      "status": "error",
      "last_check": "2024-01-15T10:29:00Z",
      "message": "API endpoint unreachable"
    },
    "queue_system": {
      "status": "error",
      "message": "Queue worker not responding"
    },
    "storage": {
      "status": "healthy",
      "disk_usage": 45.2,
      "available_space": "2.1TB",
      "last_check": "2024-01-15T10:29:45Z"
    }
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

---

## Error codes reference

| Error Code              | Description                           | HTTP Status | Retry Recommended |
| ----------------------- | ------------------------------------- | ----------- | ----------------- |
| `INVALID_FILE`          | Uploaded file is invalid or corrupted | 400         | No                |
| `VALIDATION_ERROR`      | Request validation failed             | 422         | No                |
| `RATE_LIMIT_EXCEEDED`   | Too many requests                     | 429         | Yes (after delay) |
| `TRANSCRIPTION_FAILED`  | Audio transcription service error     | 422         | Yes               |
| `TRANSCRIPTION_TIMEOUT` | Transcription service timeout         | 422         | Yes               |
| `AI_ANALYSIS_FAILED`    | AI content analysis error             | 422         | Yes               |
| `STORAGE_ERROR`         | File storage error                    | 422         | Yes               |
| `INVALID_PROCESSING_ID` | Processing ID format invalid          | 400         | No                |
| `PROCESSING_NOT_FOUND`  | Processing record not found           | 404         | No                |
| `RETRY_NOT_AVAILABLE`   | Cannot retry in current state         | 422         | No                |
| `INTERNAL_ERROR`        | Unexpected server error               | 500         | Yes               |

---

## Processing pipeline

The automated sermon processing follows this pipeline:

1. **File upload & validation**
   - Validate file format and size
   - Store file securely
   - Create processing record

2. **Metadata extraction**
   - Extract date from filename
   - Determine service type (AM/PM) from creation time
   - Set default preacher

3. **Audio transcription**
   - Transcribe audio using OpenAI Whisper
   - Store transcript as Markdown file
   - Validate transcript quality

4. **AI content analysis**
   - Generate sermon title (max 12 words)
   - Identify sermon series from existing database
   - Extract primary Bible passage reference
   - Generate sermon point headings
   - Create concise sermon summary (under 200 words)

5. **Sermon record creation**
   - Create sermon record with processed data
   - Generate URL slug from title
   - Update processing status to completed

6. **Notification**
   - Send completion notification to administrators
   - Log final processing results

---

## Best practices

### File naming convention

For optimal metadata extraction, use this filename format:
```
sermon-YYYY-MM-DD-{am|pm}.{ext}
```

Examples:
- `sermon-2024-01-15-am.mp3`
- `sermon-2024-01-15-pm.wav`

### Error handling

- Always check the `success` field in responses
- Use the `error_code` field for programmatic error handling
- Implement exponential backoff for retryable errors
- Monitor rate limit headers to avoid throttling

### Monitoring

- Regularly check system health via the `/health` endpoint
- Monitor processing statistics for performance trends
- Set up alerts for failed processing jobs
- Review failed processing logs for manual intervention

### Security

- Store API tokens securely
- Use HTTPS for all API requests
- Validate file types on client side before upload
- Implement proper error handling to avoid information disclosure