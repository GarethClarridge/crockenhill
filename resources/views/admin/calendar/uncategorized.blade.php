@extends('layouts/page')

@section('dynamic_content')

<div class="prose max-w-none mb-6">
  <p>These events couldn't be automatically categorized. Please assign them to the appropriate meeting type.</p>
</div>

@if(session('success'))
  <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
    {{ session('success') }}
  </div>
@endif

@if(session('error'))
  <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
    {{ session('error') }}
  </div>
@endif

@if($uncategorizedEvents->count() > 0)
  <div class="space-y-6">
    @foreach($uncategorizedEvents as $event)
      <x-calendar-event-card 
        :event="$event" 
        variant="admin"
        date-format="l, F j, Y"
        description-limit="200"
      >
        <div class="flex-shrink-0 ml-6">
          <form method="POST" action="{{ route('admin.calendar.categorize') }}" class="flex items-center space-x-2">
            @csrf
            <input type="hidden" name="event_id" value="{{ $event->id }}">
            
            <select name="meeting_slug" required class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
              <option value="">Select meeting type...</option>
              @foreach($meetings as $meeting)
                <option value="{{ $meeting->slug }}">{{ $meeting->slug }}</option>
              @endforeach
            </select>
            
            <x-form-button type="submit" variant="primary" size="sm">
              Categorize
            </x-form-button>
          </form>
        </div>
      </x-calendar-event-card>
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