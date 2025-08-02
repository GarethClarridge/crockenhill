<x-mail::message>
# Livestream Processing Failed

A livestream processing job has failed and requires attention.

**Processing ID:** {{ $processingId }}

**Failed Step:** {{ $step }}

**Error Message:** {{ $errorMessage }}

**File:** {{ $file }}:{{ $line }}

## Stack Trace
```
{{ $stackTrace }}
```

## Next Steps
1. Check the processing logs for more details
2. Verify system resources and dependencies
3. Retry processing if the error appears to be temporary
4. Contact technical support if the issue persists

<x-mail::button :url="config('app.url') . '/admin/livestream-processing/' . $processingId">
View Processing Details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>