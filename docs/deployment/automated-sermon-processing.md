# Automated Sermon Processing - Deployment Configuration

## Overview

This document provides comprehensive deployment configuration for the automated sermon processing feature, including environment variables, queue worker setup, monitoring, and backup procedures.

## Environment Variables

### Production Environment Configuration

Add the following environment variables to your production `.env` file:

```bash
# Application Environment
APP_ENV=production
APP_DEBUG=false
APP_KEY=your-32-character-secret-key

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=your-database-host
DB_PORT=3306
DB_DATABASE=crockenhill_production
DB_USERNAME=your-db-username
DB_PASSWORD=your-secure-db-password

# Queue Configuration
QUEUE_CONNECTION=redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379
REDIS_DB=0

# Sermon Processing Queue Configuration
SERMON_PROCESSING_QUEUE=sermon-processing
QUEUE_FAILED_DRIVER=database

# OpenAI API Configuration
OPENAI_API_KEY=your-production-openai-api-key
OPENAI_ORGANIZATION=your-openai-organization-id
OPENAI_PROJECT=your-openai-project-id
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_REQUEST_TIMEOUT=60

# Automated Sermon Processing Configuration
TRANSCRIPTION_SERVICE=openai
ANALYSIS_SERVICE=openai
OPENAI_MODEL=gpt-4o-mini
SERMON_PROCESSING_QUEUE=sermon-processing

# File Storage Configuration
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-aws-access-key
AWS_SECRET_ACCESS_KEY=your-aws-secret-key
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-sermon-storage-bucket
AWS_USE_PATH_STYLE_ENDPOINT=false

# Alternative: Local Storage (if not using S3)
# SERMON_STORAGE_DISK=local
# SERMON_AUDIO_PATH=sermons
# SERMON_TRANSCRIPT_PATH=transcripts

# Notification Configuration
SERMON_NOTIFICATIONS_ENABLED=true
SERMON_ADMIN_EMAILS=admin@crockenhill.org,tech@crockenhill.org

# Mail Configuration for Notifications
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@crockenhill.org
MAIL_FROM_NAME="Crockenhill Baptist Church"

# Logging Configuration
LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

# Session and Cache Configuration
SESSION_DRIVER=redis
SESSION_LIFETIME=120
CACHE_DRIVER=redis

# Rate Limiting Configuration
THROTTLE_SERMON_UPLOAD=10,60  # 10 uploads per hour
THROTTLE_SERMON_RETRY=5,60    # 5 retries per hour
THROTTLE_API=60,1             # 60 requests per minute

# Health Check Configuration
HEALTH_CHECK_ENABLED=true
HEALTH_CHECK_SECRET=your-health-check-secret

# Monitoring Configuration
SENTRY_LARAVEL_DSN=your-sentry-dsn
SENTRY_TRACES_SAMPLE_RATE=0.1
```

### Development/Staging Environment

For development and staging environments, use these modified values:

```bash
# Development Environment
APP_ENV=staging
APP_DEBUG=true
LOG_LEVEL=debug

# Use smaller/cheaper OpenAI model for testing
OPENAI_MODEL=gpt-3.5-turbo

# Disable notifications in development
SERMON_NOTIFICATIONS_ENABLED=false

# Use local storage for development
SERMON_STORAGE_DISK=local

# Use database queue for simpler setup
QUEUE_CONNECTION=database
```

---

## Queue Worker Configuration

### Supervisor Configuration

Create a supervisor configuration file for queue workers:

**File**: `/etc/supervisor/conf.d/crockenhill-sermon-workers.conf`

```ini
[program:crockenhill-sermon-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/crockenhill/artisan queue:work redis --queue=sermon-processing --sleep=3 --tries=3 --max-time=3600 --timeout=1800
directory=/var/www/crockenhill
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/crockenhill-sermon-worker.log
stopwaitsecs=3600

[program:crockenhill-default-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/crockenhill/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
directory=/var/www/crockenhill
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/crockenhill-default-worker.log
stopwaitsecs=3600
```

