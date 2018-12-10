@extends('page')

@section('dynamic_content')

{{-- Details --}}

  <div class="meeting-details">
    <div class="row">
      <div class="col-md-12">
        <table>
          <tbody>
            @if ($day != '')
              <tr>
                <th scope="row">
                  <i class="far fa-calendar"></i>
                </th>
                <td>
                  {{$day}}
                </td>
              </tr>
            @endif
            @if ($starttime != '')
              <tr>
                <th scope="row">
                  <i class="far fa-clock"></i>
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
              <tr>
                <th scope="row">
                  <i class="fas fa-map-marker-alt"></i>
                </th>
                <td>{{ $location }}</td>
              </tr>
            @endif
            @if ($who != '')
              <tr>
                <th scope="row">
                  <i class="far fa-user"></i>
                </th>
                <td>{{$who}}</td>
              </tr>
            @endif
            @if ($phone != '')
              <tr>
                <th scope="row">
                  <i class="fas fa-phone"></i>
                </th>
                <td>{{$phone}}</td>
              </tr>
            @endif
            @if ($email != '')
              <tr>
                <th scope="row">
                  <i class="far fa-envelope"></i>
                </th>
                <td>{{$email}}</td>
              </tr>
            @endif
          </tbody>
        </table>
      </div>
    </div>
  </div>

  @if ($photos != '')
    <div class="row">

      @foreach ($photos as $photo)

      <div class="col-md-6">
        <img src="/images/meetings/{{$slug}}/{{$photo}}" width="100%" alt="">
      </div>

      @endforeach

    </div>
  @endif

{{-- Child Protection --}}

  @if ($type === 'ChildrenAndYoungPeople'
        || $slug === 'sunday-services')
    <hr>
    <small>
      All activities at the church are carried out in accordance with our
      <a href="/media/documents/ChildProtectionPolicy.pdf">Child Protection Policy</a>
      and our our
      <a href="/media/documents/BehaviourPolicy.pdf">Positive Behaviour Policy</a>.
    </small>
  @endif

@stop
