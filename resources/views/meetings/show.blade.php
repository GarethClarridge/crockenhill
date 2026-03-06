@extends('layouts/page')

@section('dynamic_content')

{{--
  Note: The heading, headingpicture, and page content ($content) are passed to the view
  and rendered by the layouts/page layout. We only render the meeting-specific details here.
--}}

{{-- Meeting Details --}}

<div class="bg-cbc-pattern bg-cover my-12 px-6 md:px-16 py-12 text-white text-3xl font-display">
  <dl>
    @if ($meeting->day != '')
    <div class="my-3 flex items-center md:leading-loose">
      <x-heroicon-s-calendar class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Day</dt>
      <dd>{{ $meeting->day }}</dd>
    </div>
    @endif
    @if ($meeting->start_time != '')
    <div class="my-3 flex items-center md:leading-loose">
      <x-heroicon-o-clock class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Time</dt>
      <dd>
        {{ date('g:ia', strtotime($meeting->start_time)) }}
        @if ($meeting->end_time != '')
        &ndash; {{ date('g:ia', strtotime($meeting->end_time)) }}
        @endif
      </dd>
    </div>
    @endif
    @if ($meeting->location != '')
    <div class="my-3 flex items-center leading-relaxed md:leading-loose">
      <x-heroicon-o-map-pin class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Location</dt>
      <dd>{{ $meeting->location }}</dd>
    </div>
    @endif
    @if ($meeting->who != '')
    <div class="my-3 flex items-center leading-relaxed md:leading-loose">
      <x-heroicon-o-user class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Who this is for</dt>
      <dd>{{ $meeting->who }}</dd>
    </div>
    @endif
    @if ($meeting->leaders_phone != '')
    <div class="my-3 flex items-center leading-relaxed md:leading-loose">
      <x-heroicon-o-phone class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Phone</dt>
      <dd>{{ $meeting->leaders_phone }}</dd>
    </div>
    @endif
    @if ($meeting->leaders_email != '')
    <div class="my-3 flex items-center leading-relaxed md:leading-loose">
      <x-heroicon-o-envelope class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
      <dt class="sr-only">Email</dt>
      <dd>{{ $meeting->leaders_email }}</dd>
    </div>
    @endif
  </dl>
</div>

@if ($photos->isNotEmpty())
<div class="flex flex-wrap">
  @foreach ($photos as $index => $photo)
  @php
    $altText = !empty($photo['name']) && !preg_match('/\.(jpe?g|png|webp|gif)$/i', $photo['name'])
      ? $photo['name']
      : $heading . ' photo ' . ($index + 1);
  @endphp
  <div class="md:w-1/2 pr-4 pl-4">
    <img src="{{ $photo['url'] }}" width="100%" alt="{{ $altText }}" loading="lazy">
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
      <x-heroicon-o-arrow-right class="ml-2 h-4 w-4" aria-hidden="true" />
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