### Queue Worker Commands

```bash
# Start supervisor services
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start crockenhill-sermon-worker:*
sudo supervisorctl start crockenhill-default-worker:*

# Monitor queue workers
sudo supervisorctl status

# Restart workers (after deployment)
sudo supervisorctl restart crockenhill-sermon-worker:*
sudo supervisorctl restart crockenhill-default-worker:*

# View worker logs
sudo tail -f /var/log/supervisor/crockenhill-sermon-worker.log
```

### Systemd Service (Alternative)

If using systemd instead of supervisor:

**File**: `/etc/systemd/system/crockenhill-sermon-worker.service`

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
ExecStart=/usr/bin/php /var/www/crockenhill/artisan queue:work redis --queue=sermon-processing --sleep=3 --tries=3 --max-time=3600 --timeout=1800
WorkingDirectory=/var/www/crockenhill
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
# Enable and start the service
sudo systemctl enable crockenhill-sermon-worker
sudo systemctl start crockenhill-sermon-worker
sudo systemctl status crockenhill-sermon-worker

# View logs
sudo journalctl -u crockenhill-sermon-worker -f
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

### AWS S3 Configuration (Recommended)

```bash
# S3 Bucket Policy for sermon files
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowApplicationAccess",
            "Effect": "Allow",
            "Principal": {
                "AWS": "arn:aws:iam::YOUR-ACCOUNT-ID:user/crockenhill-app"
            },
            "Action": [
                "s3:GetObject",
                "s3:PutObject",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::your-sermon-storage-bucket/*"
        },
        {
            "Sid": "AllowListBucket",
            "Effect": "Allow",
            "Principal": {
                "AWS": "arn:aws:iam::YOUR-ACCOUNT-ID:user/crockenhill-app"
            },
            "Action": "s3:ListBucket",
            "Resource": "arn:aws:s3:::your-sermon-storage-bucket"
        }
    ]
}
```

### Local Storage Configuration (Alternative)

```bash
# Create storage directories
mkdir -p /var/www/crockenhill/storage/app/sermons
mkdir -p /var/www/crockenhill/storage/app/transcripts
chown -R www-data:www-data /var/www/crockenhill/storage/app/sermons
chown -R www-data:www-data /var/www/crockenhill/storage/app/transcripts
chmod -R 755 /var/www/crockenhill/storage/app/sermons
chmod -R 755 /var/www/crockenhill/storage/app/transcripts
```

---

## Monitoring and Alerting

### Health Check Endpoint

Configure your load balancer or monitoring service to check:

```
GET /up
```

This endpoint provides comprehensive health status including:
- Database connectivity
- Queue system status
- OpenAI API availability
- Storage accessibility

### Log Monitoring

Configure log monitoring for these patterns:

```bash
# Error patterns to monitor
ERROR.*SermonProcessing
CRITICAL.*AutomatedSermon
EMERGENCY.*sermon.*processing

# Success patterns to track
INFO.*Automated sermon processing initiated successfully
INFO.*Processing retry initiated successfully
INFO.*Graceful degradation applied successfully
```

### Metrics to Monitor

1. **Processing Success Rate**
   - Target: >95% success rate
   - Alert if <90% over 24 hours

2. **Processing Time**
   - Target: <30 minutes average
   - Alert if >60 minutes average

3. **Queue Depth**
   - Target: <10 pending jobs
   - Alert if >50 pending jobs

4. **Failed Job Count**
   - Target: <5 failed jobs per day
   - Alert if >20 failed jobs per day

5. **API Response Times**
   - Target: <2 seconds average
   - Alert if >10 seconds average

### Alerting Configuration

Example alerting rules for your monitoring system:

