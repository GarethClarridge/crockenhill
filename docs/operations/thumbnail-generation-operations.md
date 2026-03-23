# Thumbnail Generation Operations Guide

## Overview

This guide covers the operational aspects of the thumbnail generation system, including monitoring, troubleshooting, maintenance, and performance optimization.

## System Requirements

### Dependencies

**Required Software:**
- FFmpeg 4.0+ with video processing support
- PHP 8.2+ with GD or Imagick extension
- Laravel Queue Workers running
- Sufficient storage space (estimate 1-2MB per thumbnail)

**Verification Commands:**
```bash
# Check FFmpeg availability
ffmpeg -version

# Check PHP extensions
php -m | grep -E "(gd|imagick)"

# Check queue workers
php artisan queue:work --help
```

### Storage Requirements

**Disk Space Planning:**
- Average thumbnail size: 200KB - 2MB
- Multiple sizes per sermon: 2-3 thumbnails
- Estimated space per 1000 sermons: 2-6GB

**Storage Configuration:**
```bash
# Check available space
df -h /path/to/storage

# Monitor thumbnail directory
du -sh storage/app/public/sermons/thumbnails/
```

## Configuration Management

### Environment Variables

**Core Settings:**
```bash
# Enable/disable thumbnail generation
THUMBNAIL_GENERATION_ENABLED=true

# Storage configuration
THUMBNAIL_STORAGE_DISK=public
THUMBNAIL_STORAGE_PATH=sermons/thumbnails

# Processing limits
THUMBNAIL_FFMPEG_TIMEOUT=300
THUMBNAIL_MAX_CONCURRENT=3
```

**Frame Extraction Settings:**
```bash
# Timing configuration
THUMBNAIL_START_OFFSET=60        # Start 60 seconds in
THUMBNAIL_END_BUFFER=60          # Avoid last 60 seconds
THUMBNAIL_MIN_DURATION=120       # Minimum video length

# Quality settings
THUMBNAIL_WEB_WIDTH=1280
THUMBNAIL_WEB_HEIGHT=720
THUMBNAIL_WEB_QUALITY=85
```

**Overlay Configuration:**
```bash
# Text settings
THUMBNAIL_TITLE_SIZE=48
THUMBNAIL_DATE_SIZE=32
THUMBNAIL_FONT_COLOR=#000000

# Background settings
THUMBNAIL_BG_COLOR=#FFFFFF
THUMBNAIL_BG_OPACITY=0.8
THUMBNAIL_BG_PADDING=15
```

### Configuration Validation

**Check Configuration:**
```bash
# Validate configuration
php artisan config:show thumbnail-generation

# Test FFmpeg paths
php artisan tinker
>>> config('thumbnail-generation.ffmpeg.path')
>>> file_exists(config('thumbnail-generation.ffmpeg.path'))
```

**Validate Storage:**
```bash
# Check storage disk configuration
php artisan storage:link

# Test storage access
php artisan tinker
>>> Storage::disk('public')->exists('test.txt')
>>> Storage::disk('public')->put('test.txt', 'test')
>>> Storage::disk('public')->delete('test.txt')
```

## Queue Management

### Queue Configuration

**Shared Queue Setup:**
```bash
# Thumbnail jobs inherit the video or livestream processing queue.
# Start the shared processing workers used by the pipeline.
php artisan queue:work redis --queue=video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default --timeout=7200

# Monitor the queues that can carry GenerateThumbnail
php artisan queue:monitor video-processing
php artisan queue:monitor livestream-processing

# Check failed jobs
php artisan queue:failed
```

**Supervisor Configuration:**
```ini
[program:processing-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work redis --queue=video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default --timeout=7200 --tries=3
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/logs/processing-worker.log
```

### Queue Monitoring

**Monitor Queue Health:**
```bash
# Check queue size
php artisan queue:monitor video-processing
php artisan queue:monitor livestream-processing

# View recent jobs
php artisan horizon:status  # If using Horizon

# Check failed thumbnail jobs
php artisan queue:failed | grep GenerateThumbnail
```

**Performance Metrics:**
```bash
# Monitor processing times
tail -f storage/logs/laravel.log | grep "Thumbnail generation"

# Check memory usage
ps aux | grep "queue:work.*video-processing\\|queue:work.*livestream-processing"
```

## Monitoring & Alerting

### Health Checks

**System Health Script:**
```bash
#!/bin/bash
# thumbnail-health-check.sh

echo "=== Thumbnail Generation Health Check ==="

# Check FFmpeg
if command -v ffmpeg &> /dev/null; then
    echo "✓ FFmpeg available: $(ffmpeg -version | head -n1)"
else
    echo "✗ FFmpeg not found"
fi

# Check PHP extensions
if php -m | grep -q gd; then
    echo "✓ GD extension available"
else
    echo "✗ GD extension missing"
fi

# Check storage space
STORAGE_PATH="/path/to/storage/app/public/sermons/thumbnails"
if [ -d "$STORAGE_PATH" ]; then
    USAGE=$(du -sh "$STORAGE_PATH" | cut -f1)
    echo "✓ Thumbnail storage: $USAGE"
else
    echo "✗ Thumbnail storage directory not found"
fi

# Check queue workers
if pgrep -f "queue:work.*(video-processing|livestream-processing)" > /dev/null; then
    echo "✓ Shared media-processing queue worker running"
else
    echo "✗ No shared media-processing queue worker found"
fi
```

