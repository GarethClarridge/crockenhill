# Unified Media Processing Deployment Guide

## Overview

This guide covers the deployment requirements and setup for the unified media processing system. The system handles audio files, sermon videos, and full livestream recordings through a single pipeline with intelligent routing and processing capabilities.

## System Requirements

### Server Requirements

- **CPU:** Multi-core processor (4+ cores recommended for video processing)
- **RAM:** Minimum 8GB, 16GB+ recommended for large video files
- **Storage:** 
  - Fast SSD storage for temporary processing files
  - Sufficient space for video storage (plan for 1-2GB per hour of video)
  - Separate disk/partition for sermon videos recommended
- **Network:** Stable internet connection for AI service integration

### Software Dependencies

#### Required Software

1. **FFmpeg** (version 4.0+)
   ```bash
   # Ubuntu/Debian
   sudo apt update
   sudo apt install ffmpeg
   
   # CentOS/RHEL
   sudo yum install epel-release
   sudo yum install ffmpeg
   
   # macOS
   brew install ffmpeg
   ```

2. **FFprobe** (usually included with FFmpeg)
   ```bash
   # Verify installation
   ffmpeg -version
   ffprobe -version
   ```

3. **PHP Extensions**
   - `php-gd` (for image processing)
   - `php-curl` (for API calls)
   - `php-zip` (for file handling)
   - `php-json` (for JSON processing)

#### PHP Configuration

Update `php.ini` for video processing (production values):

```ini
# File upload and processing limits
upload_max_filesize = 5G
post_max_size = 5G
max_input_time = 7200
max_execution_time = 7200

# Memory limits for video processing
memory_limit = 2G

# Input variable limits for large form data
max_input_vars = 3000

# Temporary directory with sufficient space
upload_tmp_dir = /var/tmp/php_uploads

# Required for video processing
variables_order = EGPCS
```

**For development environments**, you can use the provided Docker configuration which includes these optimized settings.

## Installation Steps

### 1. Install Composer Dependencies

The required PHP packages should already be installed:

```bash
composer require php-ffmpeg/php-ffmpeg
```

### 2. Environment Configuration

Add the following environment variables to your `.env` file:

```env
# FFmpeg Configuration
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe

# RMS Threshold Configuration (Primary)
LIVESTREAM_RMS_THRESHOLD=-45.0

# Adaptive Threshold Configuration (New Feature)
LIVESTREAM_ADAPTIVE_THRESHOLDS_ENABLED=true
LIVESTREAM_SPEECH_PERCENTILE=30
LIVESTREAM_ADAPTIVE_FALLBACK_ENABLED=true
LIVESTREAM_MIN_THRESHOLD=-80.0
LIVESTREAM_MAX_THRESHOLD=-20.0
LIVESTREAM_MIN_SAMPLE_COUNT=1000

# Section Duration Requirements
LIVESTREAM_MIN_SECTION_DURATION=60.0
LIVESTREAM_MIN_SERMON_DURATION=300.0

# File Size and Processing Limits
LIVESTREAM_MAX_FILE_SIZE=2147483648
LIVESTREAM_PROCESSING_TIMEOUT=7200
LIVESTREAM_MAX_CONCURRENT_JOBS=2
LIVESTREAM_RETRY_ATTEMPTS=3
LIVESTREAM_RETRY_DELAY=60

# Storage Configuration
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=local
LIVESTREAM_TEMP_DISK=local

# Storage Paths
LIVESTREAM_VIDEO_PATH=sermons/videos
LIVESTREAM_AUDIO_PATH=sermons/audio
LIVESTREAM_TEMP_PATH=temp/livestreams

# Queue Configuration
LIVESTREAM_QUEUE_NAME=livestream-processing
LIVESTREAM_QUEUE_CONNECTION=database

# Notification Configuration
LIVESTREAM_ADMIN_EMAIL=admin@your-domain.com
LIVESTREAM_NOTIFY_SUCCESS=false
LIVESTREAM_NOTIFY_FAILURE=true

# Cleanup and Retention
LIVESTREAM_TEMP_RETENTION_HOURS=24
LIVESTREAM_FAILED_RETENTION_DAYS=7
LIVESTREAM_AUTO_CLEANUP=true

# Quality and Performance Settings
LIVESTREAM_AUDIO_SAMPLE_RATE=44100
LIVESTREAM_VIDEO_PRESET=medium
LIVESTREAM_PRESERVE_QUALITY=true

# Rate Limiting (API)
LIVESTREAM_RATE_LIMITING_ENABLED=true
LIVESTREAM_UPLOAD_RATE_PER_MINUTE=1
LIVESTREAM_UPLOAD_RATE_PER_HOUR=5
LIVESTREAM_RETRY_RATE_PER_MINUTE=1
LIVESTREAM_RETRY_RATE_PER_HOUR=3
LIVESTREAM_STATUS_RATE_PER_MINUTE=60

# Logging Configuration
LIVESTREAM_DETAILED_LOGGING=true
LIVESTREAM_LOG_FFMPEG=false
LIVESTREAM_PERFORMANCE_MONITORING=true

# Transcription Service (Required for sermon processing)
TRANSCRIPTION_SERVICE_TYPE=openai
OPENAI_API_KEY=your-production-openai-api-key
OPENAI_MODEL=gpt-4o-mini
```

