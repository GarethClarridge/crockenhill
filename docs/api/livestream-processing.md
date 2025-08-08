# Livestream Processing API Documentation

## Overview

The Livestream Processing API provides endpoints for uploading and processing full livestream video files. The system automatically segments videos using audio analysis, identifies sermon portions, extracts both audio and video, and integrates with the existing automated sermon processing pipeline.

## Base URL

All API endpoints are prefixed with `/api/livestreams/`

## Authentication

All endpoints require authentication using Laravel Sanctum tokens or session-based authentication.

## Endpoints

### 1. Process Livestream Video

Upload and process a full livestream video file.

**Endpoint:** `POST /api/livestreams/process`

**Content-Type:** `multipart/form-data`

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `video` | File | Yes | Video file (MP4, MOV, AVI, MKV) |
| `options` | JSON | No | Processing preferences (reserved for future use) |

#### File Requirements

- **Supported formats:** MP4, MOV, AVI, MKV
- **Maximum file size:** 2GB (configurable via `LIVESTREAM_MAX_FILE_SIZE`)
- **Content:** Must contain audio track for analysis

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "success": true,
  "message": "Livestream processing initiated",
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status_url": "/api/livestreams/processing/550e8400-e29b-41d4-a716-446655440000/status",
  "estimated_completion": "2024-01-15T10:30:00Z"
}
```

**Error Response (HTTP 400/422):**

```json
{
  "success": false,
  "message": "File validation failed",
  "errors": {
    "file": [
      "The file must be a valid video file.",
      "The file may not be greater than 2048 kilobytes."
    ]
  }
}
```

#### Example Request

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "video=@/path/to/livestream.mp4" \
  https://your-domain.com/api/livestreams/process
```

### 2. Get Processing Status

Monitor the progress of livestream processing.

**Endpoint:** `GET /api/livestreams/processing/{processingId}/status`

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `processingId` | UUID | Yes | Processing ID returned from upload |

#### Response Format

**Success Response (HTTP 200):**

```json
{
  "processing_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "segmenting",
  "current_step": "audio_analysis",
  "progress_percentage": 45,
  "segments_identified": 5,
  "sermon_processing_id": "660e8400-e29b-41d4-a716-446655440001",
  "sermon_video_path": "/storage/sermons/123/video.mp4",
  "created_at": "2024-01-15T09:00:00Z",
  "updated_at": "2024-01-15T09:15:00Z",
  "segments": [
    {
      "index": 1,
      "start_time": 0.0,
      "end_time": 180.5,
      "duration": 180.5,
      "classification": "song"
    },
    {
      "index": 2,
      "start_time": 180.5,
      "end_time": 2100.0,
      "duration": 1919.5,
      "classification": "speech",
      "is_sermon": true
    },
    {
      "index": 3,
      "start_time": 2100.0,
      "end_time": 2280.0,
      "duration": 180.0,
      "classification": "song"
    }
  ]
}
```

**Not Found Response (HTTP 404):**

```json
{
  "success": false,
  "message": "Processing record not found"
}
```

#### Status Values

| Status | Description |
|--------|-------------|
| `pending` | Processing queued but not started |
| `processing` | Currently being processed |
| `segmenting` | Analyzing audio and identifying segments |
| `extracting` | Extracting sermon audio and video |
| `sermon_submitted` | Submitted to sermon processing pipeline |
| `completed` | Processing completed successfully |
| `failed` | Processing failed |

#### Current Step Values

| Step | Description |
|------|-------------|
| `audio_analysis` | Analyzing audio track with FFmpeg |
| `segment_identification` | Classifying segments as song/speech |
| `sermon_extraction` | Extracting sermon video and audio |
| `sermon_processing` | Processing through sermon pipeline |
| `cleanup` | Cleaning up temporary files |

#### Example Request

```bash
curl -X GET \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  https://your-domain.com/api/livestreams/processing/550e8400-e29b-41d4-a716-446655440000/status
```

## Processing Workflow

### 1. Upload and Validation

1. File is uploaded and validated for format and size
2. Unique processing ID is generated
3. File is stored securely
4. Processing job chain is dispatched

### 2. Audio Analysis

