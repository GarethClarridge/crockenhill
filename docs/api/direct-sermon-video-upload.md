# Direct Sermon Video Upload API Documentation

## Overview

The Direct Sermon Video Upload API provides an endpoint for uploading video files that contain sermon-only content. Unlike the livestream processing system which analyzes and segments videos, this endpoint is designed for videos that are already sermon-only and don't require segmentation analysis.

## Base URL

All API endpoints use the base URL: `https://your-domain.com/api/sermons/`

## Authentication

All endpoints require authentication using Laravel Sanctum tokens or session-based authentication.

## Endpoint

### Upload Direct Sermon Video

Upload a video file containing sermon-only content for automated processing.

**Endpoint:** `POST /api/sermons/video`

**Content-Type:** `multipart/form-data`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | File | Yes | Video file (MP4, MOV, AVI, MKV) |

#### File Requirements

- **Supported formats:** MP4, MOV, AVI, MKV
- **Maximum file size:** 2GB (configurable via `LIVESTREAM_MAX_FILE_SIZE`)
- **Content:** Must contain audio track for transcription
- **Duration:** Should contain sermon-only content (entire video will be processed)

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
  "message": "Validation failed",
  "errors": {
    "file": [
      "The uploaded file must be one of the following types: mp4, mov, avi, mkv.",
      "The video file may not be greater than 2048MB."
    ]
  }
}
```

**Error Response (HTTP 400):**

```json
{
  "success": false,
  "message": "Invalid or corrupted video file uploaded",
  "error_code": "INVALID_FILE"
}
```

**Error Response (HTTP 500):**

```json
{
  "success": false,
  "message": "An unexpected error occurred during video processing",
  "error_code": "INTERNAL_ERROR"
}
```

#### Example Request

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "file=@/path/to/sermon-video.mp4" \
  https://your-domain.com/api/sermons/video
```

#### Example JavaScript/Node.js

```javascript
const FormData = require('form-data');
const fs = require('fs');
const axios = require('axios');

async function uploadSermonVideo(filePath, apiToken) {
  const form = new FormData();
  form.append('file', fs.createReadStream(filePath));
  
  try {
    const response = await axios.post(
      'https://your-domain.com/api/sermons/video',
      form,
      {
        headers: {
          ...form.getHeaders(),
          'Authorization': `Bearer ${apiToken}`
        }
      }
    );
    
    console.log('Processing ID:', response.data.processing_id);
    return response.data.processing_id;
  } catch (error) {
    console.error('Upload failed:', error.response.data);
    throw error;
  }
}
```

#### Example Python

```python
import requests

def upload_sermon_video(file_path, api_token):
    url = 'https://your-domain.com/api/sermons/video'
    headers = {'Authorization': f'Bearer {api_token}'}
    
    with open(file_path, 'rb') as f:
        files = {'file': f}
        response = requests.post(url, files=files, headers=headers)
    
    if response.status_code == 202:
        data = response.json()
        print(f"Processing ID: {data['processing_id']}")
        return data['processing_id']
    else:
        print(f"Upload failed: {response.json()}")
        raise Exception("Upload failed")
```

## Processing Workflow

### 1. Video Upload and Validation

1. Video file is uploaded and validated for format and size
2. File is stored securely in temporary location
3. Video metadata is extracted (duration, format, etc.)
4. Unique processing ID is generated

### 2. Audio Extraction

1. Full audio track is extracted from the entire video
2. Audio is optimized for transcription (compressed to meet 25MB limit)
3. Audio format is converted to MP3 with optimal settings:
   - Bitrate: 48kbps (with fallback to 32kbps if needed)
   - Sample rate: 16kHz
   - Channels: Mono

### 3. Sermon Processing Pipeline

1. Extracted audio is processed through the existing automated sermon processing system
2. Audio is transcribed using OpenAI Whisper API
3. AI analysis extracts:
   - Sermon title (max 12 words)
   - Preacher identification
   - Series identification from existing database
   - Primary Bible passage reference
   - Sermon point headings
   - Concise summary (under 200 words)

### 4. Sermon Record Creation

1. Sermon record is created with processed metadata
2. Video file is moved to permanent storage
3. Sermon record is updated with:
   - `source_type`: `video_upload`
   - `video_file_path`: Path to stored video file
   - `segment_start_time`: 0 (entire video is sermon)
   - `segment_end_time`: Video duration
4. URL slug is generated from title

### 5. Status Updates

1. Processing status is tracked in real-time
2. Status can be monitored via existing `/api/sermons/processing/{processingId}/status` endpoint
3. Completion notifications are sent if configured

## Status Monitoring

Use the existing sermon processing status endpoint to monitor progress:

**Endpoint:** `GET /api/sermons/processing/{processingId}/status`

This returns the same format as audio-only sermon processing, with additional video metadata.

