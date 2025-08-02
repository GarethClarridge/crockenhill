# Livestream Processing Operations Runbook

## Overview

This runbook provides step-by-step procedures for operating, troubleshooting, and maintaining the livestream video processing system. It's designed for system administrators and operations teams.

## Quick Reference

### Emergency Contacts

- **System Administrator:** [Your contact info]
- **Development Team:** [Team contact info]
- **Infrastructure Team:** [Infrastructure contact info]

### Critical System Information

- **Application:** Crockenhill Baptist Church Website
- **Feature:** Livestream Video Processing
- **Environment:** [Production/Staging]
- **Server:** [Server details]
- **Database:** [Database details]

### Key Directories

```
/path/to/app/storage/app/livestreams/     # Temporary processing files
/path/to/app/storage/app/sermons/         # Final sermon videos
/path/to/app/storage/logs/                # Application logs
/var/log/nginx/                           # Web server logs
/var/log/redis/                           # Queue logs
```

## Standard Operating Procedures

### 1. Daily Health Checks

#### Morning Health Check (5 minutes)

```bash
#!/bin/bash
# Daily health check script

echo "=== Livestream Processing Health Check ==="
echo "Date: $(date)"
echo

# Check application health
echo "1. Application Health:"
php artisan health:check

# Check queue status
echo "2. Queue Status:"
php artisan queue:monitor redis:livestream --max=10

# Check disk space
echo "3. Disk Space:"
df -h | grep -E "(livestream|sermon)"

# Check recent processing
echo "4. Recent Processing (last 24h):"
php artisan livestream:stats --hours=24

# Check for errors
echo "5. Recent Errors:"
grep -c "ERROR.*livestream" storage/logs/laravel-$(date +%Y-%m-%d).log

echo "=== Health Check Complete ==="
```

#### Weekly Health Check (15 minutes)

```bash
#!/bin/bash
# Weekly health check script

echo "=== Weekly Livestream Processing Review ==="
echo "Week ending: $(date)"
echo

# Processing statistics
echo "1. Weekly Statistics:"
php artisan livestream:stats --days=7

# Storage usage
echo "2. Storage Usage:"
du -sh storage/app/livestreams/
du -sh storage/app/sermons/

# Failed jobs review
echo "3. Failed Jobs:"
php artisan queue:failed | head -20

# Performance metrics
echo "4. Performance Metrics:"
grep "execution_time" storage/logs/laravel-*.log | tail -50 | awk '{print $NF}' | sort -n

echo "=== Weekly Review Complete ==="
```

### 2. Processing Monitoring

#### Check Processing Status

```bash
# Check specific processing job
php artisan livestream:status <processing-id>

# List active processing jobs
php artisan queue:work --once --queue=livestream

# Monitor queue in real-time
watch -n 5 'php artisan queue:monitor redis:livestream'
```

#### Monitor System Resources

```bash
# CPU usage
top -p $(pgrep -d',' -f 'php.*queue:work')

# Memory usage
ps aux --sort=-%mem | grep -E "(php|ffmpeg)" | head -10

# Disk I/O
iotop -a -o -d 1

# Network usage (if using remote storage)
nethogs
```

### 3. File Management

#### Clean Up Temporary Files

```bash
# Manual cleanup of old temporary files
find storage/app/temp/livestreams -type f -mtime +1 -delete
find storage/app/temp/livestreams -type d -empty -delete

# Check cleanup results
du -sh storage/app/temp/livestreams/
```

#### Archive Old Sermons

```bash
# Archive sermons older than 1 year
php artisan livestream:archive --days=365

# Backup to external storage
rsync -av storage/app/sermons/ /backup/sermons/$(date +%Y%m%d)/
```

## Troubleshooting Procedures

### 1. Processing Failures

#### Symptom: Processing Stuck in "Processing" Status

**Diagnosis Steps:**

1. Check if FFmpeg process is running:
   ```bash
   ps aux | grep ffmpeg
   ```

2. Check system resources:
   ```bash
   free -h
   df -h
   ```

3. Check queue worker status:
   ```bash
   ps aux | grep "queue:work"
   ```

