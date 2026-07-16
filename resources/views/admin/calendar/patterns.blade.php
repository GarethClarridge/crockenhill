@extends('layouts.main')

@section('content')
<x-admin.shell heading="Calendar patterns">

<x-admin.page
    title="Categorisation patterns"
    description="These patterns are used to automatically categorise calendar events based on their titles. Patterns are defined in the system configuration. Please contact the site administrator to make changes."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.calendar.uncategorized') }}" variant="outline" inline>
            &larr; Uncategorised events
        </x-button>
    </x-slot:actions>

    <x-card>
        <ul role="list" class="divide-y divide-gray-200 -mx-6 -mt-6">
            @foreach($patterns as $meetingSlug => $config)
                <li class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <div class="flex items-center">
                                <h3 class="font-display text-lg text-gray-900 mr-3">
                                    {{ $meetingSlug }}
                                </h3>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ count($config['patterns']) }} pattern{{ count($config['patterns']) !== 1 ? 's' : '' }}
                                </span>
                            </div>

                            <div class="mt-2">
                                <p class="text-sm text-gray-600 mb-2"><strong>Matching patterns:</strong></p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($config['patterns'] as $pattern)
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                            "{{ $pattern }}"
                                        </span>
                                    @endforeach
                                </div>

                                @if($config['case_insensitive'])
                                    <p class="text-xs text-gray-500 mt-2 flex items-center gap-1">
                                        <x-heroicon-s-information-circle class="h-3 w-3" />
                                        Case insensitive matching enabled
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="flex-shrink-0 ml-4">
                            @php
                                $meeting = $meetings->where('slug', $meetingSlug)->first();
                            @endphp
                            @if($meeting)
                                <a href="/community/{{ $meeting->slug }}" class="text-cbc-teal hover:text-cbc-teal-dark" aria-label="View {{ $meetingSlug }} meeting">
                                    <x-heroicon-o-arrow-top-right-on-square class="h-5 w-5" />
                                </a>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-card>

    <x-card>
        <div class="flex gap-3">
            <x-heroicon-s-information-circle class="h-5 w-5 text-blue-400 mt-0.5 shrink-0" />
            <div>
                <h3 class="text-sm font-medium text-gray-900">How Pattern Matching Works</h3>
                <ul class="mt-2 text-sm text-gray-600 list-disc pl-5 space-y-1">
                    <li>Events are automatically categorised when their title contains any of the defined patterns</li>
                    <li>Matching is performed during calendar sync from Google Calendar</li>
                    <li>Events that don't match any pattern are marked as "uncategorised"</li>
                    <li>Manual categorisation via Extended Properties always takes precedence</li>
                </ul>
            </div>
        </div>
    </x-card>

    <x-card>
        <div class="flex gap-3">
            <x-heroicon-s-exclamation-triangle class="h-5 w-5 text-amber-400 mt-0.5 shrink-0" />
            <div>
                <h3 class="text-sm font-medium text-gray-900">Editing Patterns</h3>
                <p class="mt-2 text-sm text-gray-600">
                    To modify these patterns, please contact the site administrator. Changes made to the system configuration take effect on the next calendar sync.
                </p>
            </div>
        </div>
    </x-card>

</x-admin.page>

</x-admin.shell>
@endsection
