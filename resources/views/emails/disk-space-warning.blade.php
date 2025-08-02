<x-mail::message>
# Critical: Disk Space Error

⚠️ **URGENT:** Livestream processing has failed due to insufficient disk space.

**Processing ID:** {{ $processingId }}

## Immediate Action Required
The system cannot continue processing livestreams until disk space is freed up.

### Recommended Actions:
1. **Check disk usage** on the server
2. **Clean up old files** from temporary directories
3. **Archive or delete** old livestream recordings
4. **Review retention policies** for processed files
5. **Consider expanding storage** if this is a recurring issue

### Directories to Check:
- `/storage/app/livestreams/`
- `/storage/app/temp/`
- `/storage/app/sermons/`
- `/storage/logs/`

<x-mail::button :url="config('app.url') . '/admin/system-status'">
Check System Status
</x-mail::button>

**This is a critical system alert. Please address immediately.**

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>