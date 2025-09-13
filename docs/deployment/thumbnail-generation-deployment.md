# Thumbnail Generation Deployment Guide

## Overview

This guide covers the deployment and setup of the thumbnail generation system, including system requirements, configuration, and verification procedures.

## System Requirements

### Software Dependencies

**Required:**
- FFmpeg 4.0+ with video processing support
- PHP 8.2+ with GD or Imagick extension
- Laravel Queue Workers
- Sufficient storage space (2-6GB per 1000 sermons)

**Optional:**
- Oswald font files for branded text rendering
- Brand overlay images for church branding

### Server Specifications

**Minimum Requirements:**
- 2 CPU cores
- 4GB RAM
- 50GB storage space
- Network bandwidth for video processing

**Recommended:**
- 4+ CPU cores
- 8GB+ RAM
- SSD storage for thumbnails
- Dedicated queue workers

## Installation Steps

### 1. Install System Dependencies

**Ubuntu/Debian:**
```bash
# Update package list
sudo apt update

# Install FFmpeg
sudo apt install ffmpeg

# Install PHP extensions
sudo apt install php8.2-gd php8.2-imagick

# Verify installations
ffmpeg -version
php -m | grep -E "(gd|imagick)"
```

**CentOS/RHEL:**
```bash
# Enable EPEL repository
sudo yum install epel-release

# Install FFmpeg
sudo yum install ffmpeg

# Install PHP extensions
sudo yum install php-gd php-imagick

# Verify installations
ffmpeg -version
php -m | grep -E "(gd|imagick)"
```

**macOS (Development):**
```bash
# Install FFmpeg via Homebrew
brew install ffmpeg

# PHP extensions usually included with PHP installations
php -m | grep -E "(gd|imagick)"
```

### 2. Configure Environment Variables

Add the following to your `.env` file:

```bash
# Core thumbnail settings
THUMBNAIL_GENERATION_ENABLED=true
THUMBNAIL_STORAGE_DISK=public
THUMBNAIL_STORAGE_PATH=sermons/thumbnails

# FFmpeg configuration
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
THUMBNAIL_FFMPEG_TIMEOUT=300
THUMBNAIL_FFMPEG_THREADS=2

# Frame extraction settings
THUMBNAIL_START_OFFSET=60
THUMBNAIL_END_BUFFER=60
THUMBNAIL_MIN_DURATION=120
THUMBNAIL_FALLBACK_POSITION=0.5

# Thumbnail dimensions and quality
THUMBNAIL_WEB_WIDTH=1280
THUMBNAIL_WEB_HEIGHT=720
THUMBNAIL_WEB_QUALITY=85
THUMBNAIL_MOBILE_WIDTH=640
THUMBNAIL_MOBILE_HEIGHT=360
THUMBNAIL_MOBILE_QUALITY=80

# Overlay configuration
THUMBNAIL_TITLE_SIZE=48
THUMBNAIL_DATE_SIZE=32
THUMBNAIL_FONT_COLOR=#000000
THUMBNAIL_BG_COLOR=#FFFFFF
THUMBNAIL_BG_OPACITY=0.8
THUMBNAIL_BG_PADDING=15

# Brand overlay
THUMBNAIL_BRAND_IMAGE=images/BrandOverlay.png
THUMBNAIL_BRAND_POSITION=bottom-right
THUMBNAIL_BRAND_MARGIN=20

# Queue configuration
THUMBNAIL_QUEUE_NAME=thumbnails
THUMBNAIL_QUEUE_CONNECTION=database
THUMBNAIL_QUEUE_TIMEOUT=300
THUMBNAIL_QUEUE_TRIES=1

# Processing limits
THUMBNAIL_MAX_CONCURRENT=3
THUMBNAIL_MEMORY_LIMIT=512M
THUMBNAIL_TEMP_DISK=local
THUMBNAIL_TEMP_PATH=temp/thumbnails
THUMBNAIL_CLEANUP_TEMP=true

# Caching settings
THUMBNAIL_CACHING_ENABLED=true
THUMBNAIL_CACHE_MAX_AGE=86400
THUMBNAIL_ETAG_ENABLED=true
THUMBNAIL_LAST_MODIFIED=true

# Social media optimization
THUMBNAIL_OG_WIDTH=1200
THUMBNAIL_OG_HEIGHT=630
THUMBNAIL_TWITTER_CARD=summary_large_image
THUMBNAIL_OPTIMIZE_SHARING=true

# Logging
THUMBNAIL_LOGGING_ENABLED=true
THUMBNAIL_DETAILED_LOGGING=false
THUMBNAIL_LOG_FFMPEG=false
THUMBNAIL_PERFORMANCE_MONITORING=true
```

