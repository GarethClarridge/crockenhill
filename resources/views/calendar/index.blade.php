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
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <h4 class="text-base font-medium text-gray-900 mb-1">
                    {{ $event->title }}
                  </h4>
                  
                  <div class="flex items-center space-x-4 text-sm text-gray-600 mb-2">
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
                    <p class="text-sm text-gray-700 mb-2">{{ Str::limit($event->description, 150) }}</p>
                  @endif
                  
                  @if($event->meeting)
                    <div class="flex items-center">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        <a href="/community/{{ $event->meeting->slug }}" class="hover:underline">
                          {{ $event->meeting->slug }}
                        </a>
                      </span>
                    </div>
                  @else
                    <div class="flex items-center">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                        {{ $event->meeting_slug }}
                      </span>
                    </div>
                  @endif
                </div>
              </div>
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