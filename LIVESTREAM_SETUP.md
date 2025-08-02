# Livestream Video Processing Setup - Complete ✅

The livestream video processing system has been successfully set up and is ready for use!

## 🚀 What's Installed

### Core System
- ✅ **PHP-FFmpeg Package**: Installed and configured for video processing
- ✅ **Database Tables**: 3 new tables created for processing logs, segments, and sermon extensions
- ✅ **Models & Services**: Complete service architecture with 6 core services
- ✅ **Background Jobs**: 5-job processing chain for reliable video processing
- ✅ **API Endpoints**: 6 REST API endpoints for complete functionality

### Configuration
- ✅ **Environment Variables**: All 19 livestream config variables added to `.env`
- ✅ **FFmpeg Paths**: Correctly configured for Docker container (`/usr/bin/ffmpeg`)
- ✅ **Storage Directories**: Created for temp files, videos, and audio
- ✅ **Queue Configuration**: Using database driver for local development
- ✅ **Rate Limiting**: Configured to prevent system overload

## 📁 Directory Structure Created

```
storage/app/
├── livestream/
│   └── temp/           # Temporary processing files
├── sermons/
│   ├── videos/         # Extracted sermon videos
│   └── audio/          # Extracted sermon audio
```

## 🔧 Configuration Summary

| Setting | Value | Purpose |
|---------|--------|---------|
| RMS Threshold | -30.0 dB | Distinguishes music from speech |
| Min Section Duration | 60 seconds | Prevents micro-segments |
| Min Sermon Duration | 5 minutes | Minimum viable sermon length |
| Max File Size | 2GB | Upload limit for videos |
| Processing Timeout | 2 hours | Max processing time |
| Queue Driver | Database | Background job processing |

## 🎯 API Endpoints Available

### Upload & Processing
- `POST /api/livestreams/process` - Upload video for processing
- `GET /api/livestreams/processing/{id}/status` - Get real-time status
- `GET /api/livestreams/processing/{id}/result` - Get complete results

### Management
- `POST /api/livestreams/processing/{id}/retry` - Retry failed processing  
- `POST /api/livestreams/processing/{id}/cancel` - Cancel processing
- `GET /api/livestreams/processing/summary` - Get system statistics

## 📝 How to Use

### 1. Start Queue Worker (Required)
```bash
# In a separate terminal, keep this running:
sail artisan queue:work --queue=livestream-processing,default --timeout=3600
```

### 2. Upload a Video via API
```bash
curl -X POST http://localhost/api/livestreams/process \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN" \
  -H "Content-Type: multipart/form-data" \
  -F "video=@/path/to/livestream.mp4"
```

### 3. Monitor Processing Status
```bash
curl -X GET http://localhost/api/livestreams/processing/{processing-id}/status \
  -H "Authorization: Bearer YOUR_SANCTUM_TOKEN"
```

## 🔄 Processing Flow

1. **Video Upload** → File validation & storage
2. **RMS Analysis** → FFmpeg generates audio analysis log
3. **Segmentation** → Identifies songs vs speech sections
4. **Sermon Extraction** → Finds longest speech segment
5. **File Generation** → Creates MP3 audio + MP4 video
6. **Sermon Processing** → Feeds into existing AI pipeline
7. **Cleanup** → Removes temporary files

## 📊 Supported Formats

**Video Input**: MP4, MOV, AVI, MKV, WebM (up to 2GB)
**Video Output**: MP4 (original quality preserved)
**Audio Output**: MP3 (128kbps for sermon processing)

## 🔐 Authentication Required

All API endpoints require Sanctum authentication:
1. Create user token: `sail artisan sanctum:token {user_id}`
2. Include in requests: `Authorization: Bearer {token}`

## ⚙️ Advanced Configuration

Edit `.env` file to customize:
```env
# Adjust sensitivity for music detection
LIVESTREAM_RMS_THRESHOLD=-25.0

# Change minimum durations
LIVESTREAM_MIN_SECTION_DURATION=30.0
LIVESTREAM_MIN_SERMON_DURATION=240.0

# Modify file size limits  
LIVESTREAM_MAX_FILE_SIZE=4294967296  # 4GB

# Enable/disable notifications
LIVESTREAM_NOTIFY_SUCCESS=true
LIVESTREAM_NOTIFY_FAILURE=true
```

## 🧪 Testing

Run the included test script:
```bash
sail exec laravel.test php test_livestream_api.php
```

## 📈 Monitoring

- Check `livestream_processing_logs` table for all processing attempts
- View `livestream_segments` table for segmentation details  
- Monitor queue with: `sail artisan queue:monitor`
- Check logs: `sail artisan log:view`

## 🛠️ Troubleshooting

### Common Issues

**Queue not processing**: Ensure queue worker is running
```bash
sail artisan queue:work --queue=livestream-processing,default
```

**FFmpeg errors**: Verify paths in container
```bash
sail exec laravel.test which ffmpeg
sail exec laravel.test which ffprobe
```

**Storage errors**: Check directory permissions
```bash
sail exec laravel.test ls -la storage/app/livestream/
```

**Memory issues**: Increase PHP memory limit for large files
```bash
# In docker-compose.yml or php.ini
memory_limit = 2G
```

## 🎉 System Ready!

The livestream video processing system is fully operational and ready to:

- ✅ Accept video uploads up to 2GB
- ✅ Automatically segment livestreams using AI
- ✅ Extract sermon audio and video
- ✅ Integrate with existing sermon processing
- ✅ Provide real-time status monitoring
- ✅ Handle errors gracefully with retry logic
- ✅ Clean up temporary files automatically

**Next Steps**: Start uploading your first livestream video and watch the magic happen! 🎬✨