### 3. Run Database Migrations

```bash
# Run migrations to add thumbnail fields to sermons table
php artisan migrate

# Verify migration success
php artisan tinker
>>> Schema::hasColumn('sermons', 'thumbnail_path')
>>> Schema::hasColumn('sermons', 'thumbnail_generated_at')
>>> Schema::hasColumn('sermons', 'thumbnail_metadata')
```

### 4. Set Up Storage

```bash
# Create thumbnail storage directory
mkdir -p storage/app/public/sermons/thumbnails

# Set proper permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/

# Create storage symlink
php artisan storage:link

# Verify storage setup
php artisan tinker
>>> Storage::disk('public')->exists('sermons')
>>> Storage::disk('public')->put('test.txt', 'test')
>>> Storage::disk('public')->delete('test.txt')
```

### 5. Configure Queue Workers

**Supervisor Configuration:**

Create `/etc/supervisor/conf.d/thumbnail-worker.conf`:

```ini
[program:thumbnail-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --queue=thumbnails --timeout=300 --tries=1 --memory=512
directory=/path/to/your/project
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/thumbnail-worker.log
stopwaitsecs=3600
```

**Start Supervisor:**
```bash
# Reload supervisor configuration
sudo supervisorctl reread
sudo supervisorctl update

# Start thumbnail workers
sudo supervisorctl start thumbnail-worker:*

# Check worker status
sudo supervisorctl status thumbnail-worker:*
```

**Systemd Configuration (Alternative):**

Create `/etc/systemd/system/thumbnail-worker@.service`:

```ini
[Unit]
Description=Thumbnail Generation Worker %i
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/your/project
ExecStart=/usr/bin/php artisan queue:work --queue=thumbnails --timeout=300 --tries=1 --memory=512
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

**Enable and start services:**
```bash
# Enable and start 2 worker instances
sudo systemctl enable thumbnail-worker@1
sudo systemctl enable thumbnail-worker@2
sudo systemctl start thumbnail-worker@1
sudo systemctl start thumbnail-worker@2

# Check status
sudo systemctl status thumbnail-worker@1
sudo systemctl status thumbnail-worker@2
```

### 6. Install Brand Assets (Optional)

**Brand Overlay Image:**
```bash
# Copy brand overlay to public images
cp /path/to/BrandOverlay.png public/images/

# Verify image exists
ls -la public/images/BrandOverlay.png
```

**Oswald Font (Optional):**
```bash
# Download Oswald font
wget https://fonts.google.com/download?family=Oswald -O oswald.zip
unzip oswald.zip -d fonts/

# Copy to public fonts directory
mkdir -p public/fonts
cp fonts/static/Oswald-Regular.ttf public/fonts/

# Verify font installation
ls -la public/fonts/Oswald-Regular.ttf
```

## Configuration Verification

### 1. Test System Dependencies

```bash
# Test FFmpeg installation
ffmpeg -version
ffprobe -version

# Test PHP extensions
php -c "<?php var_dump(extension_loaded('gd')); ?>"
php -c "<?php var_dump(extension_loaded('imagick')); ?>"

# Test file permissions
touch storage/app/public/test.txt
rm storage/app/public/test.txt
```

### 2. Validate Configuration

```bash
# Check configuration values
php artisan config:show thumbnail-generation

# Test storage configuration
php artisan tinker
>>> config('thumbnail-generation.storage.disk')
>>> config('thumbnail-generation.ffmpeg.path')
>>> file_exists(config('thumbnail-generation.ffmpeg.path'))
```

### 3. Test Thumbnail Generation

```bash
# Create test sermon and generate thumbnail
php artisan tinker
>>> $sermon = App\Models\Sermon::first();
>>> $service = app(App\Services\ThumbnailGenerationService::class);
>>> $result = $service->generateThumbnail($sermon, '/path/to/test/video.mp4');
>>> dd($result);
```

### 4. Test Queue Processing

```bash
# Dispatch test thumbnail job
php artisan tinker
>>> App\Jobs\GenerateThumbnail::dispatch(1, '/path/to/test/video.mp4');

# Monitor queue processing
php artisan queue:monitor thumbnails

# Check job completion
tail -f storage/logs/laravel.log | grep "Thumbnail generation"
```

## Production Deployment

### 1. Environment-Specific Configuration

**Production `.env` adjustments:**
```bash
# Disable detailed logging in production
THUMBNAIL_DETAILED_LOGGING=false
THUMBNAIL_LOG_FFMPEG=false

