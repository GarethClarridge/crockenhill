@extends('layouts/page')

@section('dynamic_content')

{{-- Details --}}

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
      @if ($meeting->StartTime != '')
      <tr class="md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-clock class="h-10 w-10 mr-2" />
        </th>
        <td>
          {{ $meeting->StartTime ? date('g:ia', strtotime($meeting->StartTime)) : '' }}
          @if ($meeting->EndTime != '')
          - {{ $meeting->EndTime ? date('g:ia', strtotime($meeting->EndTime)) : '' }}
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
      @if ($meeting->LeadersPhone != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-phone class="h-10 w-10 mr-2" />
        </th>
        <td>{{$meeting->LeadersPhone}}</td>
      </tr>
      @endif
      @if ($meeting->LeadersEmail != '')
      <tr class="leading-relaxed md:leading-loose">
        <th scope="row" class="my-3 flex items-center">
          <x-heroicon-o-envelope class="h-10 w-10 mr-2" />
        </th>
        <td>{{$meeting->LeadersEmail}}</td>
      </tr>
      @endif
    </tbody>
  </table>
</div>

@if (!empty($photos))
<div class="flex flex-wrap ">
  @foreach ($photos as $photo)
  <div class="md:w-1/2 pr-4 pl-4">
    <img src="/images/meetings/{{$meeting->slug}}/{{$photo}}" width="100%" alt="">
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
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
      <h4 class="font-medium text-gray-900 mb-2">{{ $event->title }}</h4>
      
      <div class="space-y-1 text-sm text-gray-600">
        <div class="flex items-center">
          <x-heroicon-o-calendar class="h-4 w-4 mr-2" />
          {{ $event->start_datetime->format('M j, Y') }}
        </div>
        
        <div class="flex items-center">
          <x-heroicon-o-clock class="h-4 w-4 mr-2" />
          {{ $event->start_datetime->format('g:i A') }}
          @if($event->end_datetime)
            - {{ $event->end_datetime->format('g:i A') }}
          @endif
        </div>
        
        @if($event->speaker)
          <div class="flex items-center">
            <x-heroicon-o-user class="h-4 w-4 mr-2" />
            {{ $event->speaker }}
          </div>
        @endif
        
        @if($event->location && $event->location !== $meeting->location)
          <div class="flex items-center">
            <x-heroicon-o-map-pin class="h-4 w-4 mr-2" />
            {{ $event->location }}
          </div>
        @endif
      </div>
      
      @if($event->description)
        <p class="text-xs text-gray-600 mt-2">{{ Str::limit($event->description, 80) }}</p>
      @endif
    </div>
  @endforeach
</div>

@if($upcomingEvents->count() > 6)
  <div class="text-center mb-6">
    <a href="/meetings/{{ $meeting->slug }}/events" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
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
      <div class="flex items-center justify-between py-2 px-3 bg-gray-50 rounded">
        <div>
          <span class="text-sm font-medium text-gray-900">{{ $event->title }}</span>
          @if($event->speaker)
            <span class="text-sm text-gray-600">- {{ $event->speaker }}</span>
          @endif
        </div>
        <span class="text-sm text-gray-500">{{ $event->start_datetime->format('M j') }}</span>
      </div>
    @endforeach
  </div>
  
  @if($pastEvents->count() > 3)
    <div class="mt-3">
      <a href="/meetings/{{ $meeting->slug }}/events" class="text-sm text-blue-600 hover:text-blue-500">
        View all past events &rarr;
      </a>
    </div>
  @endif
</div>
@endif

{{-- Safeguarding --}}

@if ($meeting->type === 'ChildrenAndYoungPeople' || $meeting->slug === 'sunday-mornings')
<hr>
<small class="prose">
  All activities at the church are carried out in accordance with our
  <a href="/church/safeguarding-policy">Safeguarding policy</a>
  and our
  <a href="/media/documents/BehaviourPolicy.pdf">Positive behaviour policy</a>.
</small>
@endif

@stop