@php
    $morningTitle = 'Sunday Morning Sermons';
    $eveningTitle = 'Sunday Evening Sermons';

    // Specialized titles for service filter pages as per project memory
    if (request()->routeIs('sermons.service')) {
        $service = request()->route('service');
        if ($service === 'morning') {
            $morningTitle = 'Sunday Morning Services Podcast';
        } elseif ($service === 'evening') {
            $eveningTitle = 'Sunday Evening Services Podcast';
        }
    }
@endphp

<link rel="alternate" type="application/rss+xml" title="{{ $morningTitle }}" href="{{ route('podcast.feed', 'morning') }}">
<link rel="alternate" type="application/rss+xml" title="{{ $eveningTitle }}" href="{{ route('podcast.feed', 'evening') }}">
