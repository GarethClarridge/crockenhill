<x-mail::message>
# Manual Review Required

A livestream processing job requires manual review to proceed.

**Processing ID:** {{ $processingId }}

**Reason:** {{ $reason }}

**Segments Found:** {{ $segmentCount }}

## Segment Details
@if (count($segments) > 0)
@foreach ($segments as $index => $segment)
- **Segment {{ $index + 1 }}:** {{ gmdate('H:i:s', $segment['start_time'] ?? 0) }} - {{ gmdate('H:i:s', $segment['end_time'] ?? 0) }} ({{ $segment['classification'] ?? 'unknown' }})
@endforeach
@else
No segments were automatically identified.
@endif

## Next Steps
1. Review the livestream file manually
2. Identify the sermon portion
3. Either adjust the segmentation parameters or process manually
4. Update the processing status once resolved

<x-mail::button :url="config('app.url') . '/admin/livestream-processing/' . $processingId">
Review Processing
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>