### 3. Database Migration

Run the database migrations to create required tables:

```bash
php artisan migrate
```

This will create:
- `livestream_processing_logs` table
- `livestream_segments` table
- Add livestream columns to `sermons` table

### 4. Storage Configuration

#### Configure Storage Disks

Add storage disk configuration to `config/filesystems.php`:

```php
'disks' => [
    // ... existing disks
    
    'sermon_disk' => [
        'driver' => 'local',
        'root' => storage_path('app/sermons'),
        'url' => env('APP_URL').'/storage/sermons',
        'visibility' => 'private', // Videos should be private
    ],
    
    'livestream_temp' => [
        'driver' => 'local',
        'root' => storage_path('app/temp/livestreams'),
        'visibility' => 'private',
    ],
],
```

#### Create Storage Directories

```bash
# Create required directories
mkdir -p storage/app/livestreams
mkdir -p storage/app/temp/livestreams
mkdir -p storage/app/sermons

# Set proper permissions
chmod -R 755 storage/app/livestreams
chmod -R 755 storage/app/temp/livestreams
chmod -R 755 storage/app/sermons

# Ensure web server can write
chown -R www-data:www-data storage/app/livestreams
chown -R www-data:www-data storage/app/temp/livestreams
chown -R www-data:www-data storage/app/sermons
```

### 5. Queue Configuration

#### Queue Setup

Configure database queues in your `.env`:

```env
QUEUE_CONNECTION=database
```

#### Queue Worker Setup

Create systemd services for queue workers. You need separate workers for different queue types:

**Livestream Processing Worker:**

```bash
sudo nano /etc/systemd/system/crockenhill-livestream-worker.service
```

```ini
[Unit]
Description=Crockenhill Livestream Processing Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5s
ExecStart=/usr/bin/php /var/www/laravel/artisan queue:work database --queue=livestream-processing --sleep=3 --tries=3 --max-time=7200 --timeout=3600
WorkingDirectory=/var/www/laravel
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

**General Processing Worker:**

```bash
sudo nano /etc/systemd/system/crockenhill-sermon-worker.service
```

```ini
[Unit]
Description=Crockenhill Sermon Processing Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5s
ExecStart=/usr/bin/php /var/www/laravel/artisan queue:work database --queue=sermon-processing,default --sleep=3 --tries=3 --max-time=3600
WorkingDirectory=/var/www/laravel
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Enable and start both services:

```bash
sudo systemctl daemon-reload

# Enable services to start on boot
sudo systemctl enable crockenhill-livestream-worker
sudo systemctl enable crockenhill-sermon-worker

# Start services
sudo systemctl start crockenhill-livestream-worker
sudo systemctl start crockenhill-sermon-worker

# Check status
sudo systemctl status crockenhill-livestream-worker
sudo systemctl status crockenhill-sermon-worker
```

### 6. Web Server Configuration

