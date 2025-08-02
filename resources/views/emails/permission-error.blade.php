<x-mail::message>
# File Permission Error

A livestream processing job has failed due to file permission issues.

**Processing ID:** {{ $processingId }}

**Operation:** {{ $operation }}

## Issue
The system encountered a permission error while trying to perform the operation: **{{ $operation }}**

### Common Causes:
- Incorrect file ownership
- Missing write permissions on directories
- SELinux or security policies blocking access
- Disk mounted as read-only

### Resolution Steps:
1. **Check file ownership** on livestream directories
2. **Verify write permissions** on:
   - `/storage/app/livestreams/`
   - `/storage/app/temp/`
   - `/storage/app/sermons/`
3. **Review server security policies**
4. **Ensure proper user/group permissions** for the web server

### Commands to Check:
```bash
ls -la /path/to/storage/app/
chown -R www-data:www-data /path/to/storage/
chmod -R 755 /path/to/storage/
```

<x-mail::button :url="config('app.url') . '/admin/livestream-processing/' . $processingId">
View Processing Details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>