@php
    $currentService = request()->routeIs('sermons.service') ? request()->route('service') : null;

    $morningTitle = 'Sunday Morning Sermons';
    if ($currentService === 'morning') {
        $morningTitle .= ' (Current)';
    }

    $eveningTitle = 'Sunday Evening Sermons';
    if ($currentService === 'evening') {
        $eveningTitle .= ' (Current)';
    }
@endphp

<link rel="alternate" type="application/rss+xml" title="{{ $morningTitle }}" href="{{ route('podcast.feed', 'morning') }}">
<link rel="alternate" type="application/rss+xml" title="{{ $eveningTitle }}" href="{{ route('podcast.feed', 'evening') }}">
