<x-mail::message>
# Livestream Processing Failed

A livestream processing job has failed and requires attention.

**Processing ID:** {{ $processingId }}

**Failed Step:** {{ $step }}

**Error Message:** {{ $errorMessage }}

**File:** {{ $file }}:{{ $line }}

@if($metadata && isset($metadata['audio_compression']))
## Audio Compression Details
**Original Size:** {{ $metadata['audio_compression']['original_size_mb'] }}MB
**Final Size:** {{ $metadata['audio_compression']['final_size_mb'] }}MB
**Compression Applied:** {{ $metadata['audio_compression']['compression_applied'] ? 'Yes' : 'No' }}
@if($metadata['audio_compression']['compression_applied'])
**Compression Ratio:** {{ $metadata['audio_compression']['compression_ratio'] }}:1
@endif
**Valid for Transcription:** {{ $metadata['audio_compression']['valid_for_transcription'] ? 'Yes' : 'No' }}
@endif

## Stack Trace
```
{{ $stackTrace }}
```

## Next Steps
1. Check the processing logs for more details
2. Verify system resources and dependencies
@if($metadata && isset($metadata['audio_compression']) && !$metadata['audio_compression']['valid_for_transcription'])
3. **Audio file is too large for transcription** - Consider uploading a shorter video segment or improving compression settings
@endif
3. Retry processing if the error appears to be temporary
4. Contact technical support if the issue persists

<x-mail::button :url="config('app.url') . '/admin/livestream-processing/' . $processingId">
View Processing Details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>