#### Nginx Configuration

Add to your Nginx server block:

```nginx
server {
    # ... existing configuration
    
    # Increase client body size for video uploads
    client_max_body_size 2G;
    
    # Increase timeouts for video processing
    proxy_read_timeout 3600;
    proxy_connect_timeout 3600;
    proxy_send_timeout 3600;
    
    # Private sermon video access
    location /storage/sermons {
        internal;
        alias /var/www/laravel/storage/app/sermons;
    }
}
```


## Configuration Options

### Processing Parameters

#### RMS Threshold Configuration

The RMS threshold determines how the system classifies audio sections:

```env
# Lower values = more sensitive to quiet sections (more speech detected)
# Higher values = less sensitive (more music detected)
LIVESTREAM_RMS_THRESHOLD=-30.0  # Default: -30.0 dB
```

**Tuning Guidelines:**
- **-35.0 to -25.0:** Good for most church services
- **-40.0 to -30.0:** For quieter recordings or sensitive microphones
- **-25.0 to -20.0:** For louder recordings or less sensitive detection

#### Duration Settings

```env
# Minimum duration for a section to be considered valid
LIVESTREAM_MIN_SECTION_DURATION=60.0  # 1 minute

# Minimum duration for a speech section to be considered a sermon
LIVESTREAM_MIN_SERMON_DURATION=300.0  # 5 minutes
```

#### File Size Limits

```env
# Maximum file size in bytes (default: 2GB)
LIVESTREAM_MAX_FILE_SIZE=2147483648

# For larger files, increase PHP limits as well:
# upload_max_filesize = 4G
# post_max_size = 4G
```

### Storage Configuration

#### Local Storage

```env
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=sermon_disk
```


### Queue Configuration

#### Queue Names

```env
# Separate queue for livestream processing
LIVESTREAM_QUEUE_NAME=livestream

# Use database queue connection
LIVESTREAM_QUEUE_CONNECTION=database
```

#### Worker Configuration

Run dedicated workers for livestream processing:

```bash
# Dedicated livestream worker
php artisan queue:work database --queue=livestream --sleep=3 --tries=3 --max-time=7200

# General worker for other jobs
php artisan queue:work database --queue=default --sleep=3 --tries=3 --max-time=3600
```

## Health Checks and Monitoring

### Built-in Health Checks

The system includes several health checks:

```bash
# Check system health
php artisan health:check

# Specific checks available:
# - ffmpeg-availability
# - livestream-queue
# - video-storage
```

### Custom Monitoring

#### Queue Monitoring

Monitor queue status:

```bash
# Check queue size
php artisan queue:monitor database:livestream --max=10

# Check failed jobs
php artisan queue:failed
```

#### Storage Monitoring

Monitor disk space:

```bash
# Check available space
df -h /path/to/storage

# Monitor storage usage
du -sh storage/app/livestreams/
du -sh storage/app/sermons/
```

#### Log Monitoring

Monitor processing logs:

```bash
# Follow livestream processing logs
tail -f storage/logs/laravel.log | grep "livestream"

# Check for errors
grep "ERROR" storage/logs/laravel.log | grep "livestream"
```

## Performance Optimization

### Server Optimization

#### CPU Optimization

```bash
# Check CPU usage during processing
htop

# Monitor FFmpeg processes
ps aux | grep ffmpeg
```

#### Memory Optimization

```bash
# Monitor memory usage
free -h

# Check for memory leaks
watch -n 1 'ps aux | grep "php\|ffmpeg" | grep -v grep'
```

#### Storage Optimization

```bash
# Use SSD for temporary processing
mount /dev/nvme0n1 /var/tmp/livestream

# Set up automatic cleanup
echo "0 2 * * * find /path/to/temp -type f -mtime +1 -delete" | crontab -
```

### Application Optimization

#### Queue Optimization

```bash
# Run multiple workers for parallel processing
for i in {1..4}; do
    php artisan queue:work redis --queue=livestream --daemon &
done
```

#### Database Optimization

Add indexes for better performance:

