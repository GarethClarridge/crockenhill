@extends('pages.page')

@section('dynamic_content')

  @foreach ($songs as $song)
    <h3>{{$song->title}}</h3>
    @if ($song->praise_number)
      <p>
        <span class="glyphicon glyphicon-music"></span> &nbsp
        Praise Number: {{ $song->praise_number }}
      </p>
    @endif

    @if ($song->author)
      <p>
        <span class="glyphicon glyphicon-user"></span> &nbsp
        {{ $song->author }}
      </p>
    @endif

    @if ($song->copyright)
      <p>
        <span class="glyphicon glyphicon-copyright-mark"></span> &nbsp
        {{ $song->copyright }}
      </p>
    @endif

  @endforeach

@stop