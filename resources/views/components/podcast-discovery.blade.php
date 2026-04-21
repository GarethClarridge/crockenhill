@php
    $currentService = request()->route('service');
    $isMorningPage = request()->routeIs('sermons.service') && $currentService === 'morning';
    $isEveningPage = request()->routeIs('sermons.service') && $currentService === 'evening';

    $morningTitle = $isMorningPage ? 'Sunday Morning Services Podcast' : 'Sunday Morning Sermons';
    $eveningTitle = $isEveningPage ? 'Sunday Evening Services Podcast' : 'Sunday Evening Sermons';
@endphp

<link rel="alternate" type="application/rss+xml" title="{{ $morningTitle }}" href="{{ route('podcast.feed', 'morning') }}">
<link rel="alternate" type="application/rss+xml" title="{{ $eveningTitle }}" href="{{ route('podcast.feed', 'evening') }}">