**Resolution Steps:**

1. **If FFmpeg is consuming too much memory:**
   ```bash
   # Kill FFmpeg processes
   pkill -f ffmpeg
   
   # Restart queue workers
   sudo systemctl restart laravel-worker
   ```

2. **If disk space is full:**
   ```bash
   # Clean up temporary files
   find storage/app/temp -type f -mtime +0.5 -delete
   
   # Check space again
   df -h
   ```

3. **If queue worker is stuck:**
   ```bash
   # Restart queue workers
   sudo systemctl restart laravel-worker
   
   # Check worker logs
   journalctl -u laravel-worker -f
   ```

#### Symptom: "No Sermon Found" Error

**Diagnosis Steps:**

1. Check RMS threshold configuration:
   ```bash
   grep "RMS_THRESHOLD" .env
   ```

2. Review segment analysis:
   ```bash
   php artisan livestream:analyze <processing-id>
   ```

**Resolution Steps:**

1. **Adjust RMS threshold:**
   ```bash
   # For quieter recordings
   sed -i 's/LIVESTREAM_RMS_THRESHOLD=.*/LIVESTREAM_RMS_THRESHOLD=-35.0/' .env
   
   # Restart application
   php artisan config:clear
   ```

2. **Manual segment review:**
   ```bash
   # Review segments manually
   php artisan livestream:segments <processing-id>
   
   # Mark specific segment as sermon
   php artisan livestream:mark-sermon <processing-id> <segment-index>
   ```

#### Symptom: FFmpeg Command Failures

**Diagnosis Steps:**

1. Check FFmpeg installation:
   ```bash
   which ffmpeg
   ffmpeg -version
   ```

2. Check file permissions:
   ```bash
   ls -la storage/app/livestreams/<processing-id>/
   ```

3. Test FFmpeg manually:
   ```bash
   ffmpeg -i storage/app/livestreams/<processing-id>/original.mp4 -t 10 test.mp4
   ```

**Resolution Steps:**

1. **Reinstall FFmpeg:**
   ```bash
   sudo apt update
   sudo apt install --reinstall ffmpeg
   ```

2. **Fix permissions:**
   ```bash
   chown -R www-data:www-data storage/app/livestreams/
   chmod -R 755 storage/app/livestreams/
   ```

3. **Update FFmpeg path:**
   ```bash
   # Find correct path
   which ffmpeg
   
   # Update .env
   sed -i 's|FFMPEG_PATH=.*|FFMPEG_PATH=/usr/bin/ffmpeg|' .env
   ```

### 2. Storage Issues

#### Symptom: Disk Space Full

**Immediate Actions:**

1. **Emergency cleanup:**
   ```bash
   # Remove old temporary files
   find storage/app/temp -type f -mtime +0.1 -delete
   
   # Remove old log files
   find storage/logs -name "*.log" -mtime +7 -delete
   
   # Check space
   df -h
   ```

2. **Identify large files:**
   ```bash
   # Find largest files
   find storage/app -type f -size +100M -exec ls -lh {} \;
   
   # Check directory sizes
   du -sh storage/app/*/ | sort -hr
   ```

**Long-term Solutions:**

1. **Implement retention policy:**
   ```bash
   # Add to crontab
   echo "0 2 * * * find /path/to/app/storage/app/temp -type f -mtime +1 -delete" | crontab -
   ```

2. **Move to external storage:**
   ```bash
   # Configure S3 storage
   aws configure
   
   # Update .env
   echo "LIVESTREAM_SERMON_DISK=s3" >> .env
   ```

#### Symptom: File Permission Errors

**Diagnosis:**
```bash
# Check file ownership
ls -la storage/app/livestreams/

# Check process user
ps aux | grep "queue:work" | head -1
```

**Resolution:**
```bash
# Fix ownership
sudo chown -R www-data:www-data storage/app/

# Fix permissions
sudo chmod -R 755 storage/app/

# Restart services
sudo systemctl restart nginx
sudo systemctl restart laravel-worker
```

### 3. Queue Issues

#### Symptom: Jobs Not Processing

