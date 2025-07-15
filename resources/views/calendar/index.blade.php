@extends('layouts/page')

@section('dynamic_content')

<x-h1>Church Calendar</x-h1>

<div class="prose max-w-none mb-8">
  <p>Here are all upcoming events from our church calendar. Events are automatically synchronized from our Google Calendar.</p>
</div>

@if($allEvents->count() > 0)
  <div class="space-y-6">
    @foreach($allEvents->groupBy(function($event) { return $event->start_datetime->format('Y-m-d'); }) as $date => $events)
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">
            {{ \Carbon\Carbon::parse($date)->format('l, F j, Y') }}
          </h3>
        </div>
        
        <div class="divide-y divide-gray-100">
          @foreach($events as $event)
            <div class="px-6 py-4 hover:bg-gray-50 transition-colors">
              <x-calendar-event-card 
                :event="$event" 
                variant="list"
                :show-date="false"
                date-format="l, F j, Y"
                description-limit="150"
              />
            </div>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
@else
  <div class="text-center py-12">
    <x-heroicon-o-calendar class="mx-auto h-12 w-12 text-gray-400" />
    <h3 class="mt-2 text-sm font-medium text-gray-900">No upcoming events</h3>
    <p class="mt-1 text-sm text-gray-500">Check back soon for new events.</p>
  </div>
@endif

@if($allEvents->count() >= 50)
  <div class="mt-8 text-center">
    <p class="text-sm text-gray-600">Showing next 50 events. More events may be available.</p>
  </div>
@endif

@stop