1. Audio track is extracted from video
2. RMS (Root Mean Square) analysis is performed using FFmpeg
3. Volume patterns are analyzed to identify loud (music) and quiet (speech) sections

### 3. Segmentation

1. Audio sections are classified as "song" (above RMS threshold) or "speech" (below threshold)
2. Segments shorter than minimum duration are filtered out
3. Segment metadata is stored in database

### 4. Sermon Identification

1. Longest speech segment is identified as the primary sermon
2. If no suitable sermon segment is found, processing is marked for manual review

### 5. Extraction

1. Sermon video segment is extracted maintaining original quality
2. Audio is converted to MP3 format for sermon processing
3. Both files are stored appropriately

### 6. Sermon Processing Integration

1. Extracted audio is submitted to existing automated sermon processing
2. AI analysis extracts metadata (title, preacher, series, etc.)
3. Sermon record is created and linked to original livestream

**Important:** Extracted sermon audio files must be under 50MB for AI transcription processing. Longer sermons from large video files may exceed this limit and fail during the transcription step.

### 7. Cleanup

1. Temporary files are cleaned up
2. Processing status is updated to completed
3. Notifications are sent if configured

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `LIVESTREAM_RMS_THRESHOLD` | `-30.0` | RMS threshold for song/speech classification |
| `LIVESTREAM_MIN_SECTION_DURATION` | `60.0` | Minimum section duration in seconds |
| `LIVESTREAM_MIN_SERMON_DURATION` | `300.0` | Minimum sermon duration in seconds |
| `LIVESTREAM_MAX_FILE_SIZE` | `2147483648` | Maximum file size in bytes (2GB) |
| `FFMPEG_PATH` | `/usr/bin/ffmpeg` | Path to FFmpeg binary |
| `FFPROBE_PATH` | `/usr/bin/ffprobe` | Path to FFprobe binary |
| `LIVESTREAM_STORAGE_DISK` | `local` | Storage disk for livestream files |
| `LIVESTREAM_SERMON_DISK` | `sermon_disk` | Storage disk for sermon videos |
| `LIVESTREAM_RATE_LIMITING_ENABLED` | `true` | Enable/disable API rate limiting |
| `LIVESTREAM_UPLOAD_RATE_PER_MINUTE` | `1` | Upload requests per minute |
| `LIVESTREAM_UPLOAD_RATE_PER_HOUR` | `5` | Upload requests per hour |
| `LIVESTREAM_RETRY_RATE_PER_MINUTE` | `1` | Retry requests per minute |
| `LIVESTREAM_RETRY_RATE_PER_HOUR` | `3` | Retry requests per hour |
| `LIVESTREAM_STATUS_RATE_PER_MINUTE` | `60` | Status requests per minute |

### Supported File Formats

- **MP4** (recommended)
- **MOV** (QuickTime)
- **AVI** (Audio Video Interleave)
- **MKV** (Matroska Video)

## Error Handling

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `INVALID_FILE_FORMAT` | 422 | Unsupported file format |
| `FILE_TOO_LARGE` | 422 | File exceeds size limit |
| `PROCESSING_FAILED` | 500 | General processing failure |
| `FFMPEG_ERROR` | 500 | FFmpeg command failed |
| `STORAGE_ERROR` | 500 | File storage failed |
| `NO_SERMON_FOUND` | 422 | No suitable sermon segment identified |
| `SERMON_FILE_TOO_LARGE` | 422 | Extracted sermon audio exceeds transcription limit (50MB) |

### Error Response Format

```json
{
  "success": false,
  "message": "Human-readable error message",
  "error_code": "MACHINE_READABLE_CODE",
  "details": {
    "additional": "context information"
  }
}
```

## Rate Limiting

Rate limiting is configurable and can be adjusted or disabled entirely for development environments.

### Default Production Limits

- **Upload endpoint:** 1 request per minute, 5 requests per hour per user
- **Retry endpoint:** 1 request per minute, 3 requests per hour per user
- **Status endpoint:** 60 requests per minute per user

### Configuration

Rate limiting can be configured via environment variables:

