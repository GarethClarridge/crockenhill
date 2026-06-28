@props([
    'event',
    'showYear' => false
])

<div class="flex items-center justify-between py-2 px-3 bg-gray-50 rounded">
    <div>
        <span class="text-sm font-medium text-gray-900">{{ $event->title }}</span>
        @if($event->speaker)
            <span class="text-sm text-gray-600"> - {{ $event->speaker }}</span>
        @endif
    </div>
    <span class="text-sm text-gray-500">
        {{ $event->start_datetime->format($showYear ? 'j M Y' : 'j M') }}
    </span>
</div>