# Optimize for production
THUMBNAIL_MAX_CONCURRENT=4
THUMBNAIL_FFMPEG_THREADS=4

# Use Redis for better queue performance
THUMBNAIL_QUEUE_CONNECTION=redis
```

### 2. Performance Optimization

**Web Server Configuration (Nginx):**
```nginx
# Add to your server block
location ~* ^/storage/sermons/thumbnails/.*\.(jpg|jpeg|png|webp)$ {
    expires 24h;
    add_header Cache-Control "public, no-transform";
    add_header Vary "Accept-Encoding";
    
    # Enable gzip compression
    gzip on;
    gzip_types image/jpeg image/png image/webp;
}

# Thumbnail serving endpoint
location ~ ^/christ/sermons/[^/]+/thumbnail$ {
    try_files $uri @laravel;
    expires 24h;
    add_header Cache-Control "public, no-transform";
}
```

**PHP Configuration:**
```ini
# Adjust PHP settings for thumbnail processing
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
```

### 3. Monitoring Setup

**Log Rotation:**
```bash
# Create logrotate configuration
sudo tee /etc/logrotate.d/thumbnail-worker << EOF
/var/log/thumbnail-worker.log {
    daily
    missingok
    rotate 14
    compress
    notifempty
    create 0644 www-data www-data
    postrotate
        supervisorctl restart thumbnail-worker:*
    endscript
}
EOF
```

**Health Check Script:**
```bash
#!/bin/bash
# /usr/local/bin/thumbnail-health-check.sh

# Check FFmpeg
if ! command -v ffmpeg &> /dev/null; then
    echo "CRITICAL: FFmpeg not found"
    exit 2
fi

# Check queue workers
if ! pgrep -f "queue:work.*thumbnails" > /dev/null; then
    echo "CRITICAL: No thumbnail queue workers running"
    exit 2
fi

# Check storage space
USAGE=$(df /path/to/storage | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$USAGE" -gt 90 ]; then
    echo "WARNING: Storage usage at ${USAGE}%"
    exit 1
fi

echo "OK: Thumbnail generation system healthy"
exit 0
```

**Cron Job for Health Checks:**
```bash
# Add to crontab
*/5 * * * * /usr/local/bin/thumbnail-health-check.sh
```

## Troubleshooting Deployment Issues

### Common Issues and Solutions

**1. FFmpeg Not Found:**
```bash
# Check FFmpeg installation
which ffmpeg
ffmpeg -version

# Update path in .env
FFMPEG_PATH=$(which ffmpeg)
FFPROBE_PATH=$(which ffprobe)
```

**2. Permission Errors:**
```bash
# Fix storage permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/

# Recreate storage link
rm public/storage
php artisan storage:link
```

**3. Queue Workers Not Processing:**
```bash
# Check worker status
sudo supervisorctl status thumbnail-worker:*

# Restart workers
sudo supervisorctl restart thumbnail-worker:*

# Check logs
tail -f /var/log/thumbnail-worker.log
```

**4. Memory Issues:**
```bash
# Increase PHP memory limit
echo "memory_limit = 1G" >> /etc/php/8.2/cli/php.ini

# Monitor memory usage
ps aux | grep "queue:work.*thumbnails"
```

### Verification Checklist

- [ ] FFmpeg installed and accessible
- [ ] PHP GD or Imagick extension loaded
- [ ] Database migrations completed
- [ ] Storage directories created with proper permissions
- [ ] Storage symlink created
- [ ] Queue workers running
- [ ] Configuration values correct
- [ ] Test thumbnail generation successful
- [ ] Brand assets installed (if using)
- [ ] Monitoring and logging configured
- [ ] Health checks operational

## Rollback Procedures

### Disable Thumbnail Generation

```bash
# Disable in environment
echo "THUMBNAIL_GENERATION_ENABLED=false" >> .env

# Stop queue workers
sudo supervisorctl stop thumbnail-worker:*

# Clear configuration cache
php artisan config:clear
```

### Remove Thumbnail Data

```bash
# Remove thumbnail files (backup first!)
cp -r storage/app/public/sermons/thumbnails/ /backup/location/
rm -rf storage/app/public/sermons/thumbnails/*

# Clear thumbnail database fields
php artisan tinker
>>> App\Models\Sermon::whereNotNull('thumbnail_path')->update([
    'thumbnail_path' => null,
    'thumbnail_generated_at' => null,
    'thumbnail_metadata' => null
]);
```

This deployment guide ensures a smooth setup of the thumbnail generation system with proper monitoring and troubleshooting procedures.