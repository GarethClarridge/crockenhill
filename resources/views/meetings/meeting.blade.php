@extends('layouts/page')

@section('dynamic_content')

{{-- Details --}}

<div class="bg-cbc-pattern bg-cover my-12 px-6 md:px-16 py-12 text-white text-3xl font-display">
  <table>
    <tbody>
      @if ($day != '')
        <tr class="md:leading-loose">
          <th scope="row">
            <i class="mr-6 far fa-calendar"></i>
          </th>
          <td>
            {{$day}}
          </td>
        </tr>
      @endif
      @if ($starttime != '')
        <tr class="md:leading-loose">
          <th scope="row">
            <i class="mr-6 far fa-clock"></i>
          </th>
          <td>
            {{date('g:ia', strtotime($starttime))}}
            @if ($endtime != '')
              - {{date('g:ia', strtotime($endtime))}}
            @endif
          </td>
        </tr>
      @endif
      @if ($location != '')
        <tr class="leading-relaxed md:leading-loose">
          <th scope="row">
            <i class="mr-6 fas fa-map-marker-alt"></i>
          </th>
          <td>{{ $location }}</td>
        </tr>
      @endif
      @if ($who != '')
        <tr class="leading-relaxed md:leading-loose">
          <th scope="row">
            <i class="mr-6 far fa-user"></i>
          </th>
          <td>{{$who}}</td>
        </tr>
      @endif
      @if ($phone != '')
        <tr class="leading-relaxed md:leading-loose">
          <th scope="row">
            <i class="mr-6 fas fa-phone"></i>
          </th>
          <td>{{$phone}}</td>
        </tr>
      @endif
      @if ($email != '')
        <tr class="leading-relaxed md:leading-loose">
          <th scope="row">
            <i class="mr-6 far fa-envelope"></i>
          </th>
          <td>{{$email}}</td>
        </tr>
      @endif
    </tbody>
  </table>
</div>

@if ($photos != '')
  <div class="flex flex-wrap ">

    @foreach ($photos as $photo)

    <div class="md:w-1/2 pr-4 pl-4">
      <img src="/images/meetings/{{$slug}}/{{$photo}}" width="100%" alt="">
    </div>

    @endforeach

  </div>
@endif

{{-- Safeguarding --}}

  @if ($type === 'ChildrenAndYoungPeople'
        || $slug === 'sunday-mornings')
    <hr>
    <small class="prose">
      All activities at the church are carried out in accordance with our
      <a href="/church/safeguarding-policy">Safeguarding policy</a>
      and our
      <a href="/media/documents/BehaviourPolicy.pdf">Positive behaviour policy</a>.
    </small>
  @endif

@stop
