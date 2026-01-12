# Production Email Setup Guide

## Overview

This guide walks through configuring transactional email for production using Mailgun. The system sends automated notifications for sermon processing events (failures, completions, errors).

## Prerequisites

- Production server with Laravel application deployed
- Admin email address for receiving notifications
- SSH access to production server

## Step 1: Sign Up for Mailgun

1. Go to [mailgun.com](https://mailgun.com)
2. Create a free account
3. Free tier includes **100 emails/day** (sufficient for church website notifications)

## Step 2: Get Mailgun Credentials

### Domain Configuration

1. Navigate to: https://app.mailgun.com/app/sending/domains
2. **Option A - Sandbox Domain (Testing):**
   - Use the provided sandbox domain (e.g., `sandboxXXXXX.mailgun.org`)
   - Limited to authorized recipients only
   - Good for initial testing

3. **Option B - Custom Domain (Production):**
   - Click "Add New Domain"
   - Add a subdomain like `mg.crockenhill.org`
   - Follow DNS verification steps:
     - Add TXT records for domain verification
     - Add MX records for receiving
     - Add CNAME records for tracking
   - Wait for DNS propagation (can take up to 48 hours)

4. Copy your domain name (will be used in `.env`)

### API Key

1. Navigate to: https://app.mailgun.com/settings/api_security
2. Locate "Private API key" section
3. Copy the key (starts with `key-...`)
4. **Keep this secret** - never commit to version control

## Step 3: Configure Production Environment

SSH into your production server and edit the `.env` file:

```bash
# Navigate to application directory
cd /path/to/your/application

# Edit environment file
nano .env
```

Add/update these configuration values:

```env
# Mail Configuration
MAIL_MAILER=mailgun
MAIL_FROM_ADDRESS=admin@crockenhill.org
MAIL_FROM_NAME="Crockenhill Baptist Church"

# Mailgun Credentials
MAILGUN_DOMAIN=mg.crockenhill.org
MAILGUN_SECRET=key-your-actual-api-key-here
MAILGUN_ENDPOINT=api.mailgun.net

# Admin Email for Notifications
LIVESTREAM_ADMIN_EMAIL=admin@crockenhill.org
LIVESTREAM_NOTIFY_SUCCESS=false
LIVESTREAM_NOTIFY_FAILURE=true
```

**Configuration Notes:**
- `MAIL_MAILER=mailgun` - Use Mailgun transport (not SMTP)
- `MAILGUN_DOMAIN` - Your verified Mailgun domain
- `MAILGUN_SECRET` - Your private API key from Mailgun dashboard
- `LIVESTREAM_ADMIN_EMAIL` - Email address that receives all processing alerts
- `LIVESTREAM_NOTIFY_SUCCESS` - Toggle success notifications (default: false to reduce email volume)
- `LIVESTREAM_NOTIFY_FAILURE` - Toggle failure notifications (default: true - always get error alerts)

## Step 4: Clear Configuration Cache

After updating `.env`, clear Laravel's cached configuration:

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

## Step 5: Test Email Configuration

### Verify Configuration

```bash
php artisan tinker
```

```php
// Check configuration is loaded
echo config('mail.default'); // Should output: mailgun
echo config('services.mailgun.domain'); // Should output: your domain
echo config('media-processing.email.admin_email'); // Should output: admin email
exit
```

### Send Test Email

```bash
php artisan tinker
```

```php
use App\Mail\LivestreamProcessingCompleted;
use Illuminate\Support\Facades\Mail;

Mail::to('admin@crockenhill.org')->send(new LivestreamProcessingCompleted('test-123', [
    'sermon_count' => 2,
    'total_duration' => '1:23:45'
]));

echo 'Test email sent!';
exit
```

### Verify Delivery

1. **Check inbox** - Email should arrive at `admin@crockenhill.org` within 1-2 minutes
2. **Check Mailgun logs:**
   - Go to https://app.mailgun.com/app/sending/domains
   - Click on your domain
   - Click "Logs" tab
   - Verify test email shows "Delivered" status

## Step 6: Monitor Email Delivery

### Mailgun Dashboard

Monitor email delivery from Mailgun dashboard:
- **Logs**: View all sent emails and delivery status
- **Events**: See opens, clicks, bounces, complaints
- **Analytics**: Track email volume and deliverability

### Application Logs

Email sending is logged in Laravel logs:

```bash
# View recent logs
tail -f storage/logs/laravel.log | grep -i mail

# Check for email errors
grep "Failed to queue.*email" storage/logs/laravel.log
```

## Email Notification Events

The system automatically sends emails for these events:

| Event | Email Class | Recipient | Trigger |
|-------|-------------|-----------|---------|
| Disk space critical | `DiskSpaceWarning` | Admin | Insufficient disk space detected |
| File permission error | `PermissionError` | Admin | Cannot read/write files |
| Processing failure | `LivestreamProcessingFailed` | Admin | Livestream processing error |
| Manual review needed | `ManualReviewRequired` | Admin | Segmentation requires review |
| Processing complete | `LivestreamProcessingCompleted` | Admin | Success (if enabled) |
| Sermon uploaded | `SendCompletionNotification` | All users | Every sermon completion |

## Configuration Reference

### Environment Variables

```env
# Core mail settings
MAIL_MAILER=mailgun                              # Email driver (mailgun for production)
MAIL_FROM_ADDRESS=admin@crockenhill.org          # Sender email
MAIL_FROM_NAME="Crockenhill Baptist Church"     # Sender name

# Mailgun credentials
MAILGUN_DOMAIN=mg.crockenhill.org                # Mailgun domain
MAILGUN_SECRET=key-xxxxx                         # Mailgun API key
MAILGUN_ENDPOINT=api.mailgun.net                 # Mailgun API endpoint (US region)

# Notification settings
LIVESTREAM_ADMIN_EMAIL=admin@crockenhill.org     # Admin notification recipient
LIVESTREAM_NOTIFY_SUCCESS=false                  # Send success notifications
LIVESTREAM_NOTIFY_FAILURE=true                   # Send failure notifications
```

### Config Files

- `config/mail.php` - Mail driver configuration
- `config/services.php` - Mailgun service credentials
- `config/media-processing.php` - Email notification settings (lines 93-97)

### Email Queueing

All emails use `Mail::queue()` for async delivery:

```php
// Emails are queued for background processing
Mail::to($admin)->queue(new DiskSpaceWarning($processingId));
```

**Queue Configuration:**
- `QUEUE_DRIVER=sync` (default) - Processes jobs immediately, no worker needed
- `QUEUE_DRIVER=database` - Queues jobs in database, requires `php artisan queue:work` daemon

## Troubleshooting

### Email Not Sending

1. **Check Mailgun credentials:**
   ```bash
   php artisan tinker
   >>> config('services.mailgun.domain')
   >>> config('services.mailgun.secret')
   ```

2. **Verify domain verification:**
   - Go to Mailgun dashboard
   - Check domain status is "Active" not "Unverified"

3. **Check Laravel logs:**
   ```bash
   tail -100 storage/logs/laravel.log | grep -i mail
   ```

4. **Test with raw email:**
   ```php
   Mail::raw('Test', fn($m) => $m->to('admin@crockenhill.org')->subject('Test'));
   ```

### Emails Going to Spam

1. **Verify DNS records** - Ensure SPF, DKIM, and DMARC records are configured
2. **Use authenticated domain** - Sandbox domains are more likely to be filtered
3. **Check Mailgun reputation** - View deliverability stats in dashboard
4. **Warm up domain** - Start with low volume, gradually increase

### Delivery Issues

1. **Check Mailgun logs** for bounce/complaint events
2. **Verify recipient email** is valid and accepting mail
3. **Check rate limits** - Free tier: 100 emails/day
4. **Review suppression list** - Mailgun may suppress previously bounced addresses

### Configuration Cache Issues

If changes to `.env` aren't taking effect:

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan queue:restart  # If using queue workers
```

## Security Best Practices

1. **Never commit `.env`** - Ensure `.env` is in `.gitignore`
2. **Rotate API keys** - Periodically regenerate Mailgun API keys
3. **Use environment-specific keys** - Different keys for staging/production
4. **Monitor usage** - Set up alerts for unusual email volume
5. **Restrict access** - Limit who has access to Mailgun dashboard

## Maintenance

### Monthly Tasks

- Review Mailgun usage (ensure under 100 emails/day for free tier)
- Check bounce/complaint rates in Mailgun dashboard
- Verify all notification emails are being delivered

### Quarterly Tasks

- Review and update `LIVESTREAM_ADMIN_EMAIL` if needed
- Test all notification email types
- Review suppression list and clean invalid addresses

## Support

- **Mailgun Documentation**: https://documentation.mailgun.com/
- **Mailgun Support**: support@mailgun.com
- **Laravel Mail Documentation**: https://laravel.com/docs/mail

## Files Modified for Email Configuration

- `.env` - Environment configuration
- `config/mail.php` - Mail driver settings (line 117: `'driver' => null`)
- `config/media-processing.php` - Email notification config (lines 93-97)
- `app/Services/LivestreamErrorHandler.php` - 4 queued notification emails
- `app/Services/LivestreamSegmentationService.php` - Failure notification email
- `app/Jobs/SendCompletionNotification.php` - Sermon completion notification