**Diagnosis Steps:**

1. Check Redis connection:
   ```bash
   redis-cli ping
   ```

2. Check queue workers:
   ```bash
   ps aux | grep "queue:work"
   ```

3. Check queue size:
   ```bash
   redis-cli llen queues:livestream
   ```

**Resolution Steps:**

1. **Restart Redis:**
   ```bash
   sudo systemctl restart redis-server
   ```

2. **Restart queue workers:**
   ```bash
   sudo systemctl restart laravel-worker
   ```

3. **Clear stuck jobs:**
   ```bash
   php artisan queue:flush
   ```

#### Symptom: Too Many Failed Jobs

**Analysis:**
```bash
# Review failed jobs
php artisan queue:failed

# Check failure patterns
php artisan queue:failed | grep -E "(Exception|Error)" | sort | uniq -c
```

**Resolution:**
```bash
# Retry specific job
php artisan queue:retry <job-id>

# Retry all failed jobs
php artisan queue:retry all

# Clear old failed jobs
php artisan queue:flush
```

### 4. Performance Issues

#### Symptom: Slow Processing

**Diagnosis:**

1. **Check system load:**
   ```bash
   uptime
   htop
   ```

2. **Check I/O wait:**
   ```bash
   iostat -x 1 5
   ```

3. **Profile processing:**
   ```bash
   # Enable query logging
   echo "DB_LOG_QUERIES=true" >> .env
   
   # Monitor processing time
   tail -f storage/logs/laravel.log | grep "execution_time"
   ```

**Optimization:**

1. **Increase worker count:**
   ```bash
   # Update systemd service
   sudo nano /etc/systemd/system/laravel-worker.service
   
   # Add multiple workers
   ExecStart=/usr/bin/php /path/to/app/artisan queue:work redis --queue=livestream --processes=4
   ```

2. **Optimize FFmpeg:**
   ```bash
   # Use hardware acceleration (if available)
   ffmpeg -hwaccels
   
   # Update processing to use GPU
   sed -i 's/FFMPEG_PARAMS=.*/FFMPEG_PARAMS="-hwaccel cuda"/' .env
   ```

## Recovery Procedures

### 1. Complete System Recovery

#### After Server Crash

1. **Verify system status:**
   ```bash
   # Check services
   systemctl status nginx
   systemctl status redis-server
   systemctl status laravel-worker
   
   # Check database
   mysql -u user -p -e "SELECT 1"
   ```

2. **Restart services:**
   ```bash
   sudo systemctl start redis-server
   sudo systemctl start nginx
   sudo systemctl start laravel-worker
   ```

3. **Verify application:**
   ```bash
   # Test health endpoint
   curl -f http://localhost/health
   
   # Check queue processing
   php artisan queue:work --once
   ```

#### After Database Corruption

1. **Restore from backup:**
   ```bash
   # Stop application
   sudo systemctl stop laravel-worker
   
   # Restore database
   mysql -u user -p database < backup_latest.sql
   
   # Run migrations
   php artisan migrate
   
   # Restart services
   sudo systemctl start laravel-worker
   ```

### 2. Data Recovery

#### Recover Lost Processing Data

1. **Check for temporary files:**
   ```bash
   find storage/app/temp/livestreams -name "*processing-id*" -type d
   ```

2. **Recreate processing record:**
   ```bash
   php artisan livestream:recover <processing-id>
   ```

3. **Manual processing:**
   ```bash
   # Extract sermon manually
   php artisan livestream:extract <processing-id> --segment=<segment-index>
   ```

#### Recover Corrupted Videos

1. **Check file integrity:**
   ```bash
   ffprobe storage/app/sermons/<sermon-id>/video.mp4
   ```

2. **Attempt repair:**
   ```bash
   ffmpeg -i corrupted.mp4 -c copy repaired.mp4
   ```

3. **Restore from backup:**
   ```bash
   cp /backup/sermons/<sermon-id>/video.mp4 storage/app/sermons/<sermon-id>/
   ```

## Maintenance Procedures

### 1. Routine Maintenance

