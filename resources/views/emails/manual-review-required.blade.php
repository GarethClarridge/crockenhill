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
1. Follow the link below to open the review page
2. Inspect the detected segments and choose the correct sermon segment
3. Click "This is the sermon" on the matching speech segment to resume processing

<x-mail::button :url="$reviewUrl">
Review and Confirm Sermon
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} System
</x-mail::message>