```yaml
# Prometheus/Grafana alerting rules
groups:
  - name: sermon_processing
    rules:
      - alert: SermonProcessingHighFailureRate
        expr: (rate(sermon_processing_failed_total[1h]) / rate(sermon_processing_total[1h])) > 0.1
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "High sermon processing failure rate"
          description: "Sermon processing failure rate is {{ $value | humanizePercentage }} over the last hour"

      - alert: SermonProcessingQueueBacklog
        expr: sermon_processing_queue_depth > 50
        for: 10m
        labels:
          severity: critical
        annotations:
          summary: "Sermon processing queue backlog"
          description: "{{ $value }} jobs pending in sermon processing queue"

      - alert: OpenAIAPIDown
        expr: openai_api_up == 0
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "OpenAI API unavailable"
          description: "OpenAI API health check failing"
```

---

## Backup and Recovery Procedures

### Database Backup

```bash
#!/bin/bash
# Daily database backup script
# File: /usr/local/bin/backup-sermon-db.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/crockenhill"
DB_NAME="crockenhill_production"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup database
mysqldump -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD $DB_NAME > $BACKUP_DIR/sermon_db_$DATE.sql

# Compress backup
gzip $BACKUP_DIR/sermon_db_$DATE.sql

# Keep only last 30 days of backups
find $BACKUP_DIR -name "sermon_db_*.sql.gz" -mtime +30 -delete

echo "Database backup completed: sermon_db_$DATE.sql.gz"
```

### Transcript File Backup

```bash
#!/bin/bash
# Daily transcript backup script
# File: /usr/local/bin/backup-transcripts.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/crockenhill"
TRANSCRIPT_DIR="/var/www/crockenhill/storage/app/transcripts"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup transcripts
tar -czf $BACKUP_DIR/transcripts_$DATE.tar.gz -C $TRANSCRIPT_DIR .

# Keep only last 30 days of backups
find $BACKUP_DIR -name "transcripts_*.tar.gz" -mtime +30 -delete

echo "Transcript backup completed: transcripts_$DATE.tar.gz"
```

### S3 Backup (if using S3)

```bash
#!/bin/bash
# S3 backup sync script
# File: /usr/local/bin/backup-s3-sermons.sh

DATE=$(date +%Y%m%d_%H%M%S)
SOURCE_BUCKET="your-sermon-storage-bucket"
BACKUP_BUCKET="your-sermon-backup-bucket"

# Sync to backup bucket
aws s3 sync s3://$SOURCE_BUCKET s3://$BACKUP_BUCKET/backup_$DATE/

echo "S3 backup completed: backup_$DATE"
```

### Cron Configuration

Add to crontab (`sudo crontab -e`):

```bash
# Daily database backup at 2 AM
0 2 * * * /usr/local/bin/backup-sermon-db.sh >> /var/log/crockenhill-backup.log 2>&1

# Daily transcript backup at 2:30 AM
30 2 * * * /usr/local/bin/backup-transcripts.sh >> /var/log/crockenhill-backup.log 2>&1

# Weekly S3 backup on Sundays at 3 AM
0 3 * * 0 /usr/local/bin/backup-s3-sermons.sh >> /var/log/crockenhill-backup.log 2>&1
```

### Recovery Procedures

#### Database Recovery

```bash
# Restore from backup
gunzip /var/backups/crockenhill/sermon_db_YYYYMMDD_HHMMSS.sql.gz
mysql -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD $DB_NAME < /var/backups/crockenhill/sermon_db_YYYYMMDD_HHMMSS.sql
```

#### Transcript Recovery

```bash
# Restore transcripts
cd /var/www/crockenhill/storage/app/transcripts
tar -xzf /var/backups/crockenhill/transcripts_YYYYMMDD_HHMMSS.tar.gz
chown -R www-data:www-data .
```

#### Failed Processing Recovery

