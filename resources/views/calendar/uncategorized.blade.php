@extends('layouts/page')

@section('dynamic_content')

<x-h1>Uncategorized Events</x-h1>

<div class="prose max-w-none mb-8">
  <p>These events from our calendar haven't been automatically categorized into a specific meeting type. They may be special events or one-off occasions.</p>
</div>

@if($uncategorizedEvents->count() > 0)
  <div class="space-y-4">
    @foreach($uncategorizedEvents as $event)
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <h3 class="text-lg font-medium text-gray-900 mb-2">
              {{ $event->title }}
            </h3>
            
            <div class="flex items-center space-x-4 text-sm text-gray-600 mb-3">
              <div class="flex items-center">
                <x-heroicon-o-calendar class="h-4 w-4 mr-1" />
                {{ $event->start_datetime->format('l, F j, Y') }}
              </div>
              
              <div class="flex items-center">
                <x-heroicon-o-clock class="h-4 w-4 mr-1" />
                {{ $event->start_datetime->format('g:i A') }}
                @if($event->end_datetime)
                  - {{ $event->end_datetime->format('g:i A') }}
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
            
            @if($event->description)
              <p class="text-sm text-gray-700 mb-3">{{ $event->description }}</p>
            @endif
            
            <div class="flex items-center">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                <x-heroicon-s-question-mark-circle class="h-3 w-3 mr-1" />
                Uncategorized
              </span>
            </div>
          </div>
        </div>
      </div>
    @endforeach
  </div>
@else
  <div class="text-center py-12">
    <x-heroicon-o-check-circle class="mx-auto h-12 w-12 text-green-400" />
    <h3 class="mt-2 text-sm font-medium text-gray-900">All events are categorized</h3>
    <p class="mt-1 text-sm text-gray-500">Great! All calendar events have been properly categorized.</p>
  </div>
@endif

@stop