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