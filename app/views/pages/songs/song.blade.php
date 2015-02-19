@extends('pages.page')

@section('dynamic_content')

@if ($song->praise_number)
  <p>
    Praise number: {{ $song->praise_number }}
  </p>
@endif

@if ($song->author)
  <p>
    <span class="glyphicon glyphicon-user"></span> &nbsp
    {{ $song->author }}
  </p>
@endif

@if ($song->lyrics)
  {{ $song->lyrics }}
@endif

@if ($song->copyright)
  <p>
    <span class="glyphicon glyphicon-copyright-mark"></span> &nbsp
    {{ $song->copyright }}
  </p>
@endif

@stop
