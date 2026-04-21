@props(['service' => null])

@if ($service === 'morning' || $service === null)
<link rel="alternate" type="application/rss+xml" title="Sunday Morning Services Podcast" href="{{ route('podcast.feed', 'morning') }}">
@endif

@if ($service === 'evening' || $service === null)
<link rel="alternate" type="application/rss+xml" title="Sunday Evening Services Podcast" href="{{ route('podcast.feed', 'evening') }}">
@endif
