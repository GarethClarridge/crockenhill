@extends('page')

@section('dynamic_content')

<br>
<ul class="list-group list-group-flush">
  @foreach ($series as $series)
    <li class="list-group-item">
      <a href="series/{!! \Illuminate\Support\Str::slug($series->series) !!}">
        {{$series->series}}
      </a>
    </li>
  @endforeach
</ul>

@stop
