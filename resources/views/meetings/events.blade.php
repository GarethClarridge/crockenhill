@extends('layouts/page')

@section('dynamic_content')

<x-h1>{{ $meeting->slug }} - All Events</x-h1>

<div class="prose max-w-none mb-8">
  <p>All events for <strong>{{ $meeting->slug }}</strong> from our calendar.</p>
  <p><a href="/community/{{ $meeting->slug }}" class="text-blue-600 hover:underline">&larr; Back to {{ $meeting->slug }}</a></p>
</div>

@if($events->count() > 0)
  @php
    $upcomingEvents = $events->where('start_datetime', '>=', now());
    $pastEvents = $events->where('start_datetime', '<', now())->sortByDesc('start_datetime');
  @endphp
  
  @if($upcomingEvents->count() > 0)
    <div class="mb-8">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Upcoming Events</h2>
      <div class="space-y-4">
        @foreach($upcomingEvents as $event)
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
                  <p class="text-sm text-gray-700">{{ $event->description }}</p>
                @endif
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif
  
  @if($pastEvents->count() > 0)
    <div>
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Past Events</h2>
      <div class="space-y-3">
        @foreach($pastEvents->take(20) as $event)
          <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <h4 class="text-base font-medium text-gray-700 mb-1">
                  {{ $event->title }}
                </h4>
                
                <div class="flex items-center space-x-3 text-sm text-gray-600">
                  <div class="flex items-center">
                    <x-heroicon-o-calendar class="h-3 w-3 mr-1" />
                    {{ $event->start_datetime->format('M j, Y') }}
                  </div>
                  
                  @if($event->speaker)
                    <div class="flex items-center">
                      <x-heroicon-o-user class="h-3 w-3 mr-1" />
                      {{ $event->speaker }}
                    </div>
                  @endif
                </div>
              </div>
            </div>
          </div>
        @endforeach
        
        @if($pastEvents->count() > 20)
          <p class="text-sm text-gray-500 text-center">Showing 20 most recent past events</p>
        @endif
      </div>
    </div>
  @endif
  
@else
  <div class="text-center py-12">
    <x-heroicon-o-calendar class="mx-auto h-12 w-12 text-gray-400" />
    <h3 class="mt-2 text-sm font-medium text-gray-900">No events found</h3>
    <p class="mt-1 text-sm text-gray-500">No events have been scheduled for this meeting yet.</p>
  </div>
@endif

@stop