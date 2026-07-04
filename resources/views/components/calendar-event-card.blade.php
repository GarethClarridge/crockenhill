@props([
    'event',
    'meeting' => null,
    'variant' => 'default', // default, compact, admin, list
    'showDate' => true,
    'showMeetingBadge' => true,
    'showDescription' => true,
    'descriptionLimit' => 150,
    'dateFormat' => 'j M Y',
    'timeFormat' => 'g:ia',
    'headingLevel' => 'h3',
])

@php
$meeting = $meeting ?? $event->meeting;
$isUncategorized = $event->meeting_slug === null;
$meetingLabel = $event->meeting_slug ?? 'Uncategorised';
$cardClasses = match($variant) {
    'compact' => 'bg-gray-50 rounded-lg border border-gray-200 p-4',
    'admin'   => 'max-w-full rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2',
    'list'    => '', // No wrapper for list variant
    default   => 'max-w-sm rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2'
};
@endphp

@if($variant !== 'list')
<div {{ $attributes->merge(['class' => $cardClasses]) }}>
@endif
    @if($variant === 'list' || $variant === 'compact')
        <div {{ $variant === 'list' ? $attributes->merge(['class' => 'flex items-start justify-between']) : $attributes->class(['flex items-start justify-between']) }}>
            <div class="flex-1">
                <{{ $headingLevel }} class="font-medium text-gray-900 mb-2">{{ $event->title }}</{{ $headingLevel }}>

                <x-calendar-event-meta
                    :event="$event"
                    :meeting="$meeting"
                    :showDate="$showDate"
                    :showMeetingBadge="$showMeetingBadge"
                    :showDescription="$showDescription"
                    :descriptionLimit="$descriptionLimit"
                    :dateFormat="$dateFormat"
                    :timeFormat="$timeFormat"
                />
            </div>

            {{ $slot }}
        </div>
    @else
        {{-- Card variant (default or admin) --}}
        <{{ $headingLevel }} class="p-6 mx-6 mt-6 font-display text-4xl">
            {{ $event->title }}
        </{{ $headingLevel }}>

        <ul class="mx-6 px-6 mb-6 pb-6 prose">
            @if($showDate)
                <li class="my-2 flex items-center">
                    <x-heroicon-s-calendar class="h-5 w-5 mr-2" aria-hidden="true" />
                    {{ $event->start_datetime->format($dateFormat) }}
                </li>
            @endif

            <li class="my-2 flex items-center">
                <x-heroicon-o-clock class="h-5 w-5 mr-2" aria-hidden="true" />
                {{ $event->start_datetime->format($timeFormat) }}
                @if($event->end_datetime)
                    - {{ $event->end_datetime->format($timeFormat) }}
                @endif
            </li>

            @if($event->location)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-map-pin class="h-5 w-5 mr-2" aria-hidden="true" />
                    {{ $event->location }}
                </li>
            @endif

            @if($event->speaker)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-user class="h-5 w-5 mr-2" aria-hidden="true" />
                    {{ $event->speaker }}
                </li>
            @endif

            @if($showMeetingBadge)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-tag class="h-5 w-5 mr-2" aria-hidden="true" />
                    @if($meeting)
                        <a href="/community/{{ $meeting->slug }}" wire:navigate class="hover:underline">{{ $meeting->slug }}</a>
                    @else
                        <span class="{{ $isUncategorized ? 'text-yellow-600' : 'text-gray-600' }}">
                            {{ $meetingLabel }}
                        </span>
                    @endif
                </li>
            @endif
        </ul>

        @if($showDescription && $event->description)
            <div class="mx-6 px-6 mb-6 pb-6">
                <p class="text-sm text-gray-700 prose">{{ Str::limit($event->description, $descriptionLimit) }}</p>
            </div>
        @endif

        {{ $slot }}
    @endif
@if($variant !== 'list')
</div>
@endif