## Rate Limiting

- **Upload endpoint:** 1 request per minute, 5 requests per hour per user
- **Status endpoint:** 60 requests per minute per user

Rate limiting headers are included in responses:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window
- `X-RateLimit-Reset`: Unix timestamp when the rate limit resets

## Configuration

### Environment Variables

The endpoint uses configuration from both sermon processing and livestream processing:

| Variable | Default | Description |
|----------|---------|-------------|
| `LIVESTREAM_MAX_FILE_SIZE` | `2147483648` | Maximum file size in bytes (2GB) |
| `FFMPEG_PATH` | `/usr/bin/ffmpeg` | Path to FFmpeg binary |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to FFprobe binary |
| `LIVESTREAM_STORAGE_DISK` | `local` | Storage disk for temporary files |
| `LIVESTREAM_SERMON_DISK` | `local` | Storage disk for permanent video files |

### Audio Extraction Settings

```php
'audio_extraction' => [
    'transcription_optimized' => [
        'bitrate' => 48, // kbps
        'sample_rate' => 16000, // Hz
        'channels' => 1, // mono
        'max_file_size' => 25 * 1024 * 1024, // 25MB
    ],
    'fallback_compression' => [
        'bitrate' => 32, // kbps for more aggressive compression
        'sample_rate' => 16000, // Hz
        'channels' => 1, // mono
    ],
]
```

## Error Handling

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `INVALID_FILE` | 400 | Video file is invalid or corrupted |
| `VALIDATION_ERROR` | 422 | File validation failed (format, size, etc.) |
| `VIDEO_PROCESSING_INITIATION_FAILED` | 422 | Failed to start video processing |
| `INTERNAL_ERROR` | 500 | Unexpected server error |

### Error Response Format

```json
{
  "success": false,
  "message": "Human-readable error message",
  "error_code": "MACHINE_READABLE_CODE",
  "errors": {
    "field": ["Validation error messages"]
  }
}
```

## Differences from Livestream Processing

| Feature | Direct Sermon Video | Livestream Processing |
|---------|-------------------|----------------------|
| **Purpose** | Sermon-only videos | Full livestream analysis |
| **Audio Analysis** | None (full video processed) | RMS analysis for segmentation |
| **Segmentation** | None | Identifies song vs speech segments |
| **Processing Time** | Faster (no analysis step) | Longer (includes segmentation) |
| **Output** | Single sermon record | Sermon + segments data |
| **Video Storage** | Full video stored | Sermon segment extracted |

## Integration with Existing Systems

This endpoint integrates seamlessly with existing sermon management:

- **Status Tracking**: Uses same processing log system as audio uploads
- **API Responses**: Compatible with existing sermon processing status endpoints
- **Error Handling**: Uses same error codes and response formats
- **Authentication**: Uses same Sanctum token system
- **Rate Limiting**: Follows same patterns as other upload endpoints

## Best Practices

### File Naming Convention

For optimal metadata extraction, use descriptive filenames:
```
sermon-YYYY-MM-DD-title-preacher.mp4
```

Examples:
- `sermon-2024-01-15-parable-of-sower-john-smith.mp4`
- `sunday-service-2024-01-15-am.mov`

### Video Preparation

- **Quality**: Use consistent video quality for better user experience
- **Duration**: Ensure video contains only sermon content (no music, announcements)
- **Audio**: Ensure clear audio quality for accurate transcription
- **File Size**: Optimize file size while maintaining acceptable quality

### Error Handling

- Always check the `success` field in responses
- Use the `error_code` field for programmatic error handling
- Implement exponential backoff for retryable errors
- Monitor rate limit headers to avoid throttling

### Monitoring

- Regularly check processing status for uploaded videos
- Set up alerts for failed processing jobs
- Monitor disk usage for video storage
- Track transcription accuracy and processing times

## Troubleshooting

### Common Issues

1. **File Upload Fails**
   - Check file format is supported (MP4, MOV, AVI, MKV)
   - Verify file size is under 2GB limit
   - Ensure proper authentication token

2. **Processing Stuck**
   - Check FFmpeg is installed and accessible
   - Verify sufficient disk space for video processing
   - Check queue workers are running

3. **Audio Extraction Fails**
   - Ensure video contains valid audio track
   - Check video is not corrupted
   - Verify FFmpeg can process the video format

4. **Transcription Fails**
   - Audio file may be too large (>25MB limit)
   - Check audio quality and clarity
   - Verify OpenAI API is accessible

5. **Video Storage Issues**
   - Check storage disk has sufficient space
   - Verify write permissions on storage directories
   - Ensure storage disk configuration is correct

### Support

For technical support or bug reports, please contact the development team or create an issue in the project repository.

---

**Version:** 1.0.0  
**Last Updated:** September 2025  
**Compatibility:** Laravel 10+, PHP 8.1+