### Log Monitoring

**Key Log Patterns:**
```bash
# Successful generations
grep "Thumbnail generation completed" storage/logs/laravel.log

# Failed generations
grep "Thumbnail generation.*failed\|skipped" storage/logs/laravel.log

# FFmpeg errors
grep "FFmpeg.*failed" storage/logs/laravel.log

# Storage errors
grep "Thumbnail storage failed" storage/logs/laravel.log
```

**Log Analysis Script:**
```bash
#!/bin/bash
# thumbnail-log-analysis.sh

LOG_FILE="storage/logs/laravel.log"
TODAY=$(date +%Y-%m-%d)

echo "=== Thumbnail Generation Report for $TODAY ==="

# Count successful generations
SUCCESS_COUNT=$(grep "$TODAY.*Thumbnail generation completed" "$LOG_FILE" | wc -l)
echo "Successful generations: $SUCCESS_COUNT"

# Count failures
FAILURE_COUNT=$(grep "$TODAY.*Thumbnail generation.*failed" "$LOG_FILE" | wc -l)
echo "Failed generations: $FAILURE_COUNT"

# Count skipped
SKIPPED_COUNT=$(grep "$TODAY.*Thumbnail generation skipped" "$LOG_FILE" | wc -l)
echo "Skipped generations: $SKIPPED_COUNT"

# Success rate
if [ $((SUCCESS_COUNT + FAILURE_COUNT)) -gt 0 ]; then
    SUCCESS_RATE=$(echo "scale=2; $SUCCESS_COUNT * 100 / ($SUCCESS_COUNT + $FAILURE_COUNT)" | bc)
    echo "Success rate: $SUCCESS_RATE%"
fi
```

## Troubleshooting

### Common Issues

#### 1. FFmpeg Not Found

**Symptoms:**
- "FFmpeg not found" errors in logs
- Thumbnail generation always skipped

**Solutions:**
```bash
# Install FFmpeg (Ubuntu/Debian)
sudo apt update
sudo apt install ffmpeg

# Install FFmpeg (CentOS/RHEL)
sudo yum install epel-release
sudo yum install ffmpeg

# Verify installation
which ffmpeg
ffmpeg -version

# Update configuration
echo "FFMPEG_PATH=$(which ffmpeg)" >> .env
echo "FFPROBE_PATH=$(which ffprobe)" >> .env
```

#### 2. Storage Permission Issues

**Symptoms:**
- "Permission denied" errors
- Thumbnails not saving to disk

**Solutions:**
```bash
# Fix storage permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/

# Create thumbnail directory
mkdir -p storage/app/public/sermons/thumbnails
sudo chown www-data:www-data storage/app/public/sermons/thumbnails

# Recreate storage link
php artisan storage:link
```

#### 3. Memory Issues

**Symptoms:**
- "Memory exhausted" errors
- Jobs timing out

**Solutions:**
```bash
# Increase PHP memory limit
echo "memory_limit = 512M" >> /etc/php/8.2/cli/php.ini

# Increase the shared worker timeout if FFmpeg needs longer than 5 minutes
# Example: update your supervisor/systemd queue:work command to use --timeout=600

# Monitor memory usage
php artisan tinker
>>> ini_get('memory_limit')
```

#### 4. Queue Worker Issues

**Symptoms:**
- Jobs not processing
- Queue backing up

**Solutions:**
```bash
# Restart queue workers
php artisan queue:restart

# Clear failed jobs
php artisan queue:flush

# Start shared processing worker
php artisan queue:work redis --queue=video-processing,audio-processing,sermon-processing,livestream-processing,speaker-identification,default --timeout=7200 --tries=3

# Check worker status
ps aux | grep "queue:work"
```

### Debugging Tools

**Test Thumbnail Generation:**
```bash
# Test single sermon thumbnail
php artisan tinker
>>> $sermon = App\Models\Sermon::find(1);
>>> $service = app(App\Services\ThumbnailGenerationService::class);
>>> $result = $service->generateThumbnail($sermon, '/path/to/video.mp4');
>>> dd($result);
```

**Manual Job Dispatch:**
```bash
# Run the thumbnail job synchronously for a known processing log
php artisan tinker
>>> $log = App\Models\MediaProcessingLog::first();
>>> dispatch_sync(new App\Jobs\GenerateThumbnail($log));
```

