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
          <x-calendar-event-card 
            :event="$event" 
            :meeting="$meeting"
            variant="admin"
            :show-meeting-badge="false"
            date-format="l, F j, Y"
          />
        @endforeach
      </div>
    </div>
  @endif
  
  @if($pastEvents->count() > 0)
    <div>
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Past Events</h2>
      <div class="space-y-3">
        @foreach($pastEvents->take(20) as $event)
          <x-calendar-event-card 
            :event="$event" 
            :meeting="$meeting"
            variant="compact"
            :show-meeting-badge="false"
            date-format="M j, Y"
          />
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