```bash
# Retry all failed processing jobs
php artisan tinker
>>> $failed = \App\Models\SermonProcessingLog::where('status', 'failed')->get();
>>> foreach($failed as $log) { \App\Services\SermonProcessingService::retryProcessing($log->processing_id); }
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

3. **Restart Queue Workers**
   ```bash
   sudo supervisorctl restart crockenhill-sermon-worker:*
   sudo supervisorctl restart crockenhill-default-worker:*
   ```

4. **Clear Application Caches**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

5. **Verify Health Check**
   ```bash
   curl -H "Authorization: Bearer your-api-token" https://your-domain.com/api/sermons/processing/health
   ```

### Post-Deployment

- [ ] Monitor queue workers for 30 minutes
- [ ] Test sermon upload functionality
- [ ] Verify processing pipeline works end-to-end
- [ ] Check error logs for any issues
- [ ] Confirm monitoring and alerting is working
- [ ] Update documentation if needed

### Rollback Plan

If issues occur:

1. **Stop Queue Workers**
   ```bash
   sudo supervisorctl stop crockenhill-sermon-worker:*
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

4. **Restart Services**
   ```bash
   sudo supervisorctl start crockenhill-sermon-worker:*
   ```

---

## Performance Optimization

### Queue Configuration

```bash
# Optimize queue worker performance
php artisan queue:work redis \
  --queue=sermon-processing \
  --sleep=3 \
  --tries=3 \
  --max-time=3600 \
  --timeout=1800 \
  --memory=512 \
  --max-jobs=100
```

### Redis Configuration

Add to `/etc/redis/redis.conf`:

```
# Optimize for queue workload
maxmemory 2gb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
```

### PHP Configuration

Optimize PHP settings for large file uploads:

```ini
# /etc/php/8.2/fpm/php.ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 512M
max_input_time = 300
```

### Web Server Configuration

#### Nginx Configuration

```nginx
# Increase client body size for file uploads
client_max_body_size 100M;

# Increase timeouts for processing
proxy_read_timeout 300s;
proxy_connect_timeout 75s;
proxy_send_timeout 300s;
```

#### Apache Configuration

```apache
# Increase limits for file uploads
LimitRequestBody 104857600  # 100MB
TimeOut 300
```

---

## Security Considerations

### API Security

- Use strong API tokens with appropriate scopes
- Implement rate limiting to prevent abuse
- Validate all file uploads thoroughly
- Use HTTPS for all API communications
- Regularly rotate API keys

### File Security

- Store uploaded files outside web root
- Implement virus scanning if possible
- Use secure file naming conventions
- Set appropriate file permissions
- Regularly audit file storage

### Environment Security

- Use environment-specific configuration
- Secure environment variable storage
- Implement proper access controls
- Regular security updates
- Monitor for suspicious activity

---

## Troubleshooting

### Common Issues

1. **Queue Workers Not Processing**
   ```bash
   # Check worker status
   sudo supervisorctl status
   
   # Check worker logs
   sudo tail -f /var/log/supervisor/crockenhill-sermon-worker.log
   
   # Restart workers
   sudo supervisorctl restart crockenhill-sermon-worker:*
   ```

2. **OpenAI API Errors**
   ```bash
   # Check API key
   php artisan tinker
   >>> config('openai.api_key')
   
   # Test API connection
   >>> $client = OpenAI::client(config('openai.api_key'));
   >>> $response = $client->models()->list();
   ```

3. **Storage Issues**
   ```bash
   # Check storage permissions
   ls -la storage/app/
   
   # Fix permissions
   chown -R www-data:www-data storage/
   chmod -R 755 storage/
   ```

4. **Database Connection Issues**
   ```bash
   # Test database connection
   php artisan tinker
   >>> \DB::connection()->getPdo();
   ```

### Log Locations

- Application logs: `/var/www/crockenhill/storage/logs/laravel.log`
- Queue worker logs: `/var/log/supervisor/crockenhill-sermon-worker.log`
- Web server logs: `/var/log/nginx/error.log` or `/var/log/apache2/error.log`
- System logs: `/var/log/syslog`

### Performance Monitoring

```bash
# Monitor queue depth
php artisan queue:monitor redis:sermon-processing --max=50

# Check failed jobs
php artisan queue:failed

# Monitor system resources
htop
iostat -x 1
```

This deployment configuration provides a comprehensive foundation for running the automated sermon processing feature in production with proper monitoring, backup, and recovery procedures.