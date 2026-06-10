@props([
    'event',
    'meeting' => null,
    'showDate' => true,
    'showMeetingBadge' => true,
    'showDescription' => true,
    'descriptionLimit' => 150,
    'dateFormat' => 'M j, Y',
    'timeFormat' => 'g:i A',
    'iconSize' => 'h-4 w-4',
])

@php
$meeting = $meeting ?? $event->meeting;
$isUncategorized = $event->meeting_slug === null;
$meetingLabel = $event->meeting_slug ?? 'Uncategorised';
@endphp

<div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 mb-3">
    @if($showDate)
        <div class="flex items-center">
            <x-heroicon-o-calendar class="{{ $iconSize }} mr-1" aria-hidden="true" />
            {{ $event->start_datetime->format($dateFormat) }}
        </div>
    @endif

    <div class="flex items-center">
        <x-heroicon-o-clock class="{{ $iconSize }} mr-1" aria-hidden="true" />
        {{ $event->start_datetime->format($timeFormat) }}
        @if($event->end_datetime)
            - {{ $event->end_datetime->format($timeFormat) }}
        @endif
    </div>

    @if($event->location)
        <div class="flex items-center">
            <x-heroicon-o-map-pin class="{{ $iconSize }} mr-1" aria-hidden="true" />
            {{ $event->location }}
        </div>
    @endif

    @if($event->speaker)
        <div class="flex items-center">
            <x-heroicon-o-user class="{{ $iconSize }} mr-1" aria-hidden="true" />
            {{ $event->speaker }}
        </div>
    @endif
</div>

@if($showDescription && $event->description)
    <p class="text-sm text-gray-700 mb-3">{{ Str::limit($event->description, $descriptionLimit) }}</p>
@endif

@if($showMeetingBadge)
    <div class="flex items-center">
        @if($meeting)
            <a href="/community/{{ $meeting->slug }}" wire:navigate class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 transition-colors">
                {{ $meeting->slug }}
            </a>
        @else
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $isUncategorized ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' }}">
                @if($isUncategorized)
                    <x-heroicon-s-question-mark-circle class="h-3 w-3 mr-1" aria-hidden="true" />
                @endif
                {{ $meetingLabel }}
            </span>
        @endif
    </div>
@endif
