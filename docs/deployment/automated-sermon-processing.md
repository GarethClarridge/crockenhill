# Automated Sermon Processing - Deployment Configuration

## Overview

This document provides comprehensive deployment configuration for the automated sermon processing feature, including environment variables, queue worker setup, monitoring, and backup procedures.

## Environment Variables

### Production Environment Configuration

Add these essential variables to your production `.env` file:

```bash
# Application 
APP_ENV=production
APP_DEBUG=false
APP_KEY=your-32-character-secret-key

# Database
DB_CONNECTION=mysql
DB_HOST=your-database-host
DB_DATABASE=crockenhill_production
DB_USERNAME=your-db-username
DB_PASSWORD=your-secure-db-password

# Queue System
QUEUE_CONNECTION=database

# OpenAI for Transcription
OPENAI_API_KEY=your-production-openai-api-key
TRANSCRIPTION_SERVICE_TYPE=openai

# Storage
SERMON_STORAGE_DISK=local

# Notifications (optional)
SERMON_NOTIFICATIONS_ENABLED=true
SERMON_ADMIN_EMAILS=admin@crockenhill.org

# Mail (if notifications enabled)
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=noreply@crockenhill.org
```


---

## PHP Configuration

### Production PHP Settings

Update `php.ini` for audio processing and API requests:

```ini
# File upload limits (for audio files up to 2GB)
upload_max_filesize = 2000M
post_max_size = 2000M
max_input_time = 1800
max_execution_time = 1800

# Memory limits for audio processing
memory_limit = 2048M

# Input variable limits
max_input_vars = 1000

# Required for processing
variables_order = EGPCS

# Temporary directory
upload_tmp_dir = /var/tmp/php_uploads
```

### Development PHP Settings

For development environments using Docker/Sail, the optimized settings are already configured. See `docker/php/php.ini` for reference.

---

## Queue Worker Configuration

### Systemd Service Configuration

Systemd is the recommended approach as it's available on all modern Linux distributions without requiring additional packages.

#### Single Queue Worker

**File**: `/etc/systemd/system/crockenhill-worker.service`

```ini
[Unit]
Description=Crockenhill Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=5s
ExecStart=/usr/bin/php /var/www/laravel/artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --timeout=1800
WorkingDirectory=/var/www/laravel
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

#### Queue Worker Commands

```bash
# Enable and start the service
sudo systemctl enable crockenhill-worker
sudo systemctl start crockenhill-worker

# Check status
sudo systemctl status crockenhill-worker

# Restart worker (after deployment)
sudo systemctl restart crockenhill-worker

# View logs
sudo journalctl -u crockenhill-worker -f
```


---

## Database Configuration

### Migration Commands

```bash
# Run migrations
php artisan migrate --force

# Verify sermon processing tables exist
php artisan tinker
>>> \DB::table('sermon_processing_logs')->count()
>>> \DB::table('sermons')->whereNotNull('transcript_path')->count()
```

### Database Indexes

Ensure these indexes exist for optimal performance:

```sql
-- Sermon processing logs indexes
CREATE INDEX idx_sermon_processing_logs_status ON sermon_processing_logs(status);
CREATE INDEX idx_sermon_processing_logs_created_at ON sermon_processing_logs(created_at);
CREATE INDEX idx_sermon_processing_logs_processing_id ON sermon_processing_logs(processing_id);

-- Sermons table indexes (if not already present)
CREATE INDEX idx_sermons_date ON sermons(date);
CREATE INDEX idx_sermons_service ON sermons(service);
CREATE INDEX idx_sermons_transcript_path ON sermons(transcript_path);
```

---

## File Storage Configuration

### Storage Configuration

```bash
# Create storage directories
mkdir -p /var/www/laravel/storage/app/sermons
mkdir -p /var/www/laravel/storage/app/transcripts
chown -R www-data:www-data /var/www/laravel/storage/app/sermons
chown -R www-data:www-data /var/www/laravel/storage/app/transcripts
chmod -R 755 /var/www/laravel/storage/app/sermons
chmod -R 755 /var/www/laravel/storage/app/transcripts
```

---

## Basic Monitoring

### Simple Health Check

Test that the system is working:

```bash
# Check if service is running
sudo systemctl status crockenhill-worker

# Check recent logs for errors
sudo journalctl -u crockenhill-worker --since "1 hour ago" | grep -i error

# Test database connection
php artisan tinker
>>> \DB::connection()->getPdo();
```

---

## Simple Backup

### Monthly Manual Backup

Run these commands monthly (first Sunday of each month):

```bash
# 1. Backup database
DATE=$(date +%Y%m%d)
mysqldump -u your-db-username -p your-database-name > backup_$DATE.sql
gzip backup_$DATE.sql

# 2. Backup sermon files and transcripts
tar -czf sermon_files_$DATE.tar.gz /var/www/laravel/storage/app/sermons /var/www/laravel/storage/app/transcripts

# 3. Store backups safely (copy to external drive or cloud storage)
```


### If Something Goes Wrong

```bash
# Check for failed jobs and retry them
php artisan queue:failed
php artisan queue:retry all

# If database restore needed
gunzip backup_YYYYMMDD.sql.gz
mysql -u your-db-username -p your-database-name < backup_YYYYMMDD.sql
```

---

## Deployment Checklist

### Pre-Deployment

- [ ] Update environment variables in production
- [ ] Verify OpenAI API key is valid and has sufficient credits
- [ ] Ensure Redis/queue system is running
- [ ] Verify database migrations are ready
- [ ] Check storage permissions and configuration
- [ ] Test backup scripts

### Deployment Steps

1. **Deploy Application Code**
   ```bash
   git pull origin main
   composer install --no-dev --optimize-autoloader
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

2. **Run Database Migrations**
   ```bash
   php artisan migrate --force
   ```

3. **Restart Queue Worker**
   ```bash
   sudo systemctl restart crockenhill-worker
   ```

4. **Clear Application Caches**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

5. **Test Upload**
   - Upload a test sermon file to verify everything works

### Post-Deployment Checklist

- [ ] Test sermon upload functionality
- [ ] Check that worker is running: `sudo systemctl status crockenhill-worker`

### Rollback Plan

If issues occur:

1. **Stop Queue Worker**
   ```bash
   sudo systemctl stop crockenhill-worker
   ```

2. **Rollback Code**
   ```bash
   git checkout previous-stable-tag
   composer install --no-dev --optimize-autoloader
   ```

3. **Rollback Database** (if needed)
   ```bash
   php artisan migrate:rollback --step=X
   ```

4. **Restart Service**
   ```bash
   sudo systemctl start crockenhill-worker
   ```

---

## Web Server Configuration

### Nginx Configuration

```nginx
# Support large sermon files (300MB+)
client_max_body_size 2G;

# Extended timeouts for processing
proxy_read_timeout 3600s;
proxy_connect_timeout 75s;
proxy_send_timeout 3600s;
```


---

## Troubleshooting

### Common Issues

1. **Worker Not Processing**
   ```bash
   # Check status and restart if needed
   sudo systemctl status crockenhill-worker
   sudo systemctl restart crockenhill-worker
   
   # Check recent logs
   sudo journalctl -u crockenhill-worker --since "1 hour ago"
   ```

2. **Upload Problems**
   ```bash
   # Check storage permissions
   ls -la storage/app/
   
   # Fix if needed
   chown -R www-data:www-data storage/
   chmod -R 755 storage/
   ```

3. **Failed Jobs**
   ```bash
   # Check and retry failed jobs
   php artisan queue:failed
   php artisan queue:retry all
   ```

That's it! Your automated sermon processing system should now be running with minimal complexity.