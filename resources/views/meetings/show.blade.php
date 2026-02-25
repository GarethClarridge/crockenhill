@extends('layouts/page')

@section('dynamic_content')

{{--
  Note: The heading, headingpicture, and page content ($content) are passed to the view
  and rendered by the layouts/page layout. We only render the meeting-specific details here.
--}}

{{-- Meeting Details Table --}}

<div class="bg-cbc-pattern bg-cover my-12 px-6 md:px-16 py-12 text-white text-3xl font-display">
  <table>
    <tbody>
      @if ($meeting->day != '')
      <tr class="md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-s-calendar class="h-10 w-10 mr-2" />
        </th>
        <td>
          {{$meeting->day}}
        </td>
      </tr>
      @endif
      @if ($meeting->start_time != '')
      <tr class="md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-clock class="h-10 w-10 mr-2" />
        </th>
        <td>
          {{ $meeting->start_time ? date('g:ia', strtotime($meeting->start_time)) : '' }}
          @if ($meeting->end_time != '')
          - {{ $meeting->end_time ? date('g:ia', strtotime($meeting->end_time)) : '' }}
          @endif
        </td>
      </tr>
      @endif
      @if ($meeting->location != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-map-pin class="h-10 w-10 mr-2" />
        </th>
        <td>{{ $meeting->location }}</td>
      </tr>
      @endif
      @if ($meeting->who != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-user class="h-10 w-10 mr-2" />
        </th>
        <td>{{$meeting->who}}</td>
      </tr>
      @endif
      @if ($meeting->leaders_phone != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-phone class="h-10 w-10 mr-2" />
        </th>
        <td>{{$meeting->leaders_phone}}</td>
      </tr>
      @endif
      @if ($meeting->leaders_email != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-envelope class="h-10 w-10 mr-2" />
        </th>
        <td>{{$meeting->leaders_email}}</td>
      </tr>
      @endif
    </tbody>
  </table>
</div>

@if ($photos->isNotEmpty())
<div class="flex flex-wrap ">
  @foreach ($photos as $photo)
  <div class="md:w-1/2 pr-4 pl-4">
    <img src="{{ $photo['url'] }}" width="100%" alt="{{ $photo['name'] }}" loading="lazy">
  </div>
  @endforeach
</div>
@endif

{{-- Calendar Events --}}
@if(isset($upcomingEvents) && $upcomingEvents->count() > 0)
<hr class="my-8">
<x-h2>Upcoming Events</x-h2>

<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 mb-6">
  @foreach($upcomingEvents->take(6) as $event)
    <x-calendar-event-card
      :event="$event"
      :meeting="$meeting"
      :show-meeting-badge="false"
      description-limit="80"
      date-format="M j, Y"
    />
  @endforeach
</div>

@if($upcomingEvents->count() > 6)
  <div class="text-center mb-6">
    <a href="/meetings/{{ $meeting->slug }}/events" wire:navigate class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
      View all {{ $upcomingEvents->count() }} upcoming events
      <x-heroicon-o-arrow-right class="ml-2 h-4 w-4" />
    </a>
  </div>
@endif
@endif

{{-- Recent Past Events --}}
@if(isset($pastEvents) && $pastEvents->count() > 0)
<div class="mb-8">
  <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Events</h3>
  <div class="space-y-2">
    @foreach($pastEvents->take(3) as $event)
      <x-calendar-event-compact :event="$event" />
    @endforeach
  </div>

  @if($pastEvents->count() > 3)
    <div class="mt-3">
      <a href="/meetings/{{ $meeting->slug }}/events" wire:navigate class="text-sm text-blue-600 hover:text-blue-500">
        View all past events &rarr;
      </a>
    </div>
  @endif
</div>
@endif

{{-- Safeguarding --}}

@if ($meeting->type->value === 'ChildrenAndYoungPeople' || $meeting->slug === 'sunday-mornings')
<hr>
<small class="prose">
  All activities at the church are carried out in accordance with our
  <a href="/church/safeguarding-policy" wire:navigate>Safeguarding policy</a>
  and our
  <a href="/media/documents/BehaviourPolicy.pdf">Positive behaviour policy</a>.
</small>
@endif

@stop
