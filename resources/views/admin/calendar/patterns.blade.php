@extends('layouts/page')

@section('dynamic_content')

<div class="px-6 max-w-2xl mx-auto mt-6">
  <x-button link="{{ route('admin.calendar.uncategorized') }}">
    &larr; Back to Uncategorized Events
  </x-button>
</div>

<x-h2>Calendar Categorization Patterns</x-h2>

<div class="prose max-w-none mb-6">
  <p>These patterns are used to automatically categorize calendar events based on their titles. Patterns are defined in the <code>config/calendar.php</code> file.</p>
</div>

<div class="bg-white shadow overflow-hidden sm:rounded-md">
  <ul role="list" class="divide-y divide-gray-200">
    @foreach($patterns as $meetingSlug => $config)
      <li class="px-6 py-4">
        <div class="flex items-center justify-between">
          <div class="flex-1">
            <div class="flex items-center">
              <h3 class="text-lg font-medium text-gray-900 mr-3">
                {{ $meetingSlug }}
              </h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                {{ count($config['patterns']) }} pattern{{ count($config['patterns']) !== 1 ? 's' : '' }}
              </span>
            </div>
            
            <div class="mt-2">
              <p class="text-sm text-gray-600 mb-2">
                <strong>Matching patterns:</strong>
              </p>
              <div class="flex flex-wrap gap-2">
                @foreach($config['patterns'] as $pattern)
                  <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">
                    "{{ $pattern }}"
                  </span>
                @endforeach
              </div>
              
              @if($config['case_insensitive'])
                <p class="text-xs text-gray-500 mt-2">
                  <x-heroicon-s-information-circle class="h-3 w-3 inline mr-1" />
                  Case insensitive matching enabled
                </p>
              @endif
            </div>
          </div>
          
          <div class="flex-shrink-0">
            @php
              $meeting = $meetings->where('slug', $meetingSlug)->first();
            @endphp
            @if($meeting)
              <a href="/community/{{ $meeting->slug }}" class="text-blue-600 hover:text-blue-500">
                <span class="sr-only">View meeting</span>
                <x-heroicon-o-arrow-top-right-on-square class="h-5 w-5" />
              </a>
            @endif
          </div>
        </div>
      </li>
    @endforeach
  </ul>
</div>

<div class="mt-8 bg-blue-50 border border-blue-200 rounded-md p-4">
  <div class="flex">
    <x-heroicon-s-information-circle class="h-5 w-5 text-blue-400 mt-0.5 mr-3" />
    <div>
      <h3 class="text-sm font-medium text-blue-800">How Pattern Matching Works</h3>
      <div class="mt-2 text-sm text-blue-700">
        <ul class="list-disc pl-5 space-y-1">
          <li>Events are automatically categorized when their title contains any of the defined patterns</li>
          <li>Matching is performed during calendar sync from Google Calendar</li>
          <li>Events that don't match any pattern are marked as "uncategorized"</li>
          <li>Manual categorization via Extended Properties always takes precedence</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-md p-4">
  <div class="flex">
    <x-heroicon-s-exclamation-triangle class="h-5 w-5 text-yellow-400 mt-0.5 mr-3" />
    <div>
      <h3 class="text-sm font-medium text-yellow-800">Editing Patterns</h3>
      <p class="mt-2 text-sm text-yellow-700">
        To modify these patterns, edit the <code>config/calendar.php</code> file and add or remove patterns from the <code>meeting_patterns</code> array. Changes take effect on the next calendar sync.
      </p>
    </div>
  </div>
</div>

@stop