```bash
# Enable/disable rate limiting (useful for development)
LIVESTREAM_RATE_LIMITING_ENABLED=true

# Upload endpoint limits
LIVESTREAM_UPLOAD_RATE_PER_MINUTE=1
LIVESTREAM_UPLOAD_RATE_PER_HOUR=5

# Retry endpoint limits
LIVESTREAM_RETRY_RATE_PER_MINUTE=1
LIVESTREAM_RETRY_RATE_PER_HOUR=3

# Status endpoint limits
LIVESTREAM_STATUS_RATE_PER_MINUTE=60
```

### Development Environment

For development environments, rate limiting can be completely disabled by setting:

```bash
LIVESTREAM_RATE_LIMITING_ENABLED=false
```

When disabled, all livestream processing endpoints will have no rate limiting applied.

## Webhooks (Future Enhancement)

Webhook support for processing status updates is planned for future releases. This will allow external systems to receive real-time notifications about processing progress.

## Integration Examples

### JavaScript/Node.js

```javascript
const FormData = require('form-data');
const fs = require('fs');
const axios = require('axios');

async function uploadLivestream(filePath, apiToken) {
  const form = new FormData();
  form.append('video', fs.createReadStream(filePath));
  
  try {
    const response = await axios.post(
      'https://your-domain.com/api/livestreams/process',
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

async function checkStatus(processingId, apiToken) {
  try {
    const response = await axios.get(
      `https://your-domain.com/api/livestreams/processing/${processingId}/status`,
      {
        headers: {
          'Authorization': `Bearer ${apiToken}`
        }
      }
    );
    
    return response.data;
  } catch (error) {
    console.error('Status check failed:', error.response.data);
    throw error;
  }
}
```

### Python

```python
import requests
import time

def upload_livestream(file_path, api_token):
    url = 'https://your-domain.com/api/livestreams/process'
    headers = {'Authorization': f'Bearer {api_token}'}
    
    with open(file_path, 'rb') as f:
        files = {'video': f}
        response = requests.post(url, files=files, headers=headers)
    
    if response.status_code == 200:
        data = response.json()
        print(f"Processing ID: {data['processing_id']}")
        return data['processing_id']
    else:
        print(f"Upload failed: {response.json()}")
        raise Exception("Upload failed")

def check_status(processing_id, api_token):
    url = f'https://your-domain.com/api/livestreams/processing/{processing_id}/status'
    headers = {'Authorization': f'Bearer {api_token}'}
    
    response = requests.get(url, headers=headers)
    
    if response.status_code == 200:
        return response.json()
    else:
        print(f"Status check failed: {response.json()}")
        raise Exception("Status check failed")

def wait_for_completion(processing_id, api_token, max_wait=3600):
    """Wait for processing to complete, checking every 30 seconds"""
    start_time = time.time()
    
    while time.time() - start_time < max_wait:
        status = check_status(processing_id, api_token)
        
        if status['status'] in ['completed', 'failed']:
            return status
        
        print(f"Status: {status['status']} - {status.get('current_step', 'N/A')}")
        time.sleep(30)
    
    raise Exception("Processing timeout")
```

## Troubleshooting

### Common Issues

1. **File Upload Fails**
   - Check file format is supported
   - Verify file size is under limit
   - Ensure proper authentication

2. **Processing Stuck**
   - Check FFmpeg is installed and accessible
   - Verify sufficient disk space
   - Check queue workers are running

3. **No Sermon Found**
   - Audio may be too quiet or too loud
   - Adjust RMS threshold configuration
   - Check minimum sermon duration setting

4. **Video Quality Issues**
   - Original quality is preserved during extraction
   - Check source video quality
   - Verify codec compatibility

5. **Sermon Transcription Fails Due to File Size**
   - Extracted sermon audio exceeds 50MB transcription limit
   - Consider reducing video bitrate or quality before upload
   - For very long sermons (>40 minutes), audio compression may be needed
   - Check livestream recording settings to optimize audio quality vs file size

### Support

For technical support or bug reports, please contact the development team or create an issue in the project repository.

## Changelog

### Version 1.0.0
- Initial release
- Basic video upload and processing
- Audio analysis and segmentation
- Sermon extraction and integration
- Status monitoring API

### Future Enhancements
- Webhook support for status updates
- Batch processing capabilities
- Advanced segmentation algorithms
- Custom processing profiles
- Integration with external storage services