```sql
-- Already included in migrations, but verify:
CREATE INDEX idx_livestream_processing_status ON livestream_processing_logs(status);
CREATE INDEX idx_livestream_segments_processing ON livestream_segments(processing_id);
CREATE INDEX idx_sermons_livestream ON sermons(livestream_processing_id);
```

## Security Considerations

### File Upload Security

1. **File Type Validation**
   - Only allow specific video formats
   - Validate MIME types server-side
   - Check file headers, not just extensions

2. **File Size Limits**
   - Set reasonable upload limits
   - Monitor disk space usage
   - Implement cleanup policies

3. **Storage Security**
   - Store videos outside web root
   - Use private storage disks
   - Implement access controls

### API Security

1. **Authentication**
   - Require authentication for all endpoints
   - Use Laravel Sanctum tokens
   - Implement rate limiting

2. **Input Validation**
   - Validate all input parameters
   - Sanitize file names
   - Check processing IDs format

### Processing Security

1. **FFmpeg Security**
   - Use specific FFmpeg paths
   - Validate command parameters
   - Run with limited privileges

2. **Temporary Files**
   - Clean up temporary files
   - Use secure temporary directories
   - Set proper file permissions

## Backup and Recovery

### Backup Strategy

1. **Database Backups**
   ```bash
   # Daily database backup
   mysqldump -u user -p database > backup_$(date +%Y%m%d).sql
   ```

2. **Video File Backups**
   ```bash
   # Backup sermon videos
   rsync -av storage/app/sermons/ /backup/sermons/
   
   ```

3. **Configuration Backups**
   ```bash
   # Backup configuration files
   tar -czf config_backup_$(date +%Y%m%d).tar.gz .env config/
   ```

### Recovery Procedures

1. **Failed Processing Recovery**
   ```bash
   # Retry failed jobs
   php artisan queue:retry all
   
   # Clear stuck jobs
   php artisan queue:flush
   ```

2. **Storage Recovery**
   ```bash
   # Restore from backup
   rsync -av /backup/sermons/ storage/app/sermons/
   
   # Fix permissions
   chown -R www-data:www-data storage/app/sermons/
   ```

## Troubleshooting

### Common Issues

#### FFmpeg Not Found

```bash
# Check FFmpeg installation
which ffmpeg
ffmpeg -version

# Install if missing
sudo apt install ffmpeg

# Update path in .env
FFMPEG_PATH=/usr/bin/ffmpeg
```

#### Permission Errors

```bash
# Fix storage permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 755 storage/

# Check SELinux (if applicable)
sudo setsebool -P httpd_can_network_connect 1
```

#### Queue Not Processing

```bash
# Check queue workers
ps aux | grep "queue:work"

# Restart workers
sudo systemctl restart crockenhill-livestream-worker
sudo systemctl restart crockenhill-sermon-worker

# Check database connection
php artisan tinker
>>> \DB::connection()->getPdo();
```

#### Out of Disk Space

```bash
# Check disk usage
df -h

# Clean up old files
find storage/app/temp -type f -mtime +7 -delete

# Implement retention policy
php artisan livestream:cleanup --days=30
```

### Log Analysis

#### Processing Logs

```bash
# Check processing status
grep "processing_id" storage/logs/laravel.log

# Find errors
grep "ERROR.*livestream" storage/logs/laravel.log

# Monitor real-time
tail -f storage/logs/laravel.log | grep "livestream"
```

#### Performance Logs

```bash
# Check processing times
grep "execution_time" storage/logs/laravel.log

# Monitor memory usage
grep "memory_usage" storage/logs/laravel.log
```

## Maintenance

### Regular Maintenance Tasks

1. **Daily Tasks**
   - Monitor queue status
   - Check disk space
   - Review error logs

2. **Weekly Tasks**
   - Clean up temporary files
   - Review processing statistics
   - Update system packages

3. **Monthly Tasks**
   - Backup configuration
   - Review storage usage
   - Performance optimization

### Automated Maintenance

Create cron jobs for automated maintenance:

