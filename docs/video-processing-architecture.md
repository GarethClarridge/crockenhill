# Unified Video Processing Architecture

## Overview

The Crockenhill Baptist Church website features a unified media processing pipeline that handles three types of media uploads:
- **Audio files** (sermon audio recordings)
- **Video files** (direct sermon videos)  
- **Livestream recordings** (full service videos requiring segmentation)

This unified architecture replaced a previously fragmented system and ensures that **all video files are preserved** while providing consistent processing and API responses.

## Architecture Components

### 1. ProcessingRouter (Entry Point)

The `ProcessingRouter` service acts as the intelligent routing layer that directs uploads to the appropriate processing service based on user-specified media type.

**Key Features:**
- **Smart Routing**: Routes based on explicit user choice (no auto-detection needed)
- **Validation**: Performs file type and size validation before processing
- **Configuration**: Supports different limits and formats per processing type

**Routing Logic:**
```
Audio Upload → SermonProcessingService::processSermon()
Video Upload → VideoProcessingService::processDirectly() 
Livestream Upload → VideoProcessingService::processWithSegmentation()
```

### 2. VideoProcessingService (Video Handler)

Handles all video processing, preserving video files throughout the pipeline. Renamed from the original `LivestreamProcessingService` and enhanced with dual processing modes.

**Processing Modes:**
- **`processWithSegmentation()`**: For livestream recordings requiring RMS analysis and segmentation
- **`processDirectly()`**: For sermon-only videos that don't need segmentation

**Key Features:**
- **Video Preservation**: All video files are stored permanently and linked to sermon records
- **Audio Extraction**: Optimized for transcription services with compression fallbacks
- **Job Chain**: Resilient processing using Laravel job chains
- **Error Handling**: Comprehensive error recovery and cleanup procedures

**Processing Pipeline (Segmentation):**
```
Video Upload → Storage → RMS Analysis → Segmentation → Sermon Extraction → Audio Processing → Permanent Storage
```

**Processing Pipeline (Direct):**
```
Video Upload → Storage → Audio Extraction → Sermon Processing → Video + Audio Storage
```

### 3. SermonProcessingService (Audio & AI)

Focuses on audio processing, transcription, and AI-powered sermon analysis.

**Responsibilities:**
- Audio file processing and storage
- Speech transcription (OpenAI Whisper or Mock service)
- AI-powered metadata extraction (title, preacher, series, Bible references)
- Sermon record creation and updates

### 4. ProcessingStatusContract (Unified API)

Interface ensuring consistent API responses across all processing types.

**Contract Methods:**
- `getProcessingStatus()`: Returns standardized status information
- `cancelProcessing()`: Provides consistent cancellation functionality  
- `canHandle()`: Enables polymorphic processing ID handling

**Benefits:**
- **API Consistency**: Unified response format across processing types
- **Better Monitoring**: Standardized status checking enables system monitoring
- **Client Integration**: Easier frontend integration with consistent interfaces

## Processing Flow

### Upload Flow
```
1. User uploads media file via frontend
2. File sent to AutomatedSermonController endpoint
3. ProcessingRouter validates and routes to appropriate service
4. Service processes media and preserves files
5. Unified status tracking via ProcessingStatusContract
6. Sermon record created with preserved media files
```

### Status Checking Flow
```
1. Client requests status via processing ID
2. AutomatedSermonController or LivestreamProcessingController receives request
3. ProcessingStatusContract ensures consistent response format
4. StandardProcessingResponse returned with progress/completion info
```

## API Endpoints

### Unified Upload Endpoints (AutomatedSermonController)
- `POST /api/sermons/audio` - Audio sermon files
- `POST /api/sermons/video` - Direct sermon videos  
- `POST /api/sermons/livestream` - Full livestream recordings

### Legacy Endpoint (Backwards Compatibility)
- `POST /api/livestreams/process` - Direct livestream processing (LivestreamProcessingController)

### Status Management
- `GET /api/sermons/processing/{processingId}/status` - Unified status checking
- `DELETE /api/sermons/processing/{processingId}` - Cancel processing
- `POST /api/sermons/processing/{processingId}/retry` - Retry failed processing

## Key Improvements Over Previous Architecture

### Problems Solved
1. **Video File Preservation**: Previous system deleted video files during processing
2. **Architectural Fragmentation**: Eliminated overlapping services with different behaviors
3. **API Inconsistency**: Unified response formats across all processing types
4. **Error Handling**: Improved error recovery and monitoring capabilities

### Benefits Achieved
- ✅ **All video files preserved** across all processing types
- ✅ **Single, consistent pipeline** for all media processing
- ✅ **Unified API responses** for better client integration  
- ✅ **Simplified codebase** - eliminated problematic duplicate code
- ✅ **Enhanced monitoring** - standardized status tracking
- ✅ **Better error handling** - comprehensive recovery procedures

## Configuration

### Environment Variables
```bash
# Processing Limits
LIVESTREAM_MAX_FILE_SIZE=2147483648  # 2GB for livestreams
SERMON_MAX_FILE_SIZE=104857600       # 100MB for direct uploads

# Storage Configuration
LIVESTREAM_TEMP_DISK=local
LIVESTREAM_SERMON_DISK=local
LIVESTREAM_STORAGE_VIDEO_PATH=sermons/videos

# Transcription Service
TRANSCRIPTION_SERVICE_TYPE=mock      # 'openai' for production
OPENAI_API_KEY=your_key_here         # Required if using openai
```

### Supported File Formats

**Video Files:**
- MP4, MOV, AVI, MKV, WEBM
- Maximum size: 2GB (livestream) / 100MB (direct)

**Audio Files:**
- MP3, WAV, M4A, MP4
- Maximum size: 100MB

## Monitoring and Health

### Health Checks
- FFmpeg availability and version
- Storage space monitoring  
- Queue worker status
- Processing success rates

### Logging
- Comprehensive processing logs
- Error tracking and recovery
- Performance metrics
- User activity monitoring

## Technical Implementation Details

### Job Chain Architecture
The system uses Laravel job chains for resilient processing:

```php
Bus::chain([
    new GenerateRmsLog($processingLog),
    new AnalyzeSegments($processingLog), 
    new ExtractSermon($processingLog),
    new SubmitToProcessing($processingLog),
    new CleanupTemporaryFiles($processingLog),
])->dispatch();
```

### Error Recovery
- Automatic retry logic for failed jobs
- Graceful degradation options
- Comprehensive cleanup procedures
- Email notifications for critical failures

### Performance Optimization
- Audio compression for large files
- Efficient video storage management
- Background processing for all operations
- Optimal transcription service integration

This unified architecture provides a robust, maintainable foundation for all media processing needs while ensuring data preservation and consistent user experience.