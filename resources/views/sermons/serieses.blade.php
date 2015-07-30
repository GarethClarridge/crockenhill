@extends('page')

@section('dynamic_content')

<br>

@foreach ($series as $series)
  @if ($series->series != 'NULL')
    <h4>
      <a href="series/{!! \Illuminate\Support\Str::slug($series->series) !!}">
        {{$series->series}}
      </a>
    </h4>
  @endif
@endforeach

@stop