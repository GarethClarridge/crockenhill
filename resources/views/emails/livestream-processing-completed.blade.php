<x-mail::message>
# Livestream Processing Completed

A livestream processing job has completed successfully.

**Processing ID:** {{ $processingId }}

## Summary
@if (isset($summary['sermon_id']))
- **Sermon Created:** ID {{ $summary['sermon_id'] }}
@endif
@if (isset($summary['segments_processed']))
- **Segments Processed:** {{ $summary['segments_processed'] }}
@endif
@if (isset($summary['processing_duration']))
- **Processing Duration:** {{ gmdate('H:i:s', $summary['processing_duration']) }}
@endif
@if (isset($summary['video_stored']) && $summary['video_stored'])
- **Video:** Successfully stored
@endif
@if (isset($summary['audio_processed']) && $summary['audio_processed'])
- **Audio:** Successfully processed
@endif

<x-mail::button :url="config('app.url') . '/admin/livestream-processing/' . $processingId">
View Results
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>