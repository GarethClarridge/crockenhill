# Livestream Processing Deployment Guide

## Overview

This guide covers the deployment requirements and setup for the livestream video processing feature. The system extends the existing automated sermon processing with video analysis capabilities.

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

Update `php.ini` for video processing:

```ini
# File upload limits
upload_max_filesize = 2G
post_max_size = 2G
max_input_time = 3600
max_execution_time = 3600

# Memory limits
memory_limit = 1G

# Temporary directory with sufficient space
upload_tmp_dir = /var/tmp/php_uploads
```

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

# Processing Configuration
LIVESTREAM_RMS_THRESHOLD=-30.0
LIVESTREAM_MIN_SECTION_DURATION=60.0
LIVESTREAM_MIN_SERMON_DURATION=300.0
LIVESTREAM_MAX_FILE_SIZE=2147483648

# Storage Configuration
LIVESTREAM_STORAGE_DISK=local
LIVESTREAM_SERMON_DISK=sermon_disk

# Queue Configuration
LIVESTREAM_QUEUE_NAME=livestream
LIVESTREAM_QUEUE_CONNECTION=redis

# Notification Configuration
LIVESTREAM_ADMIN_EMAIL=admin@your-domain.com
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

#### Redis Setup (Recommended)

Install and configure Redis for queue processing:

```bash
# Ubuntu/Debian
sudo apt install redis-server

# Start Redis
sudo systemctl start redis-server
sudo systemctl enable redis-server
```

Update `.env`:
```env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

#### Queue Worker Setup

Create a systemd service for queue workers:

```bash
sudo nano /etc/systemd/system/laravel-worker.service
```

```ini
[Unit]
Description=Laravel queue worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /path/to/your/app/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable laravel-worker
sudo systemctl start laravel-worker
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
        alias /path/to/your/app/storage/app/sermons;
    }
}
```

#### Apache Configuration

Add to your Apache virtual host:

```apache
<VirtualHost *:80>
    # ... existing configuration
    
    # Increase upload limits
    LimitRequestBody 2147483648
    
    # Increase timeouts
    TimeOut 3600
    
    # Private sermon video access
    <Directory "/path/to/your/app/storage/app/sermons">
        Require all denied
    </Directory>
</VirtualHost>
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

#### S3 Storage (Optional)

For cloud storage, configure S3:

```env
LIVESTREAM_STORAGE_DISK=s3
LIVESTREAM_SERMON_DISK=s3

AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket-name
```

Add S3 disk to `config/filesystems.php`:

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
],
```

### Queue Configuration

#### Queue Names

```env
# Separate queue for livestream processing
LIVESTREAM_QUEUE_NAME=livestream

# Use different queue connection if needed
LIVESTREAM_QUEUE_CONNECTION=redis
```

#### Worker Configuration

Run dedicated workers for livestream processing:

```bash
# Dedicated livestream worker
php artisan queue:work redis --queue=livestream --sleep=3 --tries=3 --max-time=7200

# General worker for other jobs
php artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
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
php artisan queue:monitor redis:livestream --max=10

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
   
   # Backup to S3
   aws s3 sync storage/app/sermons/ s3://backup-bucket/sermons/
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
sudo systemctl restart laravel-worker

# Check Redis connection
redis-cli ping
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
0 2 * * * /usr/bin/php /path/to/app/artisan livestream:cleanup

# Weekly log rotation
0 0 * * 0 /usr/bin/php /path/to/app/artisan log:clear --days=30

# Monthly storage report
0 9 1 * * /usr/bin/php /path/to/app/artisan livestream:storage-report
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

This deployment guide provides comprehensive instructions for setting up and maintaining the livestream processing feature. Follow the steps carefully and adapt the configuration to your specific environment and requirements.