#### Daily Tasks (Automated)

Create `/etc/cron.d/livestream-daily`:
```bash
# Daily cleanup at 2 AM
0 2 * * * www-data /usr/bin/php /path/to/app/artisan livestream:cleanup

# Daily health check at 6 AM
0 6 * * * www-data /path/to/scripts/daily-health-check.sh >> /var/log/livestream-health.log
```

#### Weekly Tasks (Manual)

1. **Review processing statistics:**
   ```bash
   php artisan livestream:stats --days=7
   ```

2. **Check storage usage:**
   ```bash
   du -sh storage/app/sermons/
   df -h
   ```

3. **Review failed jobs:**
   ```bash
   php artisan queue:failed | head -20
   ```

4. **Update system packages:**
   ```bash
   sudo apt update
   sudo apt list --upgradable
   sudo apt upgrade
   ```

#### Monthly Tasks

1. **Performance review:**
   ```bash
   # Generate performance report
   php artisan livestream:performance-report --month
   ```

2. **Storage optimization:**
   ```bash
   # Archive old sermons
   php artisan livestream:archive --days=90
   
   # Compress old videos
   php artisan livestream:compress --days=180
   ```

3. **Security updates:**
   ```bash
   # Update dependencies
   composer update
   
   # Check for security advisories
   composer audit
   ```

### 2. Capacity Planning

#### Monitor Growth Trends

```bash
# Storage growth
echo "Date,Livestreams,Sermons,Total" > storage_growth.csv
du -sb storage/app/livestreams/ storage/app/sermons/ | awk '{print strftime("%Y-%m-%d")","$1}' >> storage_growth.csv

# Processing volume
php artisan livestream:stats --format=csv --days=30 >> processing_volume.csv
```

#### Scaling Recommendations

1. **Storage scaling:**
   - Monitor disk usage trends
   - Plan for 20% growth buffer
   - Consider cloud storage for archives

2. **Processing scaling:**
   - Monitor queue depth
   - Add workers when average queue > 5
   - Consider dedicated processing servers

3. **Database scaling:**
   - Monitor query performance
   - Add indexes for slow queries
   - Consider read replicas for reporting

## Alerting and Notifications

### 1. Critical Alerts

Set up monitoring for:

- **Disk space < 10%**
- **Queue depth > 20 jobs**
- **Processing failures > 5 per hour**
- **FFmpeg crashes**
- **Database connection failures**

### 2. Warning Alerts

Monitor for:

- **Disk space < 20%**
- **Queue depth > 10 jobs**
- **Processing time > 2 hours**
- **Memory usage > 80%**

### 3. Alert Configuration

#### Using Laravel Health Checks

```php
// config/health.php
'notifications' => [
    'mail' => [
        'to' => 'admin@your-domain.com',
        'subject' => 'Livestream Processing Health Alert',
    ],
    'slack' => [
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
        'channel' => '#alerts',
    ],
],
```

#### Using External Monitoring

```bash
# Nagios check
/usr/lib/nagios/plugins/check_http -H localhost -u /health -s "healthy"

# Prometheus metrics
curl http://localhost/metrics | grep livestream_
```

## Documentation Updates

### When to Update This Runbook

- After system changes or upgrades
- When new issues are discovered
- After major incidents
- Quarterly review and updates

### Change Log

| Date | Version | Changes | Author |
|------|---------|---------|--------|
| 2024-01-15 | 1.0 | Initial version | System Admin |
| | | | |

## Contact Information

### Escalation Path

1. **Level 1:** System Administrator
2. **Level 2:** Development Team Lead
3. **Level 3:** Infrastructure Team
4. **Level 4:** External Support

### Emergency Procedures

For critical system failures:

1. **Immediate:** Stop processing to prevent data loss
2. **Assess:** Determine scope and impact
3. **Communicate:** Notify stakeholders
4. **Resolve:** Follow recovery procedures
5. **Document:** Record incident details
6. **Review:** Post-incident analysis

This runbook should be reviewed and updated regularly to ensure accuracy and completeness. Keep it accessible to all operations team members and ensure they are trained on these procedures.