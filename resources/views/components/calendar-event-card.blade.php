@props([
    'event',
    'meeting' => null,
    'variant' => 'default', // default, compact, admin, list
    'showDate' => true,
    'showMeetingBadge' => true,
    'showDescription' => true,
    'descriptionLimit' => 150,
    'dateFormat' => 'M j, Y',
    'timeFormat' => 'g:i A'
])

@php
$meeting = $meeting ?? $event->meeting;
$cardClasses = match($variant) {
    'compact' => 'bg-gray-50 rounded-lg border border-gray-200 p-4',
    'admin' => 'max-w-full rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2',
    'list' => '', // No wrapper for list variant
    default => 'max-w-sm rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2'
};
@endphp

@if($variant !== 'list')
<div class="{{ $cardClasses }}">
@endif
    @if($variant === 'list')
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h4 class="font-medium text-gray-900 mb-2">{{ $event->title }}</h4>
                
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 mb-3">
                    @if($showDate)
                        <div class="flex items-center">
                            <x-heroicon-o-calendar class="h-4 w-4 mr-1" />
                            {{ $event->start_datetime->format($dateFormat) }}
                        </div>
                    @endif
                    
                    <div class="flex items-center">
                        <x-heroicon-o-clock class="h-4 w-4 mr-1" />
                        {{ $event->start_datetime->format($timeFormat) }}
                        @if($event->end_datetime)
                            - {{ $event->end_datetime->format($timeFormat) }}
                        @endif
                    </div>
                    
                    @if($event->location)
                        <div class="flex items-center">
                            <x-heroicon-o-map-pin class="h-4 w-4 mr-1" />
                            {{ $event->location }}
                        </div>
                    @endif
                    
                    @if($event->speaker)
                        <div class="flex items-center">
                            <x-heroicon-o-user class="h-4 w-4 mr-1" />
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
                            <a href="/community/{{ $meeting->slug }}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 transition-colors">
                                {{ $meeting->slug }}
                            </a>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $event->meeting_slug === 'uncategorized' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' }}">
                                @if($event->meeting_slug === 'uncategorized')
                                    <x-heroicon-s-question-mark-circle class="h-3 w-3 mr-1" />
                                @endif
                                {{ $event->meeting_slug }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
            
            {{ $slot }}
        </div>
    @elseif($variant === 'compact')
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h4 class="font-medium text-gray-900 mb-2">{{ $event->title }}</h4>
                
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 mb-3">
                    @if($showDate)
                        <div class="flex items-center">
                            <x-heroicon-o-calendar class="h-4 w-4 mr-1" />
                            {{ $event->start_datetime->format($dateFormat) }}
                        </div>
                    @endif
                    
                    <div class="flex items-center">
                        <x-heroicon-o-clock class="h-4 w-4 mr-1" />
                        {{ $event->start_datetime->format($timeFormat) }}
                        @if($event->end_datetime)
                            - {{ $event->end_datetime->format($timeFormat) }}
                        @endif
                    </div>
                    
                    @if($event->location)
                        <div class="flex items-center">
                            <x-heroicon-o-map-pin class="h-4 w-4 mr-1" />
                            {{ $event->location }}
                        </div>
                    @endif
                    
                    @if($event->speaker)
                        <div class="flex items-center">
                            <x-heroicon-o-user class="h-4 w-4 mr-1" />
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
                            <a href="/community/{{ $meeting->slug }}" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 transition-colors">
                                {{ $meeting->slug }}
                            </a>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $event->meeting_slug === 'uncategorized' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' }}">
                                @if($event->meeting_slug === 'uncategorized')
                                    <x-heroicon-s-question-mark-circle class="h-3 w-3 mr-1" />
                                @endif
                                {{ $event->meeting_slug }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
            
            {{ $slot }}
        </div>
    @else
        {{-- Card variant (default or admin) with sermon-card styling --}}
        <h4 class="p-6 mx-6 mt-6 font-display text-4xl">
            {{ $event->title }}
        </h4>
        
        <ul class="mx-6 px-6 mb-6 pb-6 prose">
            @if($showDate)
                <li class="my-2 flex items-center">
                    <x-heroicon-s-calendar class="h-5 w-5 mr-2" />
                    {{ $event->start_datetime->format($dateFormat) }}
                </li>
            @endif
            
            <li class="my-2 flex items-center">
                <x-heroicon-o-clock class="h-5 w-5 mr-2" />
                {{ $event->start_datetime->format($timeFormat) }}
                @if($event->end_datetime)
                    - {{ $event->end_datetime->format($timeFormat) }}
                @endif
            </li>
            
            @if($event->location)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-map-pin class="h-5 w-5 mr-2" />
                    {{ $event->location }}
                </li>
            @endif
            
            @if($event->speaker)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-user class="h-5 w-5 mr-2" />
                    {{ $event->speaker }}
                </li>
            @endif
            
            @if($showMeetingBadge)
                <li class="my-2 flex items-center">
                    <x-heroicon-o-tag class="h-5 w-5 mr-2" />
                    @if($meeting)
                        <a href="/community/{{ $meeting->slug }}" class="hover:underline">{{ $meeting->slug }}</a>
                    @else
                        <span class="{{ $event->meeting_slug === 'uncategorized' ? 'text-yellow-600' : 'text-gray-600' }}">
                            {{ $event->meeting_slug }}
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