```bash
# Add to crontab
crontab -e

# Daily cleanup at 2 AM
0 2 * * * /usr/bin/php /var/www/laravel/artisan livestream:cleanup

# Weekly log rotation
0 0 * * 0 /usr/bin/php /var/www/laravel/artisan log:clear --days=30

# Monthly storage report
0 9 1 * * /usr/bin/php /var/www/laravel/artisan livestream:storage-report
```

## Support and Updates

### Getting Help

1. **Documentation**
   - Check API documentation
   - Review troubleshooting guide
   - Consult Laravel documentation

2. **Logs and Debugging**
   - Enable debug mode for development
   - Check Laravel logs
   - Use health check commands

3. **Community Support**
   - Laravel community forums
   - FFmpeg documentation
   - Project-specific support channels

### Updates and Upgrades

1. **Application Updates**
   ```bash
   # Update dependencies
   composer update
   
   # Run migrations
   php artisan migrate
   
   # Clear caches
   php artisan config:clear
   php artisan cache:clear
   ```

2. **System Updates**
   ```bash
   # Update system packages
   sudo apt update && sudo apt upgrade
   
   # Update FFmpeg if needed
   sudo apt install --only-upgrade ffmpeg
   ```

## Docker Deployment

### Development with Laravel Sail

The project includes a Docker configuration optimized for video processing:

#### Prerequisites

- Docker and Docker Compose installed
- Git repository cloned

#### Setup

1. **Copy environment file:**
   ```bash
   cp .env.example .env
   ```

2. **Configure for development:**
   ```bash
   # Add to .env for development only
   TRANSCRIPTION_SERVICE_TYPE=mock  # Use mock service to avoid API costs in development
   LIVESTREAM_RATE_LIMITING_ENABLED=false  # Disable rate limiting in development
   ```

3. **Start services:**
   ```bash
   ./vendor/bin/sail up -d
   ```

4. **Run migrations:**
   ```bash
   ./vendor/bin/sail artisan migrate
   ```

#### Key Features of Docker Configuration

- **Optimized PHP Settings**: Pre-configured with video processing limits (5GB upload, 2G memory, 7200s timeout)
- **FFmpeg Integration**: FFmpeg pre-installed and configured in containers
- **MySQL Database**: Ready-to-use database service
- **Queue Processing**: Supports background job processing
- **Development Tools**: Includes Mailpit for email testing

#### Production Docker Deployment

For production deployments using Docker:

1. **Build production image:**
   ```bash
   docker build -f docker/8.4/Dockerfile -t your-app:latest .
   ```

2. **Use environment-specific configuration:**
   ```bash
   # Production environment variables
   APP_ENV=production
   APP_DEBUG=false
   TRANSCRIPTION_SERVICE_TYPE=openai  # Use real transcription service in production
   OPENAI_API_KEY=your-production-openai-api-key
   LIVESTREAM_RATE_LIMITING_ENABLED=true  # Enable rate limiting in production
   ```

3. **Deploy with Docker Compose or orchestration tool of choice**

## CI/CD Integration

### GitHub Actions

The project includes a GitHub Actions workflow for automated testing:

#### Features
- **FFmpeg Installation**: Automatically installs FFmpeg for video processing tests
- **Integration Tests**: Runs comprehensive integration tests with video processing
- **Environment Configuration**: Uses mock services for testing to avoid API costs
- **Database Setup**: Configures MySQL for testing environment

#### Configuration
Located at `.github/workflows/integration-tests.yml`, the workflow:
- Runs on pushes to `master` and `develop` branches
- Installs PHP 8.2 with required extensions
- Sets up MySQL service for testing
- Installs FFmpeg for video processing tests
- Uses mock transcription service (`TRANSCRIPTION_SERVICE_TYPE=mock`)
- Runs parallel integration tests

### Adding CI/CD to Your Environment

1. **Fork or adapt the existing workflow**
2. **Set up required secrets in GitHub:**
   - `OPENAI_API_KEY` (if using real API in staging/production tests)
3. **Customize environment variables for your deployment needs**

This deployment guide provides comprehensive instructions for setting up and maintaining the livestream processing feature. Follow the steps carefully and adapt the configuration to your specific environment and requirements.