**Configuration Testing:**
```bash
# Test configuration values
php artisan tinker
>>> config('thumbnail-generation.enabled')
>>> config('thumbnail-generation.ffmpeg.path')
>>> Storage::disk(config('thumbnail-generation.storage.disk'))->exists('test')
```

## Maintenance Tasks

### Regular Maintenance

**Daily Tasks:**
```bash
# Check queue health
php artisan queue:monitor video-processing
php artisan queue:monitor livestream-processing

# Review error logs
grep "$(date +%Y-%m-%d).*Thumbnail.*error" storage/logs/laravel.log

# Monitor storage usage
du -sh storage/app/public/sermons/thumbnails/
```

**Weekly Tasks:**
```bash
# Clean up failed jobs
php artisan queue:prune-failed --hours=168

# Analyze generation success rates
./thumbnail-log-analysis.sh

# Check for orphaned thumbnails
php artisan tinker
>>> $orphaned = collect(Storage::disk('public')->files('sermons/thumbnails'))
    ->filter(fn($file) => !App\Models\Sermon::where('thumbnail_path', $file)->exists())
    ->count();
>>> echo "Orphaned thumbnails: $orphaned";
```

**Monthly Tasks:**
```bash
# Storage cleanup and optimization
php artisan thumbnail:cleanup-orphaned  # Custom command if implemented

# Performance review
./thumbnail-performance-report.sh

# Configuration review and updates
php artisan config:show thumbnail-generation
```

### Bulk Operations

**Regenerate All Thumbnails:**
```bash
# Create custom command for bulk regeneration
php artisan make:command RegenerateThumbnails

# Example implementation:
php artisan thumbnail:regenerate --all
php artisan thumbnail:regenerate --missing-only
php artisan thumbnail:regenerate --sermon-id=123
```

**Cleanup Operations:**
```bash
# Remove thumbnails for deleted sermons
php artisan thumbnail:cleanup-orphaned

# Clear all thumbnails (for testing)
php artisan thumbnail:clear-all --confirm
```

## Performance Optimization

### Queue Optimization

**Worker Configuration:**
```bash
# Optimize worker count based on server capacity
# Rule of thumb: 1-2 workers per CPU core for shared media-processing queues

# Monitor worker performance
php artisan queue:monitor video-processing --verbose
php artisan queue:monitor livestream-processing --verbose
```

**Memory Management:**
```bash
# Monitor memory usage per job
php artisan queue:work redis --queue=video-processing,livestream-processing --memory=256

# Optimize PHP memory settings
echo "memory_limit = 512M" >> php.ini
echo "max_execution_time = 300" >> php.ini
```

### Storage Optimization

**Disk I/O Optimization:**
```bash
# Use SSD storage for thumbnail generation if possible
# Monitor disk I/O during processing
iostat -x 1

# Consider separate disk for thumbnails in high-volume scenarios
echo "THUMBNAIL_STORAGE_DISK=thumbnails" >> .env
```

**Caching Strategy:**
```bash
# Enable HTTP caching for thumbnail serving
echo "THUMBNAIL_CACHING_ENABLED=true" >> .env
echo "THUMBNAIL_CACHE_MAX_AGE=86400" >> .env

# Monitor cache hit rates
tail -f /var/log/nginx/access.log | grep thumbnail
```

### FFmpeg Optimization

**Processing Optimization:**
```bash
# Adjust FFmpeg thread count based on server capacity
echo "THUMBNAIL_FFMPEG_THREADS=4" >> .env

# Monitor FFmpeg performance
time ffmpeg -ss 60 -i test.mp4 -vframes 1 -q:v 2 test.jpg

# Optimize for specific video formats if needed
echo "THUMBNAIL_FFMPEG_PRESET=fast" >> .env
```

## Backup and Recovery

### Backup Strategy

**Thumbnail Backup:**
```bash
# Include thumbnails in regular backups
tar -czf thumbnails-backup-$(date +%Y%m%d).tar.gz storage/app/public/sermons/thumbnails/

# Database backup including thumbnail metadata
mysqldump --single-transaction database_name sermons > sermons-backup-$(date +%Y%m%d).sql
```

**Recovery Procedures:**
```bash
# Restore thumbnails from backup
tar -xzf thumbnails-backup-20231225.tar.gz -C /

# Regenerate missing thumbnails after recovery
php artisan thumbnail:regenerate --missing-only
```

### Disaster Recovery

**Complete System Recovery:**
1. Restore application code and configuration
2. Restore database with sermon records
3. Restore thumbnail files from backup
4. Verify thumbnail URLs and accessibility
5. Regenerate any missing thumbnails

**Partial Recovery:**
```bash
# Regenerate thumbnails for specific date range
php artisan thumbnail:regenerate --date-from=2023-12-01 --date-to=2023-12-31

# Verify thumbnail integrity
php artisan thumbnail:verify --fix-missing
```

This operations guide provides comprehensive coverage of thumbnail generation system management, from initial setup through ongoing maintenance and troubleshooting.
